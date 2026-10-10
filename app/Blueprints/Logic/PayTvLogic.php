<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Cable / satellite TV subscriptions: a decoder number is 8 to 14 digits and belongs to one subscriber. An active
 * subscriber runs to a renewal date a month after activation, and renewing adds months at the monthly price.
 * Subscribers past their renewal date are suspended each morning and disconnected after 60 days unpaid. An
 * installation needs at least 60% signal to count as installed, and installing activates the subscriber.
 */
class PayTvLogic extends AppLogic
{
    /**
     * The weakest signal an installation can be signed off with.
     */
    public const MIN_SIGNAL = 60;

    /**
     * Days unpaid after the renewal date before a subscriber is disconnected.
     */
    public const DISCONNECT_AFTER_DAYS = 60;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'installations') {
            if ($payload['status'] === 'installed' && (blank($data['signal_strength'] ?? null) || (float) $data['signal_strength'] < self::MIN_SIGNAL)) {
                $errors['data.signal_strength'] = 'An installation needs at least '.self::MIN_SIGNAL.'% signal; realign the dish or mark it failed.';
            }

            return $errors;
        }
        $decoder = $this->decoder((string) ($data['decoder_number'] ?? ''));
        if ($decoder === '') {
            return $errors;
        }
        if (! preg_match('/^\d{8,14}$/', $decoder)) {
            $errors['data.decoder_number'] = 'A decoder or smartcard number is 8 to 14 digits.';
        } elseif ($other = $this->records('subscribers')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $subscriber) => $this->decoder((string) $subscriber->value('decoder_number')) === $decoder)) {
            $errors['data.decoder_number'] = 'This decoder belongs to '.$other->title.'.';
        }

        return $errors;
    }

    /**
     * A decoder number without spaces or dashes.
     */
    protected function decoder(string $number): string
    {
        return preg_replace('/[\s-]+/', '', trim($number));
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'installations') {
            $record->occurs_on ??= today();

            return;
        }
        if (filled($record->value('decoder_number'))) {
            $this->put($record, ['decoder_number' => $this->decoder((string) $record->value('decoder_number'))]);
        }
        if ($record->status === 'active') {
            $record->occurs_on ??= today();
            $record->due_on ??= $record->occurs_on->copy()->addMonthNoOverflow();
        }
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = $this->records('subscribers')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get();
        $lapsed->each(fn (Record $subscriber) => $subscriber->update(['status' => 'suspended']));
        $dead = $this->records('subscribers')->where('status', 'suspended')->whereNotNull('due_on')->whereDate('due_on', '<', today()->subDays(self::DISCONNECT_AFTER_DAYS)->toDateString())->get();
        $dead->each(fn (Record $subscriber) => $subscriber->update(['status' => 'disconnected']));

        return $lapsed->count() + $dead->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'installations') {
            return $record->status === 'booked' ? [
                'install' => ['label' => 'Installed', 'icon' => 'check', 'fields' => [['name' => 'signal_strength', 'label' => 'Signal strength %', 'type' => 'number', 'value' => $record->value('signal_strength')]]],
                'fail' => ['label' => 'Failed', 'icon' => 'x'],
            ] : [];
        }

        return [
            'renew' => ['label' => 'Renew', 'icon' => 'refresh-cw', 'fields' => [['name' => 'months', 'label' => 'Months', 'type' => 'number', 'value' => 1]]],
            'change' => ['label' => 'Change bouquet', 'icon' => 'tv', 'fields' => [
                ['name' => 'bouquet', 'label' => 'Bouquet', 'type' => 'select', 'options' => array_combine($bouquets = ['basic', 'family', 'compact', 'premium', 'sports', 'custom'], array_map('ucfirst', $bouquets)), 'value' => $record->value('bouquet')],
                ['name' => 'price', 'label' => 'Monthly price', 'type' => 'number', 'value' => $record->amount],
            ]],
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'renew':
                $months = (int) $request->validate(['months' => ['required', 'integer', 'min:1', 'max:12']])['months'];
                $from = $record->due_on && $record->due_on->gte(today()) ? $record->due_on : today();
                $record->update(['status' => 'active', 'due_on' => $from->copy()->addMonthsNoOverflow($months)]);

                return $record->title.' renewed for '.$months.' '.str('month')->plural($months).', '.$this->money($months * (float) $record->amount).', until '.$record->due_on->format('d M Y').'.';
            case 'change':
                $input = $request->validate(['bouquet' => ['required', 'in:basic,family,compact,premium,sports,custom'], 'price' => ['nullable', 'numeric', 'min:0']]);
                $record->update(['amount' => $input['price'] ?? $record->amount, 'data' => [...$record->data, 'bouquet' => $input['bouquet']]]);

                return $record->title.' moved to '.ucfirst($input['bouquet']).'.';
            case 'install':
                $signal = (float) $request->validate(['signal_strength' => ['required', 'numeric', 'min:0', 'max:100']])['signal_strength'];
                if ($signal < self::MIN_SIGNAL) {
                    throw ValidationException::withMessages(['signal_strength' => 'Signal of '.(int) $signal.'% is too weak; realign the dish or mark it failed.']);
                }
                $record->update(['status' => 'installed', 'data' => [...$record->data, 'signal_strength' => $signal]]);
                $subscriber = $this->parent($record, 'subscriber');
                if ($subscriber && $subscriber->status !== 'active') {
                    $subscriber->update(['status' => 'active', 'occurs_on' => today(), 'due_on' => today()->addMonthNoOverflow()]);
                }

                return 'Installed with '.(int) $signal.'% signal'.($subscriber ? '; '.$subscriber->title.' is active' : '').'.';
            default:
                $record->update(['status' => 'failed']);

                return $record->title.'\'s installation failed.';
        }
    }

    public function homeCards(): array
    {
        $subscribers = $this->records('subscribers')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Renewals due in 7 days', 'icon' => 'calendar-clock', 'empty' => 'No renewals due this week.',
                'rows' => $subscribers->where('status', 'active')->filter(fn (Record $subscriber) => $subscriber->due_on && $subscriber->due_on->lte(today()->addDays(7)))->sortBy('due_on')
                    ->map(fn (Record $subscriber) => ['label' => $subscriber->title, 'sub' => ucfirst((string) $subscriber->value('bouquet')), 'value' => $subscriber->due_on->format('d M'), 'href' => $subscriber->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Subscribers', 'icon' => 'tv', 'stats' => [
                ['label' => 'Active', 'value' => $subscribers->where('status', 'active')->count()],
                ['label' => 'Suspended', 'value' => $subscribers->where('status', 'suspended')->count()],
                ['label' => 'Monthly revenue', 'value' => $this->money($subscribers->where('status', 'active')->sum('amount'))],
                ['label' => 'Installations booked', 'value' => $this->records('installations')->where('status', 'booked')->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $subscribers = $this->records('subscribers')->get();
        $installations = $this->dated('installations', $from, $to)->get();

        return [
            ['title' => 'Subscribers by bouquet', 'columns' => ['Bouquet', 'Active', 'Suspended', 'Disconnected', 'Monthly revenue'], 'rows' => $subscribers
                ->groupBy(fn (Record $subscriber) => ucfirst((string) $subscriber->value('bouquet')))->sortKeys()
                ->map(fn ($group, string $bouquet) => [$bouquet, $group->where('status', 'active')->count(), $group->where('status', 'suspended')->count(), $group->where('status', 'disconnected')->count(), $this->money($group->where('status', 'active')->sum('amount'))])
                ->values()->all()],
            ['title' => 'Installations', 'columns' => ['Result', 'Jobs', 'Fees', 'Average signal'], 'rows' => $installations
                ->groupBy('status')->sortKeys()
                ->map(fn ($group, string $status) => [ucfirst($status), $group->count(), $this->money($group->sum('amount')), ($signals = $group->filter(fn (Record $job) => filled($job->value('signal_strength'))))->count() ? round($signals->avg(fn (Record $job) => $this->number($job, 'signal_strength'))).'%' : '—'])
                ->values()->all()],
        ];
    }
}
