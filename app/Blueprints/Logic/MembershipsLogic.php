<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Memberships: every member gets a unique membership number, an active member always has a renewal
 * date (a year from joining unless set), and members whose renewal is more than a month overdue lapse
 * automatically. Renewing pushes the date on by a year, upgrades change the tier, and the home page
 * shows who is due to renew.
 */
class MembershipsLogic extends AppLogic
{
    public const GRACE_DAYS = 30;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        $number = strtoupper(trim((string) ($data['membership_number'] ?? '')));
        if ($number !== '' && $this->records('members')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $member) => strtoupper(trim((string) $member->value('membership_number'))) === $number)) {
            $errors['data.membership_number'] = 'Membership number '.$number.' belongs to another member.';
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The annual fee cannot be negative.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The renewal date comes before the joining date.';
        }
        if (($data['tier'] ?? null) === 'honorary' && (float) ($payload['amount'] ?? 0) > 0) {
            $errors['amount'] = 'Honorary members pay no fee.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->status === 'active' && ! $record->due_on) {
            $record->due_on = $record->occurs_on->copy()->addYear();
        }
        $number = strtoupper(trim((string) $record->value('membership_number')));
        if ($number === '') {
            do {
                $number = 'M-'.$record->occurs_on->format('y').strtoupper(Str::random(5));
            } while ($this->records('members')->where('data->membership_number', $number)->exists());
        }
        $days = $record->due_on ? (int) today()->diffInDays($record->due_on, false) : null;
        $this->put($record, [
            'membership_number' => $number,
            '_days_to_renewal' => $record->status === 'active' ? $days : null,
            '_overdue' => $record->status === 'active' && $days !== null && $days < 0,
            '_years' => (int) $record->occurs_on->diffInYears(today()),
            '_lapsed_on' => $record->status === 'lapsed' ? ($record->value('_lapsed_on') ?? today()->toDateString()) : null,
        ]);
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = 0;
        foreach ($this->records('members')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<', today()->subDays(self::GRACE_DAYS))->get() as $member) {
            $member->update(['status' => 'lapsed']);
            $lapsed++;
        }
        foreach ($this->records('members')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<', today())->get() as $member) {
            if (! $member->value('_overdue')) {
                $this->recalculate($member);
            }
        }

        return $lapsed;
    }

    public function actions(Record $record): array
    {
        $tiers = $this->app->entities['members']->field('tier')?->options ?? [];
        $renew = ['label' => 'Renew', 'icon' => 'refresh-cw', 'fields' => [
            ['name' => 'amount', 'label' => 'Fee paid', 'type' => 'number', 'value' => $record->amount],
            ['name' => 'due_on', 'label' => 'Renews on', 'type' => 'date', 'value' => ($record->due_on && $record->due_on->gt(today()) ? $record->due_on : today())->copy()->addYear()->toDateString()],
        ]];

        return match ($record->status) {
            'active' => [
                'renew' => $renew,
                'change_tier' => ['label' => 'Change tier', 'icon' => 'badge-check', 'fields' => [
                    ['name' => 'tier', 'label' => 'Tier', 'type' => 'select', 'options' => $tiers, 'value' => $record->value('tier')],
                    ['name' => 'amount', 'label' => 'Annual fee', 'type' => 'number', 'value' => $record->amount],
                ]],
                'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel '.$record->title.'\'s membership?'],
            ],
            default => ['reinstate' => $renew + ['label' => 'Reinstate', 'icon' => 'user-check']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'renew':
            case 'reinstate':
                $input = $request->validate(['amount' => ['nullable', 'numeric', 'min:0'], 'due_on' => ['required', 'date', 'after:today']]);
                $record->update(['status' => 'active', 'due_on' => Carbon::parse($input['due_on']), 'amount' => filled($input['amount'] ?? null) ? (float) $input['amount'] : $record->amount]);

                return $record->title.($action === 'renew' ? ' renewed' : ' reinstated').' until '.Carbon::parse($input['due_on'])->format('d M Y').'.';
            case 'change_tier':
                $input = $request->validate(['tier' => ['required', 'in:standard,silver,gold,honorary'], 'amount' => ['nullable', 'numeric', 'min:0']]);
                $record->update(['amount' => $input['tier'] === 'honorary' ? 0 : (filled($input['amount'] ?? null) ? (float) $input['amount'] : $record->amount), 'data' => [...$record->data, 'tier' => $input['tier']]]);

                return $record->title.' is now a '.$input['tier'].' member.';
        }

        $record->update(['status' => 'cancelled']);

        return $record->title.'\'s membership is cancelled.';
    }

    public function recordCards(Record $record): array
    {
        $days = $record->value('_days_to_renewal');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Membership', 'icon' => 'badge-check', 'stats' => [
                ['label' => 'Number', 'value' => (string) $record->value('membership_number')],
                ['label' => 'Tier', 'value' => ucfirst((string) ($record->value('tier') ?: 'standard'))],
                ['label' => 'Member for', 'value' => (int) $record->value('_years').' years'],
                ['label' => 'Annual fee', 'value' => $this->money($record->amount)],
                ['label' => 'Renews on', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->value('_overdue') ? 'danger' : ($days !== null && $days <= 30 ? 'warning' : null)],
                ['label' => 'Renewal', 'value' => $record->status !== 'active' ? ucfirst($record->status) : ($days === null ? '—' : ($days < 0 ? abs($days).' days overdue' : 'in '.$days.' days')), 'tone' => $record->value('_overdue') ? 'danger' : null],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $members = $this->records('members')->get();
        $active = $members->where('status', 'active');
        $due = $active->filter(fn (Record $member) => $member->due_on && $member->due_on->lte(today()->addDays(30)))->sortBy('due_on');
        $overdue = $due->filter(fn (Record $member) => $member->due_on->lt(today()));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Members', 'icon' => 'badge-check', 'stats' => [
                ['label' => 'Active', 'value' => (string) $active->count()],
                ['label' => 'Lapsed', 'value' => (string) $members->where('status', 'lapsed')->count(), 'tone' => $members->where('status', 'lapsed')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Joined this year', 'value' => (string) $members->filter(fn (Record $member) => $member->occurs_on?->isCurrentYear())->count()],
                ['label' => 'Due in 30 days', 'value' => (string) $due->count(), 'tone' => $due->isNotEmpty() ? 'warning' : null],
                ['label' => 'Overdue', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'danger' : null],
                ['label' => 'Annual fees', 'value' => $this->money($active->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Renewals due', 'icon' => 'refresh-cw', 'empty' => 'No renewals due in the next 30 days.',
                'rows' => $due->take(10)->map(fn (Record $member) => [
                    'label' => $member->title, 'sub' => $member->value('membership_number').' · '.ucfirst((string) ($member->value('tier') ?: 'standard')), 'value' => $member->due_on->format('d M'), 'href' => $member->url(), 'tone' => $member->due_on->lt(today()) ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $members = $this->records('members')->get();
        $tiers = $this->app->entities['members']->field('tier')?->options ?? [];
        $byTier = collect($tiers)->map(fn (string $label, string $tier) => [
            $label, $members->where('data.tier', $tier)->where('status', 'active')->count(), $members->where('data.tier', $tier)->where('status', 'lapsed')->count(), $members->where('data.tier', $tier)->where('status', 'cancelled')->count(), $this->money($members->where('data.tier', $tier)->where('status', 'active')->sum('amount')),
        ])->values()->all();

        $byRenewal = collect($this->months($from, $to))->map(function (string $label, string $month) use ($members) {
            $group = $members->filter(fn (Record $member) => $member->status === 'active' && $member->due_on?->format('Y-m') === $month);

            return [$label, $group->count(), $this->money($group->sum('amount'))];
        })->values()->all();

        $joined = $this->dated('members', $from, $to)->get();
        $byJoined = collect($this->months($from, $to))->map(function (string $label, string $month) use ($joined) {
            $group = $joined->filter(fn (Record $member) => $member->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'active')->count(), $this->money($group->sum('amount'))];
        })->values()->all();

        return [
            ['title' => 'Members by tier', 'columns' => ['Tier', 'Active', 'Lapsed', 'Cancelled', 'Annual fees'], 'rows' => $byTier],
            ['title' => 'Renewals by month', 'columns' => ['Month', 'Renewals due', 'Fees'], 'rows' => $byRenewal],
            ['title' => 'Joined by month', 'columns' => ['Month', 'Joined', 'Still active', 'Fees'], 'rows' => $byJoined],
        ];
    }
}
