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
 * Fundraising: a campaign has a goal and works out what it has raised from the donations received
 * against it, with pledges counted separately. Donations go only to live campaigns, are receipted
 * once received, get a tax certificate only once receipted, and a campaign closes by itself when
 * its end date passes.
 */
class FundraisingLogic extends AppLogic
{
    public const RECEIVED = ['received', 'receipted'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'campaigns') {
            if ((float) ($data['goal'] ?? 0) <= 0) {
                $errors['data.goal'] = 'Set a goal for the campaign.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The campaign ends before it starts.';
            }
            if ($payload['status'] === 'live' && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(today())) {
                $errors['status'] = 'The end date has passed; set a new one to go live.';
            }

            return $errors;
        }

        $campaign = ! empty($data['campaign']) ? $this->records('campaigns')->find($data['campaign']) : null;
        $joining = $campaign && (! $existing || (int) $existing->value('campaign') !== $campaign->id);
        if ($joining && $campaign->status !== 'live') {
            $errors['data.campaign'] = $campaign->title.' is '.$campaign->status.'; donations go to live campaigns.';
        }
        if ((float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Enter the amount donated.';
        }
        if (! empty($data['tax_certificate']) && ! in_array($payload['status'], self::RECEIVED, true)) {
            $errors['data.tax_certificate'] = 'A tax certificate is issued only for a donation received.';
        }
        if ($payload['status'] === 'refunded' && $existing && ! in_array($existing->status, self::RECEIVED, true)) {
            $errors['status'] = 'Only a donation received can be refunded.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'donations') {
            $record->occurs_on ??= today();
            $this->put($record, [
                'recurring' => (bool) $record->value('recurring'),
                'tax_certificate' => (bool) $record->value('tax_certificate'),
                '_received_on' => in_array($record->status, self::RECEIVED, true) ? ($record->value('_received_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $donations = $record->exists ? $this->linked('donations', 'campaign', $record)->get() : collect();
        $received = $donations->whereIn('status', self::RECEIVED);
        $goal = (float) $record->value('goal');
        $this->put($record, [
            'raised' => round($received->sum('amount'), 2),
            '_pledged' => round($donations->where('status', 'pledged')->sum('amount'), 2),
            '_donors' => $received->count(),
            '_progress' => $goal > 0 ? (int) round($received->sum('amount') / $goal * 100) : 0,
            '_to_go' => round(max(0, $goal - $received->sum('amount')), 2),
            '_days_left' => $record->due_on && $record->status === 'live' ? max(0, (int) today()->diffInDays($record->due_on, false)) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'donations') {
            $this->recalculate($this->parent($record, 'campaign'));
            $this->recalculate($this->previousParent($record, 'campaign'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'donations') {
            $this->recalculate($this->parent($record, 'campaign'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $closed = 0;
        foreach ($this->records('campaigns')->where('status', 'live')->whereNotNull('due_on')->whereDate('due_on', '<', today())->get() as $campaign) {
            $campaign->update(['status' => 'closed']);
            $closed++;
        }

        return $closed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'campaigns') {
            return match ($record->status) {
                'planning' => ['launch' => ['label' => 'Go live', 'icon' => 'megaphone', 'fields' => [
                    ['name' => 'due_on', 'label' => 'Ends on', 'type' => 'date', 'value' => $record->due_on?->toDateString() ?? today()->addMonth()->toDateString()],
                ]]],
                'live' => ['close' => ['label' => 'Close campaign', 'icon' => 'lock', 'confirm' => 'Close '.$record->title.'? Pledges still open can be received afterwards.']],
                default => [],
            };
        }

        return match ($record->status) {
            'pledged' => ['receive' => ['label' => 'Received', 'icon' => 'hand-coins', 'fields' => [
                ['name' => 'method', 'label' => 'Method', 'type' => 'select', 'options' => $this->app->entities['donations']->field('method')?->options ?? [], 'value' => $record->value('method') ?: 'bank'],
            ]]],
            'received' => ['receipt' => ['label' => 'Send receipt', 'icon' => 'receipt', 'fields' => [
                ['name' => 'tax_certificate', 'label' => 'Issue tax certificate', 'type' => 'checkbox', 'value' => false],
            ]], 'refund' => ['label' => 'Refund', 'icon' => 'undo', 'confirm' => 'Refund this donation?']],
            'receipted' => ['refund' => ['label' => 'Refund', 'icon' => 'undo', 'confirm' => 'Refund this donation?']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'launch':
                $dueOn = Carbon::parse($request->validate(['due_on' => ['required', 'date', 'after_or_equal:today']])['due_on']);
                if ((float) $record->value('goal') <= 0) {
                    throw ValidationException::withMessages(['data.goal' => 'Set a goal before going live.']);
                }
                $record->update(['status' => 'live', 'occurs_on' => $record->occurs_on ?? today(), 'due_on' => $dueOn]);

                return $record->title.' is live until '.$dueOn->format('d M Y').'.';
            case 'close':
                $record->update(['status' => 'closed']);

                return $record->title.' closed at '.(int) $record->fresh()->value('_progress').'% of its goal.';
            case 'receive':
                $method = $request->validate(['method' => ['nullable', 'string']])['method'] ?? $record->value('method');
                $record->update(['status' => 'received', 'data' => [...$record->data, 'method' => $method]]);

                return 'Donation received from '.$record->title.'.';
            case 'receipt':
                $certificate = $request->boolean('tax_certificate');
                $record->update(['status' => 'receipted', 'data' => [...$record->data, 'tax_certificate' => $certificate]]);

                return 'Receipt sent to '.$record->title.($certificate ? ' with a tax certificate.' : '.');
        }

        $record->update(['status' => 'refunded', 'data' => [...$record->data, 'tax_certificate' => false]]);

        return 'Donation from '.$record->title.' refunded.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'campaigns') {
            return [];
        }

        $donations = $this->linked('donations', 'campaign', $record)->orderByDesc('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Campaign', 'icon' => 'megaphone', 'stats' => [
                ['label' => 'Goal', 'value' => $this->money($this->number($record, 'goal'))],
                ['label' => 'Raised', 'value' => $this->money($this->number($record, 'raised')), 'tone' => (int) $record->value('_progress') >= 100 ? 'success' : null],
                ['label' => 'Progress', 'value' => (int) $record->value('_progress').'%', 'tone' => (int) $record->value('_progress') >= 100 ? 'success' : null],
                ['label' => 'To go', 'value' => $this->money($this->number($record, '_to_go'))],
                ['label' => 'Pledged', 'value' => $this->money($this->number($record, '_pledged'))],
                ['label' => 'Donors', 'value' => (string) (int) $record->value('_donors')],
                ['label' => 'Days left', 'value' => $record->value('_days_left') === null ? '—' : (string) (int) $record->value('_days_left'), 'tone' => $record->value('_days_left') !== null && (int) $record->value('_days_left') <= 7 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Donations', 'icon' => 'hand-heart', 'empty' => 'No donations yet.',
                'rows' => $donations->take(15)->map(fn (Record $donation) => [
                    'label' => $donation->title, 'sub' => $donation->occurs_on?->format('d M Y').' · '.ucfirst(str_replace('_', ' ', (string) ($donation->value('method') ?: 'method not set'))).($donation->value('recurring') ? ' · recurring' : ''), 'value' => $this->money($donation->amount).' · '.ucfirst($donation->status), 'href' => $donation->url(),
                    'tone' => $donation->status === 'refunded' ? 'danger' : ($donation->status === 'pledged' ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $campaigns = $this->records('campaigns')->get();
        $donations = $this->records('donations')->get();
        $live = $campaigns->where('status', 'live')->sortBy('due_on');
        $month = $donations->filter(fn (Record $donation) => in_array($donation->status, self::RECEIVED, true) && $donation->occurs_on?->isCurrentMonth());
        $pledges = $donations->where('status', 'pledged');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Fundraising', 'icon' => 'hand-heart', 'stats' => [
                ['label' => 'Live campaigns', 'value' => (string) $live->count()],
                ['label' => 'Raised this month', 'value' => $this->money($month->sum('amount'))],
                ['label' => 'Donations this month', 'value' => (string) $month->count()],
                ['label' => 'Pledges open', 'value' => $this->money($pledges->sum('amount')), 'tone' => $pledges->isNotEmpty() ? 'warning' : null],
                ['label' => 'Recurring donors', 'value' => (string) $donations->filter(fn (Record $donation) => $donation->value('recurring') && in_array($donation->status, self::RECEIVED, true))->pluck('title')->unique()->count()],
                ['label' => 'Receipts to send', 'value' => (string) $donations->where('status', 'received')->count(), 'tone' => $donations->where('status', 'received')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Live campaigns', 'icon' => 'megaphone', 'empty' => 'No campaign is live.',
                'rows' => $live->take(10)->map(fn (Record $campaign) => [
                    'label' => $campaign->title, 'sub' => $this->money($this->number($campaign, 'raised')).' of '.$this->money($this->number($campaign, 'goal')).($campaign->due_on ? ' · ends '.$campaign->due_on->format('d M') : ''), 'value' => (int) $campaign->value('_progress').'%', 'href' => $campaign->url(), 'tone' => (int) $campaign->value('_progress') >= 100 ? 'success' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $campaigns = $this->records('campaigns')->get();
        $campaignRows = $campaigns->sortByDesc(fn (Record $campaign) => (float) $campaign->value('raised'))->map(fn (Record $campaign) => [
            $campaign->title, ucfirst($campaign->status), $this->money((float) $campaign->value('goal')), $this->money((float) $campaign->value('raised')), (int) $campaign->value('_progress').'%', $this->money((float) $campaign->value('_pledged')), (int) $campaign->value('_donors'),
        ])->values()->all();

        $donations = $this->dated('donations', $from, $to)->get()->whereIn('status', self::RECEIVED);
        $methods = $this->app->entities['donations']->field('method')?->options ?? [];
        $byMethod = collect($methods)->map(fn (string $label, string $method) => [
            $label, $donations->where('data.method', $method)->count(), $this->money($donations->where('data.method', $method)->sum('amount')), $donations->where('data.method', $method)->filter(fn (Record $donation) => $donation->value('recurring'))->count(),
        ])->values()->all();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($donations) {
            $group = $donations->filter(fn (Record $donation) => $donation->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $this->money($group->sum('amount')), $group->filter(fn (Record $donation) => $donation->value('tax_certificate'))->count()];
        })->values()->all();

        return [
            ['title' => 'Campaigns', 'columns' => ['Campaign', 'Status', 'Goal', 'Raised', 'Progress', 'Pledged', 'Donors'], 'rows' => $campaignRows],
            ['title' => 'Donations by method', 'columns' => ['Method', 'Donations', 'Amount', 'Recurring'], 'rows' => $byMethod],
            ['title' => 'Donations by month', 'columns' => ['Month', 'Donations', 'Amount', 'Tax certificates'], 'rows' => $byMonth],
        ];
    }
}
