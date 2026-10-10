<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * SMS & WhatsApp campaigns: an SMS template is counted in message parts the way networks bill them:
 * 160 characters in one part or 153 per part in the GSM alphabet, and 70 or 67 once it has an emoji
 * or other character outside it. WhatsApp templates number their variables {{1}}, {{2}} and so on
 * with no gaps, and editing an approved one sends it back for approval. A campaign needs recipients
 * and an approved template on its own channel (WhatsApp always needs one), goes out now or on its
 * send date, and its delivery results can't exceed the numbers it went to.
 */
class SmsMarketingLogic extends AppLogic
{
    /**
     * The GSM 03.38 basic alphabet; each counts as one character.
     */
    protected const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /**
     * GSM extension characters; each takes two characters' room.
     */
    protected const GSM_EXTENDED = "^{}\\[~]|€\f";

    /**
     * The longest WhatsApp template body allowed.
     */
    protected const WHATSAPP_LIMIT = 1024;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return $entity->key === 'templates' ? $this->validateTemplate($payload) : $this->validateCampaign($payload, $existing);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateTemplate(array $payload): array
    {
        $body = (string) ($payload['data']['body'] ?? '');
        if (($payload['data']['channel'] ?? null) !== 'whatsapp') {
            return [];
        }
        if (mb_strlen($body) > self::WHATSAPP_LIMIT) {
            return ['data.body' => 'WhatsApp templates can be at most '.number_format(self::WHATSAPP_LIMIT).' characters.'];
        }
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);
        $numbers = array_values(array_unique(array_map('intval', $matches[1])));
        sort($numbers);
        if ($numbers && $numbers !== range(1, count($numbers))) {
            return ['data.body' => 'Number the variables {{1}}, {{2}} and so on with no gaps.'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateCampaign(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $order = ['draft', 'scheduled', 'sending', 'sent'];
        $errors = [];

        if ($existing?->status === 'sent' && $status !== 'sent') {
            return ['status' => 'This campaign has been sent.'];
        }
        if ($status === 'sent' && $existing?->status !== 'sent') {
            return ['status' => 'Use "Record delivery" once the results are in.'];
        }
        if ($existing?->status === 'sending' && array_search($status, $order, true) < 2) {
            return ['status' => 'This campaign is already going out.'];
        }
        if (in_array($status, ['scheduled', 'sending'], true)) {
            $template = filled($data['template'] ?? null) ? $this->records('templates')->find($data['template']) : null;
            if ($problem = $this->cannotSend((string) ($data['channel'] ?? ''), $template, (float) ($data['recipients'] ?? 0))) {
                $errors[$problem[0]] = $problem[1];
            }
            if ($status === 'scheduled' && blank($payload['occurs_on'] ?? null)) {
                $errors['occurs_on'] = 'Choose when to send it.';
            }
        }
        if ((float) ($data['delivered'] ?? 0) > (float) ($data['recipients'] ?? 0)) {
            $errors['data.delivered'] = 'Delivered cannot be more than the recipients.';
        }

        return $errors;
    }

    /**
     * Why a campaign can't go out, as [field, message], or null when it can.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function cannotSend(string $channel, ?Record $template, float $recipients): ?array
    {
        if ($recipients <= 0) {
            return ['data.recipients', 'Say how many numbers it is going to.'];
        }
        if (! $template) {
            return $channel === 'whatsapp' ? ['data.template', 'WhatsApp campaigns must use an approved template.'] : null;
        }
        if ($template->value('channel') !== $channel) {
            return ['data.template', $template->title.' is a '.($template->value('channel') === 'whatsapp' ? 'WhatsApp' : 'SMS').' template.'];
        }
        if ($template->status !== 'approved') {
            return ['data.template', $template->title.' is not approved.'];
        }

        return null;
    }

    /**
     * How an SMS body is billed: its encoding, length and number of parts.
     *
     * @return array{encoding: string, characters: int, parts: int}
     */
    public function segments(string $body): array
    {
        $gsm = true;
        $length = 0;
        foreach (mb_str_split($body) as $character) {
            if (str_contains(self::GSM, $character)) {
                $length++;
            } elseif (str_contains(self::GSM_EXTENDED, $character)) {
                $length += 2;
            } else {
                $gsm = false;
                break;
            }
        }
        if (! $gsm) {
            $length = intdiv(strlen((string) mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')), 2);
        }
        [$single, $multi] = $gsm ? [160, 153] : [70, 67];

        return ['encoding' => $gsm ? 'GSM-7' : 'Unicode', 'characters' => $length, 'parts' => $length <= $single ? 1 : (int) ceil($length / $multi)];
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'templates') {
            return;
        }
        if ($record->value('channel') === 'whatsapp' && $record->exists && $record->getOriginal('status') === 'approved' && $record->status === 'approved'
            && (string) (((array) $record->getOriginal('data'))['body'] ?? '') !== (string) $record->value('body')) {
            $record->status = 'draft';
        }
        $count = $this->segments((string) $record->value('body'));
        $this->put($record, ['_encoding' => $count['encoding'], '_characters' => $count['characters'], '_parts' => $record->value('channel') === 'whatsapp' ? 1 : $count['parts']]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'campaigns') {
            return [];
        }

        return match ($record->status) {
            'draft' => [
                'send' => ['label' => 'Send now', 'icon' => 'send'],
                'schedule' => ['label' => 'Schedule', 'icon' => 'calendar-clock', 'fields' => [['name' => 'send_at', 'label' => 'Send on', 'type' => 'date', 'value' => today()->addDay()->toDateString()]]],
            ],
            'scheduled' => ['send' => ['label' => 'Send now', 'icon' => 'send'], 'unschedule' => ['label' => 'Back to draft', 'icon' => 'undo-2']],
            'sending' => ['results' => ['label' => 'Record delivery', 'icon' => 'check-check', 'fields' => [
                ['name' => 'delivered', 'label' => 'Delivered', 'type' => 'number', 'value' => $record->value('recipients')],
                ['name' => 'cost', 'label' => 'Cost', 'type' => 'number', 'value' => $record->value('cost')],
            ]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'send':
                $this->ensureSendable($record);
                $this->send($record);
                $parts = (int) $record->value('_parts');

                return $record->value('channel') === 'whatsapp'
                    ? $record->title.' sending to '.number_format($this->number($record, 'recipients')).' numbers on WhatsApp.'
                    : $record->title.' sending to '.number_format($this->number($record, 'recipients')).' numbers: '.number_format((float) $record->value('_messages')).' SMS ('.$parts.' '.str('part')->plural($parts).' each).';
            case 'schedule':
                $date = $request->validate(['send_at' => ['required', 'date', 'after_or_equal:today']])['send_at'];
                $this->ensureSendable($record);
                $record->update(['status' => 'scheduled', 'occurs_on' => $date]);

                return $record->title.' scheduled for '.Carbon::parse($date)->format('d M Y').'.';
            case 'unschedule':
                $record->update(['status' => 'draft']);

                return $record->title.' is back in draft.';
            default:
                $values = $request->validate([
                    'delivered' => ['required', 'integer', 'min:0', 'max:'.(int) $this->number($record, 'recipients')],
                    'cost' => ['nullable', 'numeric', 'min:0'],
                ]);
                $cost = (float) ($values['cost'] ?? 0);
                $record->update(['status' => 'sent', 'data' => [...$record->data, 'delivered' => (int) $values['delivered'], 'cost' => $cost]]);

                return $record->title.': '.$this->rate($values['delivered'], $this->number($record, 'recipients')).' delivered'
                    .($cost > 0 && $values['delivered'] > 0 ? ', '.$this->money($cost / $values['delivered']).' per delivered message.' : '.');
        }
    }

    /**
     * Refuse to send a campaign that isn't ready.
     */
    protected function ensureSendable(Record $campaign): void
    {
        if ($problem = $this->cannotSend((string) $campaign->value('channel'), $this->parent($campaign, 'template'), $this->number($campaign, 'recipients'))) {
            throw ValidationException::withMessages([$problem[0] => $problem[1]]);
        }
    }

    /**
     * Start a campaign going out, counting the messages it takes.
     */
    protected function send(Record $campaign): void
    {
        $template = $this->parent($campaign, 'template');
        $parts = $template ? max(1, (int) $template->value('_parts')) : 1;
        $campaign->update(['status' => 'sending', 'occurs_on' => today(), 'data' => [...$campaign->data, '_parts' => $parts, '_messages' => (int) $this->number($campaign, 'recipients') * $parts, '_sent_at' => now()->toDateTimeString()]]);
    }

    public function daily(Workspace $workspace): int
    {
        $sent = 0;
        foreach ($this->records('campaigns')->where('status', 'scheduled')->whereDate('occurs_on', '<=', today()->toDateString())->get() as $campaign) {
            if (! $this->cannotSend((string) $campaign->value('channel'), $this->parent($campaign, 'template'), $this->number($campaign, 'recipients'))) {
                $this->send($campaign);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * A share as a percentage, or a dash when there is nothing to divide by.
     */
    protected function rate(float|int $part, float|int $whole): string
    {
        return $whole > 0 ? number_format($part / $whole * 100, 1).'%' : '—';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'templates') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Message length', 'icon' => 'ruler', 'stats' => $record->value('channel') === 'whatsapp'
            ? [['label' => 'Characters', 'value' => mb_strlen((string) $record->value('body')).' / '.number_format(self::WHATSAPP_LIMIT)]]
            : [
                ['label' => 'Encoding', 'value' => $record->value('_encoding')],
                ['label' => 'Characters', 'value' => $record->value('_characters')],
                ['label' => 'Parts per message', 'value' => $record->value('_parts'), 'tone' => (int) $record->value('_parts') > 1 ? 'warning' : null],
            ]]]];
    }

    public function homeCards(): array
    {
        $month = $this->records('campaigns')->where('status', 'sent')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->get();
        $queued = $this->records('campaigns')->whereIn('status', ['scheduled', 'sending'])->orderBy('occurs_on')->get();
        $delivered = $month->sum(fn (Record $campaign) => $this->number($campaign, 'delivered'));
        $cost = $month->sum(fn (Record $campaign) => $this->number($campaign, 'cost'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This month', 'icon' => 'send', 'stats' => [
                ['label' => 'Campaigns sent', 'value' => $month->count()],
                ['label' => 'Delivered', 'value' => number_format($delivered)],
                ['label' => 'Delivery rate', 'value' => $this->rate($delivered, $month->sum(fn (Record $campaign) => $this->number($campaign, 'recipients')))],
                ['label' => 'Spent', 'value' => $this->money($cost)],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Going out', 'icon' => 'calendar-clock', 'empty' => 'Nothing queued.',
                'rows' => $queued->map(fn (Record $campaign) => ['label' => $campaign->title, 'sub' => ucfirst($campaign->status), 'value' => $campaign->occurs_on?->format('d M'), 'href' => $campaign->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sent = $this->dated('campaigns', $from, $to)->where('status', 'sent')->orderBy('occurs_on')->get();
        $row = function (Collection $group, string $label) {
            $delivered = $group->sum(fn (Record $campaign) => $this->number($campaign, 'delivered'));
            $cost = $group->sum(fn (Record $campaign) => $this->number($campaign, 'cost'));

            return [$label, $group->count(), number_format($group->sum(fn (Record $campaign) => $this->number($campaign, 'recipients'))), number_format($delivered),
                $this->rate($delivered, $group->sum(fn (Record $campaign) => $this->number($campaign, 'recipients'))), $this->money($cost), $delivered > 0 ? $this->money($cost / $delivered) : '—'];
        };

        return [
            ['title' => 'Delivery by channel', 'columns' => ['Channel', 'Campaigns', 'Recipients', 'Delivered', 'Delivery rate', 'Cost', 'Cost per delivered'],
                'rows' => $sent->groupBy(fn (Record $campaign) => $campaign->value('channel') === 'whatsapp' ? 'WhatsApp' : 'SMS')->sortKeys()->map($row)->values()->all()],
            ['title' => 'Campaigns', 'columns' => ['Campaign', 'Recipients', 'Delivered', 'Delivery rate', 'Cost', 'Cost per delivered'],
                'rows' => $sent->map(fn (Record $campaign) => array_values(array_diff_key($row(collect([$campaign]), $campaign->title), [1 => true])))->values()->all()],
        ];
    }
}
