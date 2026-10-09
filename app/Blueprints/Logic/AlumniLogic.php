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
 * Alumni & professional associations: each member has one membership number, an annual
 * subscription and a renewal date, and lapses by itself a month after the renewal date passes.
 * CPD is logged against active members, counts towards the year's target only once verified, and
 * the home page shows who is behind on points and who is due to renew.
 */
class AlumniLogic extends AppLogic
{
    public const CPD_TARGET = 20;

    public const GRACE_DAYS = 30;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'members') {
            $number = strtoupper(trim((string) ($data['membership_number'] ?? '')));
            if ($number !== '' && $this->records('members')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $member) => strtoupper(trim((string) $member->value('membership_number'))) === $number)) {
                $errors['data.membership_number'] = 'Membership number '.$number.' is already in use.';
            }
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'The subscription cannot be negative.';
            }
            if (filled($data['graduation_year'] ?? null) && ((int) $data['graduation_year'] < 1900 || (int) $data['graduation_year'] > (int) today()->year)) {
                $errors['data.graduation_year'] = 'Enter a year between 1900 and '.today()->year.'.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The renewal date is before the joining date.';
            }

            return $errors;
        }

        $member = ! empty($data['member']) ? $this->records('members')->find($data['member']) : null;
        if ($member && $member->status !== 'active' && (! $existing || (int) $existing->value('member') !== $member->id)) {
            $errors['data.member'] = $member->title.' is '.$member->status.'; CPD is logged for active members.';
        }
        if ((float) ($data['points'] ?? 0) <= 0) {
            $errors['data.points'] = 'Enter the CPD points earned.';
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['occurs_on'] = 'CPD is logged once the activity is done.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'cpd') {
            $record->occurs_on ??= today();
            $this->put($record, [
                'points' => round((float) $record->value('points'), 1),
                '_verified_on' => $record->status === 'verified' ? ($record->value('_verified_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $cpd = $record->exists ? $this->linked('cpd', 'member', $record)->get() : collect();
        $year = $cpd->filter(fn (Record $entry) => $entry->occurs_on?->isCurrentYear());
        $points = $year->where('status', 'verified')->sum(fn (Record $entry) => (float) $entry->value('points'));
        $this->put($record, [
            'membership_number' => strtoupper(trim((string) $record->value('membership_number'))) ?: null,
            'email' => strtolower(trim((string) $record->value('email'))) ?: null,
            '_cpd_points' => round($points, 1),
            '_cpd_target' => self::CPD_TARGET,
            '_cpd_met' => $points >= self::CPD_TARGET,
            '_cpd_pending' => $year->where('status', 'submitted')->count(),
            '_renewal_overdue' => $record->status === 'active' && $record->due_on?->lt(today()),
            '_years' => $record->occurs_on ? (int) $record->occurs_on->diffInYears(today()) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'cpd') {
            $this->recalculate($this->parent($record, 'member'));
            $this->recalculate($this->previousParent($record, 'member'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'cpd') {
            $this->recalculate($this->parent($record, 'member'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = 0;
        foreach ($this->records('members')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<', today()->subDays(self::GRACE_DAYS))->get() as $member) {
            $member->update(['status' => 'lapsed']);
            $lapsed++;
        }

        return $lapsed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'cpd') {
            return $record->status === 'submitted'
                ? ['verify' => ['label' => 'Verify', 'icon' => 'badge-check'], 'reject' => ['label' => 'Reject', 'icon' => 'x']]
                : [];
        }

        $renew = ['label' => 'Renew', 'icon' => 'refresh-cw', 'fields' => [['name' => 'amount', 'label' => 'Subscription', 'type' => 'number', 'value' => $record->amount]]];
        $grades = $this->app->entities['members']->field('grade')?->options ?? [];
        $upgrade = ['label' => 'Change grade', 'icon' => 'award', 'fields' => [['name' => 'grade', 'label' => 'Grade', 'type' => 'select', 'options' => $grades, 'value' => $record->value('grade')]]];

        return match ($record->status) {
            'active' => ['renew' => $renew, 'change_grade' => $upgrade, 'suspend' => ['label' => 'Suspend', 'icon' => 'ban', 'confirm' => 'Suspend '.$record->title.'?'], 'resign' => ['label' => 'Resigned', 'icon' => 'log-out', 'confirm' => 'Record '.$record->title.' as resigned?']],
            'lapsed' => ['renew' => $renew, 'resign' => ['label' => 'Resigned', 'icon' => 'log-out']],
            'suspended' => ['reinstate' => ['label' => 'Reinstate', 'icon' => 'user-check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'verify':
                $record->update(['status' => 'verified']);
                $member = $this->parent($record, 'member');

                return rtrim(rtrim(number_format((float) $record->value('points'), 1), '0'), '.').' points verified for '.($member?->title ?? 'the member').'.';
            case 'reject':
                $record->update(['status' => 'rejected']);

                return 'CPD record rejected.';
            case 'renew':
                $amount = $request->validate(['amount' => ['nullable', 'numeric', 'min:0']])['amount'] ?? $record->amount;
                $base = $record->due_on && $record->due_on->gt(today()) ? $record->due_on : today();
                $until = $base->copy()->addYear();
                $record->update(['status' => 'active', 'amount' => $amount, 'due_on' => $until]);

                return $record->title.' renewed until '.$until->format('d M Y').'.';
            case 'change_grade':
                $grades = $this->app->entities['members']->field('grade')?->options ?? [];
                $grade = $request->validate(['grade' => ['required', 'string']])['grade'];
                if (! array_key_exists($grade, $grades)) {
                    throw ValidationException::withMessages(['grade' => 'Choose a grade.']);
                }
                $record->update(['data' => [...$record->data, 'grade' => $grade]]);

                return $record->title.' is now '.$grades[$grade].'.';
            case 'suspend':
                $record->update(['status' => 'suspended']);

                return $record->title.' suspended.';
            case 'resign':
                $record->update(['status' => 'resigned']);

                return $record->title.' resigned.';
        }

        $record->update(['status' => 'active']);

        return $record->title.' reinstated.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'members') {
            return [];
        }

        $cpd = $this->linked('cpd', 'member', $record)->orderByDesc('occurs_on')->get();
        $grades = $this->app->entities['members']->field('grade')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Membership', 'icon' => 'user-round', 'stats' => [
                ['label' => 'Number', 'value' => $record->value('membership_number') ?: '—'],
                ['label' => 'Grade', 'value' => $grades[$record->value('grade')] ?? ucfirst((string) ($record->value('grade') ?: '—'))],
                ['label' => 'Subscription', 'value' => $this->money($record->amount)],
                ['label' => 'Renewal', 'value' => $record->due_on?->format('d M Y') ?? 'Not set', 'tone' => $record->value('_renewal_overdue') ? 'danger' : null],
                ['label' => 'CPD this year', 'value' => rtrim(rtrim(number_format($this->number($record, '_cpd_points'), 1), '0'), '.').' of '.self::CPD_TARGET.' points', 'tone' => $record->value('_cpd_met') ? 'success' : 'warning'],
                ['label' => 'CPD to verify', 'value' => (string) (int) $record->value('_cpd_pending')],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'CPD', 'icon' => 'award', 'empty' => 'No CPD logged.',
                'rows' => $cpd->take(15)->map(fn (Record $entry) => [
                    'label' => $entry->title, 'sub' => $entry->occurs_on?->format('d M Y').' · '.ucfirst(str_replace('_', ' ', (string) ($entry->value('category') ?: 'other'))), 'value' => rtrim(rtrim(number_format((float) $entry->value('points'), 1), '0'), '.').' pts · '.ucfirst($entry->status), 'href' => $entry->url(), 'tone' => $entry->status === 'verified' ? 'success' : ($entry->status === 'rejected' ? 'danger' : 'warning'),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $members = $this->records('members')->get();
        $active = $members->where('status', 'active');
        $due = $active->filter(fn (Record $member) => $member->due_on?->lte(today()->addDays(30)))->sortBy('due_on');
        $behind = $active->filter(fn (Record $member) => ! $member->value('_cpd_met'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Association', 'icon' => 'graduation-cap', 'stats' => [
                ['label' => 'Active members', 'value' => (string) $active->count()],
                ['label' => 'Lapsed', 'value' => (string) $members->where('status', 'lapsed')->count(), 'tone' => $members->where('status', 'lapsed')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Renewals due', 'value' => (string) $due->count(), 'tone' => $due->isNotEmpty() ? 'warning' : null],
                ['label' => 'Subscriptions a year', 'value' => $this->money($active->sum('amount'))],
                ['label' => 'Behind on CPD', 'value' => (string) $behind->count(), 'tone' => $behind->isNotEmpty() ? 'warning' : null],
                ['label' => 'CPD to verify', 'value' => (string) $this->records('cpd')->where('status', 'submitted')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Renewals due', 'icon' => 'calendar-clock', 'empty' => 'No renewals due in the next 30 days.',
                'rows' => $due->take(10)->map(fn (Record $member) => [
                    'label' => $member->title, 'sub' => ($member->value('membership_number') ?: '').' · '.$this->money($member->amount), 'value' => $member->due_on->format('d M Y'), 'href' => $member->url(), 'tone' => $member->due_on->lt(today()) ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $members = $this->records('members')->get();
        $grades = $this->app->entities['members']->field('grade')?->options ?? [];
        $byGrade = collect($grades)->map(fn (string $label, string $grade) => [
            $label, $members->where('data.grade', $grade)->where('status', 'active')->count(), $members->where('data.grade', $grade)->where('status', 'lapsed')->count(), $this->money($members->where('data.grade', $grade)->where('status', 'active')->sum('amount')),
        ])->values()->all();

        $cpd = $this->dated('cpd', $from, $to)->get();
        $categories = $this->app->entities['cpd']->field('category')?->options ?? [];
        $byCategory = collect($categories)->map(fn (string $label, string $category) => [
            $label, $cpd->where('data.category', $category)->count(), $cpd->where('data.category', $category)->where('status', 'verified')->sum(fn (Record $entry) => (float) $entry->value('points')), $cpd->where('data.category', $category)->where('status', 'submitted')->count(),
        ])->values()->all();

        $compliance = $members->where('status', 'active')->sortBy('title')->map(fn (Record $member) => [
            $member->title, $member->value('membership_number') ?: '—', $grades[$member->value('grade')] ?? '—', (float) $member->value('_cpd_points'), self::CPD_TARGET, $member->value('_cpd_met') ? 'Met' : 'Behind',
        ])->values()->all();

        return [
            ['title' => 'Members by grade', 'columns' => ['Grade', 'Active', 'Lapsed', 'Subscriptions'], 'rows' => $byGrade],
            ['title' => 'CPD by category', 'columns' => ['Category', 'Records', 'Verified points', 'To verify'], 'rows' => $byCategory],
            ['title' => 'CPD compliance', 'columns' => ['Member', 'Number', 'Grade', 'Points', 'Target', 'Status'], 'rows' => $compliance],
        ];
    }
}
