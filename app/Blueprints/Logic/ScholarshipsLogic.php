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
 * Scholarships: applications are taken only on open schemes before their deadline, one per
 * applicant per scheme, and awards never exceed the scheme's slots or its fund. An award is
 * disbursed only after it is made, and each scheme tracks what is awarded, disbursed and left.
 */
class ScholarshipsLogic extends AppLogic
{
    public const AWARDED = ['awarded', 'disbursed'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'schemes') {
            if (filled($data['slots'] ?? null) && (int) $data['slots'] < 0) {
                $errors['data.slots'] = 'Slots cannot be negative.';
            }
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'The fund size cannot be negative.';
            }
            if ($existing) {
                $awarded = $this->linked('applications', 'scheme', $existing)->whereIn('status', self::AWARDED)->get();
                if (filled($data['slots'] ?? null) && (int) $data['slots'] > 0 && $awarded->count() > (int) $data['slots']) {
                    $errors['data.slots'] = $awarded->count().' awards are already made on this scheme.';
                }
                if ((float) ($payload['amount'] ?? 0) > 0 && $awarded->sum('amount') > (float) $payload['amount']) {
                    $errors['amount'] = $this->money($awarded->sum('amount')).' is already awarded on this scheme.';
                }
            }

            return $errors;
        }

        $scheme = ! empty($data['scheme']) ? $this->records('schemes')->find($data['scheme']) : null;
        $newApplication = ! $existing || (int) $existing->value('scheme') !== $scheme?->id;
        if ($scheme && $newApplication) {
            if ($scheme->status !== 'open') {
                $errors['data.scheme'] = $scheme->title.' is closed.';
            } elseif ($scheme->due_on && $scheme->due_on->lt(today())) {
                $errors['data.scheme'] = 'Applications for '.$scheme->title.' closed on '.$scheme->due_on->format('d M Y').'.';
            }
        }
        if ($scheme && filled($payload['contact_id'] ?? null) && $this->linked('applications', 'scheme', $scheme)->where('contact_id', $payload['contact_id'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            $errors['contact_id'] = 'This applicant has already applied to '.$scheme->title.'.';
        }
        if (filled($data['household_income'] ?? null) && (float) $data['household_income'] < 0) {
            $errors['data.household_income'] = 'Household income cannot be negative.';
        }
        $amount = (float) ($payload['amount'] ?? 0);
        if (in_array($payload['status'], self::AWARDED, true)) {
            if ($amount <= 0) {
                $errors['amount'] = 'Enter the award amount.';
            } elseif ($scheme && ($existing?->status === null || ! in_array($existing->status, self::AWARDED, true) || (float) $existing->amount !== $amount)) {
                $others = $this->linked('applications', 'scheme', $scheme)->whereIn('status', self::AWARDED)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get();
                if ((int) $scheme->value('slots') > 0 && $others->count() >= (int) $scheme->value('slots') && (! $existing || ! in_array($existing->status, self::AWARDED, true))) {
                    $errors['status'] = 'All '.(int) $scheme->value('slots').' slots on '.$scheme->title.' are awarded.';
                } elseif ((float) $scheme->amount > 0 && $others->sum('amount') + $amount > (float) $scheme->amount) {
                    $errors['amount'] = 'Only '.$this->money((float) $scheme->amount - $others->sum('amount')).' is left in the fund.';
                }
            }
        }
        if ($payload['status'] === 'disbursed' && $existing && ! in_array($existing->status, self::AWARDED, true)) {
            $errors['status'] = 'An award is disbursed only after it is made.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'applications') {
            $record->occurs_on ??= today();
            $this->put($record, [
                '_awarded_on' => in_array($record->status, self::AWARDED, true) ? ($record->value('_awarded_on') ?? today()->toDateString()) : null,
                '_disbursed_on' => $record->status === 'disbursed' ? ($record->value('_disbursed_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $applications = $record->exists ? $this->linked('applications', 'scheme', $record)->get() : collect();
        $awarded = $applications->whereIn('status', self::AWARDED);
        $fund = (float) $record->amount;
        $slots = (int) $record->value('slots');
        $this->put($record, [
            '_applications' => $applications->count(),
            '_shortlisted' => $applications->where('status', 'shortlisted')->count(),
            '_awarded' => $awarded->count(),
            '_awarded_amount' => round($awarded->sum('amount'), 2),
            '_disbursed_amount' => round($applications->where('status', 'disbursed')->sum('amount'), 2),
            '_fund_remaining' => $fund > 0 ? round($fund - $awarded->sum('amount'), 2) : null,
            '_slots_remaining' => $slots > 0 ? max(0, $slots - $awarded->count()) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'applications') {
            $this->recalculate($this->parent($record, 'scheme'));
            $this->recalculate($this->previousParent($record, 'scheme'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'applications') {
            $this->recalculate($this->parent($record, 'scheme'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $closed = 0;
        foreach ($this->records('schemes')->where('status', 'open')->whereNotNull('due_on')->whereDate('due_on', '<', today())->get() as $scheme) {
            if ($this->linked('applications', 'scheme', $scheme)->whereIn('status', ['submitted', 'shortlisted'])->doesntExist()) {
                $scheme->update(['status' => 'closed']);
                $closed++;
            }
        }

        return $closed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'schemes') {
            return $record->status === 'open'
                ? ['close' => ['label' => 'Close scheme', 'icon' => 'lock', 'confirm' => 'Close this scheme to new applications?']]
                : ['reopen' => ['label' => 'Reopen', 'icon' => 'unlock', 'fields' => [
                    ['name' => 'due_on', 'label' => 'New deadline', 'type' => 'date', 'value' => today()->addMonth()->toDateString()],
                ]]];
        }

        return match ($record->status) {
            'submitted' => [
                'shortlist' => ['label' => 'Shortlist', 'icon' => 'list-checks'],
                'decline' => ['label' => 'Decline', 'icon' => 'x'],
            ],
            'shortlisted' => [
                'award' => ['label' => 'Award', 'icon' => 'hand-coins', 'fields' => [
                    ['name' => 'amount', 'label' => 'Award amount', 'type' => 'number', 'value' => $record->amount],
                ]],
                'decline' => ['label' => 'Decline', 'icon' => 'x'],
            ],
            'awarded' => ['disburse' => ['label' => 'Disburse', 'icon' => 'banknote', 'confirm' => 'Mark this award as paid out?']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'close':
                $record->update(['status' => 'closed']);

                return $record->title.' is closed.';
            case 'reopen':
                $dueOn = $request->validate(['due_on' => ['required', 'date', 'after_or_equal:today']])['due_on'];
                $record->update(['status' => 'open', 'due_on' => Carbon::parse($dueOn)]);

                return $record->title.' is open until '.Carbon::parse($dueOn)->format('d M Y').'.';
            case 'shortlist':
                $record->update(['status' => 'shortlisted']);

                return $record->title.' shortlisted.';
            case 'decline':
                $record->update(['status' => 'declined']);

                return $record->title.' declined.';
            case 'disburse':
                $record->update(['status' => 'disbursed']);

                return $this->money($record->amount).' disbursed to '.$record->title.'.';
        }

        $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'gt:0']])['amount'];
        $scheme = $this->parent($record, 'scheme');
        if ($scheme) {
            $others = $this->linked('applications', 'scheme', $scheme)->whereIn('status', self::AWARDED)->whereKeyNot($record->id)->get();
            if ((int) $scheme->value('slots') > 0 && $others->count() >= (int) $scheme->value('slots')) {
                throw ValidationException::withMessages(['status' => 'All '.(int) $scheme->value('slots').' slots on '.$scheme->title.' are awarded.']);
            }
            if ((float) $scheme->amount > 0 && $others->sum('amount') + $amount > (float) $scheme->amount) {
                throw ValidationException::withMessages(['amount' => 'Only '.$this->money((float) $scheme->amount - $others->sum('amount')).' is left in the fund.']);
            }
        }
        $record->update(['status' => 'awarded', 'amount' => $amount]);

        return $record->title.' awarded '.$this->money($amount).'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'schemes') {
            return [];
        }

        $applications = $this->linked('applications', 'scheme', $record)->orderByDesc('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Scheme', 'icon' => 'hand-coins', 'stats' => [
                ['label' => 'Applications', 'value' => (string) (int) $record->value('_applications')],
                ['label' => 'Shortlisted', 'value' => (string) (int) $record->value('_shortlisted')],
                ['label' => 'Awarded', 'value' => (int) $record->value('_awarded').((int) $record->value('slots') > 0 ? ' of '.(int) $record->value('slots') : '')],
                ['label' => 'Awarded amount', 'value' => $this->money($this->number($record, '_awarded_amount'))],
                ['label' => 'Disbursed', 'value' => $this->money($this->number($record, '_disbursed_amount'))],
                ['label' => 'Fund remaining', 'value' => $record->value('_fund_remaining') === null ? '—' : $this->money($this->number($record, '_fund_remaining')), 'tone' => $record->value('_fund_remaining') !== null && (float) $record->value('_fund_remaining') <= 0 ? 'warning' : null],
                ['label' => 'Deadline', 'value' => $record->due_on?->format('d M Y') ?? 'Rolling', 'tone' => $record->due_on?->lt(today()) ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Applications', 'icon' => 'file-text', 'empty' => 'No applications yet.',
                'rows' => $applications->take(10)->map(fn (Record $application) => [
                    'label' => $application->title, 'sub' => ($application->value('institution') ?: 'No institution').' · '.$application->occurs_on?->format('d M Y'), 'value' => in_array($application->status, self::AWARDED, true) ? $this->money($application->amount) : ucfirst($application->status), 'href' => $application->url(),
                    'tone' => $application->status === 'declined' ? 'danger' : (in_array($application->status, self::AWARDED, true) ? 'success' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $schemes = $this->records('schemes')->get();
        $applications = $this->records('applications')->get();
        $open = $schemes->where('status', 'open');
        $closing = $open->filter(fn (Record $scheme) => $scheme->due_on && $scheme->due_on->between(today(), today()->addDays(14)))->sortBy('due_on');
        $toReview = $applications->where('status', 'submitted');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Scholarships', 'icon' => 'hand-coins', 'stats' => [
                ['label' => 'Open schemes', 'value' => (string) $open->count()],
                ['label' => 'To review', 'value' => (string) $toReview->count(), 'tone' => $toReview->isNotEmpty() ? 'warning' : null],
                ['label' => 'Shortlisted', 'value' => (string) $applications->where('status', 'shortlisted')->count()],
                ['label' => 'Awarded this year', 'value' => $this->money($applications->whereIn('status', self::AWARDED)->filter(fn (Record $application) => $application->value('_awarded_on') && Carbon::parse($application->value('_awarded_on'))->isCurrentYear())->sum('amount'))],
                ['label' => 'Awaiting disbursement', 'value' => $this->money($applications->where('status', 'awarded')->sum('amount')), 'tone' => $applications->where('status', 'awarded')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Closing soon', 'icon' => 'calendar-clock', 'empty' => 'No deadlines in the next two weeks.',
                'rows' => $closing->take(10)->map(fn (Record $scheme) => [
                    'label' => $scheme->title, 'sub' => ($scheme->value('sponsor') ?: 'No sponsor').' · '.(int) $scheme->value('_applications').' applications', 'value' => $scheme->due_on->format('d M'), 'href' => $scheme->url(), 'tone' => 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $schemes = $this->records('schemes')->get();
        $types = $this->app->entities['schemes']->field('type')?->options ?? [];
        $bySchemeRows = $schemes->sortBy('title')->map(fn (Record $scheme) => [
            $scheme->title, $types[$scheme->value('type')] ?? ucfirst((string) $scheme->value('type')), $scheme->value('sponsor') ?: '—', ucfirst($scheme->status), (int) $scheme->value('_applications'), (int) $scheme->value('_awarded'), $this->money($this->number($scheme, '_awarded_amount')), $this->money($this->number($scheme, '_disbursed_amount')),
        ])->values()->all();

        $applications = $this->dated('applications', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($applications) {
            $group = $applications->filter(fn (Record $application) => $application->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'shortlisted')->count(), $group->whereIn('status', self::AWARDED)->count(), $group->where('status', 'declined')->count(), $this->money($group->whereIn('status', self::AWARDED)->sum('amount'))];
        })->values()->all();

        $byInstitution = $applications->groupBy(fn (Record $application) => $application->value('institution') ?: 'Not given')->sortKeys()->map(fn ($group, $institution) => [
            $institution, $group->count(), $group->whereIn('status', self::AWARDED)->count(), $this->money($group->whereIn('status', self::AWARDED)->sum('amount')),
        ])->values()->all();

        return [
            ['title' => 'Schemes', 'columns' => ['Scheme', 'Type', 'Sponsor', 'Status', 'Applications', 'Awarded', 'Awarded amount', 'Disbursed'], 'rows' => $bySchemeRows],
            ['title' => 'Applications by month', 'columns' => ['Month', 'Applications', 'Shortlisted', 'Awarded', 'Declined', 'Awarded amount'], 'rows' => $byMonth],
            ['title' => 'Applications by institution', 'columns' => ['Institution', 'Applications', 'Awarded', 'Awarded amount'], 'rows' => $byInstitution],
        ];
    }
}
