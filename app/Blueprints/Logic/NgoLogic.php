<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * NGO: a grant moves from pipeline to applied, awarded, reporting and closed, carries a value and
 * dates once awarded, and works out when its next funder report falls due from its reporting
 * frequency. Beneficiaries are funded only by awarded grants, inherit the grant's programme, and a
 * grant is not closed while it still funds active beneficiaries.
 */
class NgoLogic extends AppLogic
{
    public const FUNDED = ['awarded', 'reporting'];

    public const REPORT_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'biannual' => 6, 'annual' => 12];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'grants') {
            $amount = (float) ($payload['amount'] ?? 0);
            if ($amount < 0) {
                $errors['amount'] = 'The grant value cannot be negative.';
            } elseif (in_array($payload['status'], self::FUNDED, true) && $amount <= 0) {
                $errors['amount'] = 'Enter the value of the award.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The end date comes before the start date.';
            }
            if ($payload['status'] === 'closed' && $existing && $existing->status !== 'closed' && ($active = $this->linked('beneficiaries', 'grant', $existing)->where('status', 'active')->count()) > 0) {
                $errors['status'] = $active.' beneficiaries are still active on this grant; graduate or exit them first.';
            }

            return $errors;
        }

        $grant = ! empty($data['grant']) ? $this->records('grants')->find($data['grant']) : null;
        if ($grant && (! $existing || (int) $existing->value('grant') !== $grant->id) && ! in_array($grant->status, self::FUNDED, true)) {
            $errors['data.grant'] = $grant->title.' is '.str_replace('_', ' ', $grant->status).', not awarded.';
        }
        if (filled($data['age'] ?? null) && ((int) $data['age'] < 0 || (int) $data['age'] > 120)) {
            $errors['data.age'] = 'Enter an age between 0 and 120.';
        }
        if ($grant && $grant->occurs_on && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->lt($grant->occurs_on)) {
            $errors['occurs_on'] = $grant->title.' only starts on '.$grant->occurs_on->format('d M Y').'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'beneficiaries') {
            $record->occurs_on ??= today();
            $grant = $this->parent($record, 'grant');
            if (blank($record->value('programme')) && $grant?->value('programme')) {
                $this->put($record, ['programme' => $grant->value('programme')]);
            }
            $this->put($record, ['_exited_on' => in_array($record->status, ['graduated', 'exited'], true) ? ($record->value('_exited_on') ?? today()->toDateString()) : null]);

            return;
        }

        $beneficiaries = $record->exists ? $this->linked('beneficiaries', 'grant', $record)->get() : collect();
        $this->put($record, [
            '_beneficiaries' => $beneficiaries->count(),
            '_active' => $beneficiaries->where('status', 'active')->count(),
            '_graduated' => $beneficiaries->where('status', 'graduated')->count(),
            '_next_report' => $this->nextReport($record)?->toDateString(),
            '_days_left' => $record->due_on && in_array($record->status, self::FUNDED, true) ? (int) today()->diffInDays($record->due_on, false) : null,
            '_awarded_on' => in_array($record->status, [...self::FUNDED, 'closed'], true) ? ($record->value('_awarded_on') ?? today()->toDateString()) : null,
        ]);
    }

    /** The next funder report date from the grant's start date and reporting frequency, while it is funded. */
    protected function nextReport(Record $grant): ?Carbon
    {
        $months = self::REPORT_MONTHS[$grant->value('report_frequency')] ?? null;
        if (! $months || ! $grant->occurs_on || ! in_array($grant->status, self::FUNDED, true)) {
            return null;
        }
        $next = $grant->occurs_on->copy()->addMonths($months);
        while ($next->lt(today())) {
            $next->addMonths($months);
        }

        return $grant->due_on && $next->gt($grant->due_on) ? $grant->due_on->copy() : $next;
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'beneficiaries') {
            $this->recalculate($this->parent($record, 'grant'));
            $this->recalculate($this->previousParent($record, 'grant'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'beneficiaries') {
            $this->recalculate($this->parent($record, 'grant'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'beneficiaries') {
            return $record->status === 'active'
                ? ['graduate' => ['label' => 'Graduate', 'icon' => 'graduation-cap'], 'exit' => ['label' => 'Exit', 'icon' => 'log-out', 'confirm' => 'Exit '.$record->title.' from the programme?']]
                : ['reactivate' => ['label' => 'Re-enrol', 'icon' => 'user-plus']];
        }

        $award = ['label' => 'Record award', 'icon' => 'landmark', 'fields' => [
            ['name' => 'amount', 'label' => 'Grant value', 'type' => 'number', 'value' => $record->amount ?: ''],
            ['name' => 'occurs_on', 'label' => 'Start date', 'type' => 'date', 'value' => $record->occurs_on?->toDateString() ?? today()->toDateString()],
            ['name' => 'due_on', 'label' => 'End date', 'type' => 'date', 'value' => $record->due_on?->toDateString() ?? today()->addYear()->toDateString()],
        ]];

        return match ($record->status) {
            'pipeline' => ['apply' => ['label' => 'Mark applied', 'icon' => 'send'], 'decline' => ['label' => 'Drop', 'icon' => 'x']],
            'applied' => ['award' => $award, 'decline' => ['label' => 'Declined', 'icon' => 'x']],
            'awarded' => ['start_reporting' => ['label' => 'Start reporting', 'icon' => 'file-text'], 'close' => ['label' => 'Close', 'icon' => 'lock', 'confirm' => 'Close this grant? No beneficiary may still be active on it.']],
            'reporting' => ['close' => ['label' => 'Close', 'icon' => 'lock', 'confirm' => 'Close this grant? No beneficiary may still be active on it.']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'graduate':
                $record->update(['status' => 'graduated']);

                return $record->title.' graduated.';
            case 'exit':
                $record->update(['status' => 'exited']);

                return $record->title.' exited the programme.';
            case 'reactivate':
                $grant = $this->parent($record, 'grant');
                if ($grant && ! in_array($grant->status, self::FUNDED, true)) {
                    throw ValidationException::withMessages(['data.grant' => $grant->title.' is no longer funding beneficiaries.']);
                }
                $record->update(['status' => 'active']);

                return $record->title.' is active again.';
            case 'apply':
                $record->update(['status' => 'applied']);

                return 'Application to '.$record->value('funder').' recorded.';
            case 'decline':
                $record->update(['status' => 'declined']);

                return $record->title.' declined.';
            case 'award':
                $input = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'occurs_on' => ['required', 'date'], 'due_on' => ['required', 'date', 'after_or_equal:occurs_on']]);
                $record->update(['status' => 'awarded', 'amount' => (float) $input['amount'], 'occurs_on' => Carbon::parse($input['occurs_on']), 'due_on' => Carbon::parse($input['due_on'])]);

                return $record->value('funder').' awarded '.$record->title.' until '.Carbon::parse($input['due_on'])->format('d M Y').'.';
            case 'start_reporting':
                $record->update(['status' => 'reporting']);

                return $record->title.' is in reporting; next report due '.($record->fresh()->value('_next_report') ? Carbon::parse($record->fresh()->value('_next_report'))->format('d M Y') : 'at the end').'.';
        }

        $active = $this->linked('beneficiaries', 'grant', $record)->where('status', 'active')->count();
        if ($active > 0) {
            throw ValidationException::withMessages(['status' => $active.' beneficiaries are still active on this grant; graduate or exit them first.']);
        }
        $record->update(['status' => 'closed']);

        return $record->title.' closed.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'grants') {
            return [];
        }

        $beneficiaries = $this->linked('beneficiaries', 'grant', $record)->orderBy('title')->get();
        $daysLeft = $record->value('_days_left');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Grant', 'icon' => 'landmark', 'stats' => [
                ['label' => 'Value', 'value' => $this->money($record->amount)],
                ['label' => 'Period', 'value' => $record->occurs_on ? $record->occurs_on->format('d M Y').' – '.($record->due_on?->format('d M Y') ?? 'open') : 'Not set'],
                ['label' => 'Days left', 'value' => $daysLeft === null ? '—' : (string) max(0, (int) $daysLeft), 'tone' => $daysLeft !== null && (int) $daysLeft < 60 ? 'warning' : null],
                ['label' => 'Next report', 'value' => $record->value('_next_report') ? Carbon::parse($record->value('_next_report'))->format('d M Y') : 'None due', 'tone' => $record->value('_next_report') && Carbon::parse($record->value('_next_report'))->lte(today()->addDays(14)) ? 'warning' : null],
                ['label' => 'Beneficiaries', 'value' => (string) (int) $record->value('_beneficiaries')],
                ['label' => 'Active', 'value' => (string) (int) $record->value('_active')],
                ['label' => 'Graduated', 'value' => (string) (int) $record->value('_graduated'), 'tone' => (int) $record->value('_graduated') > 0 ? 'success' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Beneficiaries', 'icon' => 'users', 'empty' => 'Nobody is funded by this grant yet.',
                'rows' => $beneficiaries->take(15)->map(fn (Record $beneficiary) => [
                    'label' => $beneficiary->title, 'sub' => implode(' · ', array_filter([$beneficiary->value('location'), $beneficiary->value('programme')])), 'value' => ucfirst($beneficiary->status), 'href' => $beneficiary->url(), 'tone' => $beneficiary->status === 'graduated' ? 'success' : ($beneficiary->status === 'exited' ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $grants = $this->records('grants')->get();
        $beneficiaries = $this->records('beneficiaries')->get();
        $funded = $grants->whereIn('status', self::FUNDED);
        $reports = $funded->filter(fn (Record $grant) => $grant->value('_next_report') && Carbon::parse($grant->value('_next_report'))->lte(today()->addDays(30)))->sortBy('_next_report');
        $ending = $funded->filter(fn (Record $grant) => $grant->due_on && $grant->due_on->between(today(), today()->addDays(90)));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'NGO', 'icon' => 'heart-handshake', 'stats' => [
                ['label' => 'Active grants', 'value' => (string) $funded->count()],
                ['label' => 'Funding', 'value' => $this->money($funded->sum('amount'))],
                ['label' => 'In pipeline', 'value' => $this->money($grants->whereIn('status', ['pipeline', 'applied'])->sum('amount'))],
                ['label' => 'Active beneficiaries', 'value' => (string) $beneficiaries->where('status', 'active')->count()],
                ['label' => 'Graduated this year', 'value' => (string) $beneficiaries->filter(fn (Record $beneficiary) => $beneficiary->status === 'graduated' && $beneficiary->value('_exited_on') && Carbon::parse($beneficiary->value('_exited_on'))->isCurrentYear())->count()],
                ['label' => 'Reports due', 'value' => (string) $reports->count(), 'tone' => $reports->isNotEmpty() ? 'warning' : null],
                ['label' => 'Ending in 90 days', 'value' => (string) $ending->count(), 'tone' => $ending->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reports due', 'icon' => 'file-text', 'empty' => 'No funder reports due in the next 30 days.',
                'rows' => $reports->take(10)->map(fn (Record $grant) => [
                    'label' => $grant->title, 'sub' => $grant->value('funder').' · '.ucfirst((string) $grant->value('report_frequency')), 'value' => Carbon::parse($grant->value('_next_report'))->format('d M'), 'href' => $grant->url(), 'tone' => Carbon::parse($grant->value('_next_report'))->lt(today()) ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $grants = $this->records('grants')->get();
        $byFunder = $grants->groupBy(fn (Record $grant) => $grant->value('funder') ?: 'Unknown funder')->sortKeys()->map(fn ($group, $funder) => [
            $funder, $group->count(), $group->whereIn('status', self::FUNDED)->count(), $this->money($group->whereIn('status', self::FUNDED)->sum('amount')), $this->money($group->where('status', 'closed')->sum('amount')), $group->sum(fn (Record $grant) => (int) $grant->value('_beneficiaries')),
        ])->values()->all();

        $beneficiaries = $this->dated('beneficiaries', $from, $to)->get();
        $byProgramme = $beneficiaries->groupBy(fn (Record $beneficiary) => $beneficiary->value('programme') ?: 'No programme')->sortKeys()->map(fn ($group, $programme) => [
            $programme, $group->count(), $group->where('data.sex', 'female')->count(), $group->where('data.sex', 'male')->count(), $group->where('status', 'active')->count(), $group->where('status', 'graduated')->count(), $group->where('status', 'exited')->count(),
        ])->values()->all();

        $byLocation = $beneficiaries->groupBy(fn (Record $beneficiary) => $beneficiary->value('location') ?: 'Not recorded')->sortKeys()->map(fn ($group, $location) => [
            $location, $group->count(), $group->where('status', 'active')->count(), $group->filter(fn (Record $beneficiary) => filled($beneficiary->value('age')))->avg(fn (Record $beneficiary) => (int) $beneficiary->value('age')) ? round($group->filter(fn (Record $beneficiary) => filled($beneficiary->value('age')))->avg(fn (Record $beneficiary) => (int) $beneficiary->value('age'))) : '—',
        ])->values()->all();

        return [
            ['title' => 'Grants by funder', 'columns' => ['Funder', 'Grants', 'Active', 'Active value', 'Closed value', 'Beneficiaries'], 'rows' => $byFunder],
            ['title' => 'Beneficiaries by programme', 'columns' => ['Programme', 'Enrolled', 'Female', 'Male', 'Active', 'Graduated', 'Exited'], 'rows' => $byProgramme],
            ['title' => 'Beneficiaries by location', 'columns' => ['Location', 'Enrolled', 'Active', 'Average age'], 'rows' => $byLocation],
        ];
    }
}
