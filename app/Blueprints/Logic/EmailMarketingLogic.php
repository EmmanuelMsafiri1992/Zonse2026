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
 * Email marketing: a campaign goes to an active mailing list with subscribers on it, and its body must
 * carry an {unsubscribe} link before it can be scheduled or sent. Sending counts the recipients, and
 * scheduled campaigns go out on their send date each morning. Opens can't exceed the recipients and
 * clicks can't exceed the opens. An automation's steps read like "Day 0: Welcome", in day order.
 */
class EmailMarketingLogic extends AppLogic
{
    /**
     * The placeholder every outgoing email must carry so people can opt out.
     */
    public const UNSUBSCRIBE = '{unsubscribe}';

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return match ($entity->key) {
            'lists' => $this->validateList($payload, $existing),
            'campaigns' => $this->validateCampaign($payload, $existing),
            default => $this->validateSequence($payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateList(array $payload, ?Record $existing): array
    {
        $errors = [];
        $name = mb_strtolower(trim((string) $payload['title']));
        $taken = $this->records('lists')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->contains(fn (Record $list) => mb_strtolower(trim((string) $list->title)) === $name);
        if ($taken) {
            $errors['title'] = 'There is already a list called '.trim((string) $payload['title']).'.';
        }
        if ((float) ($payload['data']['subscribers'] ?? 0) < 0) {
            $errors['data.subscribers'] = 'Subscribers cannot be negative.';
        }
        if ($existing && $payload['status'] === 'archived' && $existing->status !== 'archived') {
            $scheduled = $this->linked('campaigns', 'list', $existing)->where('status', 'scheduled')->count();
            if ($scheduled > 0) {
                $errors['status'] = $scheduled.' scheduled '.str('campaign')->plural($scheduled).' still '.($scheduled === 1 ? 'uses' : 'use').' this list.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateCampaign(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($existing?->status === 'sent' && $status !== 'sent') {
            return ['status' => 'This campaign has already been sent.'];
        }
        if ($status === 'sent' && $existing?->status !== 'sent') {
            return ['status' => 'Use "Send now" so the recipients are counted.'];
        }
        if ($status === 'scheduled') {
            $list = filled($data['list'] ?? null) ? $this->records('lists')->find($data['list']) : null;
            if ($problem = $this->cannotSend($list, (string) ($data['body'] ?? ''))) {
                $errors[$problem[0]] = $problem[1];
            }
            if (blank($payload['occurs_on'] ?? null)) {
                $errors['occurs_on'] = 'Choose when to send it.';
            } elseif (Carbon::parse($payload['occurs_on'])->lt(today())) {
                $errors['occurs_on'] = 'The send date has passed.';
            }
        }
        $opens = (float) ($data['opens'] ?? 0);
        $clicks = (float) ($data['clicks'] ?? 0);
        if ($clicks > $opens) {
            $errors['data.clicks'] = 'Clicks cannot be more than opens.';
        }
        if ($existing && $existing->value('_recipients') !== null && $opens > (float) $existing->value('_recipients')) {
            $errors['data.opens'] = 'It only went to '.number_format((float) $existing->value('_recipients')).' people.';
        }

        return $errors;
    }

    /**
     * Why a campaign can't go out to this list with this body, as [field, message], or null when it can.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function cannotSend(?Record $list, string $body): ?array
    {
        if (! $list) {
            return ['data.list', 'Choose a mailing list.'];
        }
        if ($list->status !== 'active') {
            return ['data.list', $list->title.' is archived.'];
        }
        if ($this->number($list, 'subscribers') <= 0) {
            return ['data.list', $list->title.' has no subscribers.'];
        }
        if (! str_contains($body, self::UNSUBSCRIBE)) {
            return ['data.body', 'Add '.self::UNSUBSCRIBE.' so people can opt out.'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateSequence(array $payload): array
    {
        $steps = $this->steps((string) ($payload['data']['steps'] ?? ''));
        if (is_string($steps)) {
            return ['data.steps' => $steps];
        }
        if ($payload['status'] === 'active' && $steps === []) {
            return ['data.steps' => 'Add at least one email, like "Day 0: Welcome".'];
        }

        return [];
    }

    /**
     * An automation's emails as [day, subject] pairs, or the problem with them.
     *
     * @return list<array{0: int, 1: string}>|string
     */
    public function steps(string $text): array|string
    {
        $steps = [];
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), fn (string $line) => $line !== ''));
        foreach ($lines as $index => $line) {
            if (! preg_match('/^day\s+(\d+)\s*[:\-–]\s*(.+)$/iu', $line, $match)) {
                return 'Line '.($index + 1).' should read like "Day 3: Tips to get started".';
            }
            $day = (int) $match[1];
            if ($steps && $day < end($steps)[0]) {
                return 'Line '.($index + 1).' goes back to day '.$day.'; keep the emails in day order.';
            }
            $steps[] = [$day, trim($match[2])];
        }

        return $steps;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'sequences') {
            $steps = $this->steps((string) $record->value('steps'));
            $steps = is_array($steps) ? $steps : [];
            $this->put($record, ['_emails' => count($steps), '_span_days' => $steps ? end($steps)[0] : 0]);
        }
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
            'sent' => ['results' => ['label' => 'Record results', 'icon' => 'mouse-pointer-click', 'fields' => [
                ['name' => 'opens', 'label' => 'Opens', 'type' => 'number', 'value' => $record->value('opens') ?? 0],
                ['name' => 'clicks', 'label' => 'Clicks', 'type' => 'number', 'value' => $record->value('clicks') ?? 0],
            ]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'send':
                $list = $this->parent($record, 'list');
                if ($problem = $this->cannotSend($list, (string) $record->value('body'))) {
                    throw ValidationException::withMessages([$problem[0] => $problem[1]]);
                }
                $this->send($record, $list);

                return $record->title.' sent to '.number_format((float) $record->value('_recipients')).' subscribers on '.$list->title.'.';
            case 'schedule':
                $date = $request->validate(['send_at' => ['required', 'date', 'after_or_equal:today']])['send_at'];
                if ($problem = $this->cannotSend($this->parent($record, 'list'), (string) $record->value('body'))) {
                    throw ValidationException::withMessages([$problem[0] => $problem[1]]);
                }
                $record->update(['status' => 'scheduled', 'occurs_on' => $date]);

                return $record->title.' scheduled for '.Carbon::parse($date)->format('d M Y').'.';
            case 'unschedule':
                $record->update(['status' => 'draft']);

                return $record->title.' is back in draft.';
            default:
                $recipients = (int) $record->value('_recipients');
                $values = $request->validate([
                    'opens' => ['required', 'integer', 'min:0', 'max:'.$recipients],
                    'clicks' => ['required', 'integer', 'min:0', 'lte:opens'],
                ]);
                $record->update(['data' => [...$record->data, 'opens' => (int) $values['opens'], 'clicks' => (int) $values['clicks']]]);

                return $record->title.': '.$this->rate($values['opens'], $recipients).' opened, '.$this->rate($values['clicks'], $recipients).' clicked.';
        }
    }

    /**
     * Mark a campaign sent to everyone on its list today.
     */
    protected function send(Record $campaign, Record $list): void
    {
        $campaign->update(['status' => 'sent', 'occurs_on' => today(), 'data' => [...$campaign->data, '_recipients' => (int) $this->number($list, 'subscribers'), '_sent_at' => now()->toDateTimeString()]]);
    }

    public function daily(Workspace $workspace): int
    {
        $sent = 0;
        foreach ($this->records('campaigns')->where('status', 'scheduled')->whereDate('occurs_on', '<=', today()->toDateString())->get() as $campaign) {
            $list = $this->parent($campaign, 'list');
            if (! $this->cannotSend($list, (string) $campaign->value('body'))) {
                $this->send($campaign, $list);
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
        if ($record->entity !== 'lists') {
            return [];
        }
        $campaigns = $this->linked('campaigns', 'list', $record)->where('status', 'sent')->latest('occurs_on')->latest('id')->limit(10)->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Campaigns sent', 'icon' => 'mail', 'empty' => 'Nothing sent to this list yet.',
            'rows' => $campaigns->map(fn (Record $campaign) => [
                'label' => $campaign->title, 'sub' => $campaign->occurs_on?->format('d M Y').' · '.number_format((float) $campaign->value('_recipients')).' recipients',
                'value' => $this->rate($this->number($campaign, 'opens'), (float) $campaign->value('_recipients')).' opened', 'href' => $campaign->url(),
            ])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $lists = $this->records('lists')->where('status', 'active')->get();
        $sent = $this->records('campaigns')->where('status', 'sent')->whereDate('occurs_on', '>=', today()->subDays(90)->toDateString())->get();
        $scheduled = $this->records('campaigns')->where('status', 'scheduled')->orderBy('occurs_on')->get();
        $recipients = $sent->sum(fn (Record $campaign) => (float) $campaign->value('_recipients'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Last 90 days', 'icon' => 'mail', 'stats' => [
                ['label' => 'Subscribers', 'value' => number_format($lists->sum(fn (Record $list) => $this->number($list, 'subscribers')))],
                ['label' => 'Campaigns sent', 'value' => $sent->count()],
                ['label' => 'Open rate', 'value' => $this->rate($sent->sum(fn (Record $campaign) => $this->number($campaign, 'opens')), $recipients)],
                ['label' => 'Click rate', 'value' => $this->rate($sent->sum(fn (Record $campaign) => $this->number($campaign, 'clicks')), $recipients)],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Scheduled', 'icon' => 'calendar-clock', 'empty' => 'Nothing scheduled.',
                'rows' => $scheduled->map(fn (Record $campaign) => ['label' => $campaign->title, 'value' => $campaign->occurs_on?->format('d M'), 'href' => $campaign->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sent = $this->dated('campaigns', $from, $to)->where('status', 'sent')->orderBy('occurs_on')->get();
        $lists = $this->records('lists')->pluck('title', 'id');

        $results = $sent->map(fn (Record $campaign) => [
            $campaign->title, $lists[$campaign->value('list')] ?? '—', $campaign->occurs_on?->format('d M Y') ?? '',
            number_format((float) $campaign->value('_recipients')),
            $this->rate($this->number($campaign, 'opens'), (float) $campaign->value('_recipients')),
            $this->rate($this->number($campaign, 'clicks'), (float) $campaign->value('_recipients')),
        ])->values()->all();

        $byList = $sent->groupBy(fn (Record $campaign) => $lists[$campaign->value('list')] ?? '—')->sortKeys()
            ->map(function (Collection $group, string $list) {
                $recipients = $group->sum(fn (Record $campaign) => (float) $campaign->value('_recipients'));

                return [$list, $group->count(), number_format($recipients), $this->rate($group->sum(fn (Record $campaign) => $this->number($campaign, 'opens')), $recipients), $this->rate($group->sum(fn (Record $campaign) => $this->number($campaign, 'clicks')), $recipients)];
            })->values()->all();

        return [
            ['title' => 'Campaign results', 'columns' => ['Campaign', 'List', 'Sent', 'Recipients', 'Open rate', 'Click rate'], 'rows' => $results],
            ['title' => 'Results by list', 'columns' => ['List', 'Campaigns', 'Recipients', 'Open rate', 'Click rate'], 'rows' => $byList],
        ];
    }
}
