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
 * Grants & subsidies: applications are taken only while a programme is open, one per applicant
 * per programme. An award never exceeds what was asked for or what is left in the programme's
 * budget. Disbursed grants owe a report by their report date, the programme closes itself after
 * its closing date and completes only once every grant on it is settled.
 */
class GrantsLogic extends AppLogic
{
    public const AWARDED = ['approved', 'disbursed', 'reporting', 'closed'];

    public const LIVE = ['submitted', 'screening', 'approved', 'disbursed', 'reporting'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'programmes') {
            if ((float) ($data['budget'] ?? 0) <= 0) {
                $errors['data.budget'] = 'Set the programme budget.';
            } elseif ($existing && (float) $data['budget'] < (float) $existing->value('_awarded')) {
                $errors['data.budget'] = 'More than this has already been awarded.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The programme closes after it opens.';
            }

            return $errors;
        }

        $programme = ! empty($data['programme']) ? $this->records('programmes')->find($data['programme']) : null;
        if ($programme && ! $existing) {
            if ($programme->status !== 'open' || ($programme->due_on && $programme->due_on->lt(today()))) {
                $errors['data.programme'] = $programme->title.' is not taking applications.';
            } elseif ($this->linked('applications', 'programme', $programme)->get()->contains(fn (Record $application) => strcasecmp(trim($application->title), trim((string) $payload['title'])) === 0)) {
                $errors['title'] = $payload['title'].' has already applied to '.$programme->title.'.';
            }
        }
        if ((float) ($data['requested'] ?? 0) < 0) {
            $errors['data.requested'] = 'The amount requested cannot be negative.';
        }
        if (filled($data['score'] ?? null) && ((float) $data['score'] < 0 || (float) $data['score'] > 100)) {
            $errors['data.score'] = 'Score out of 100.';
        }
        if (in_array($payload['status'], self::AWARDED, true) && $programme) {
            $amount = (float) ($payload['amount'] ?? 0);
            $left = $this->left($programme, $existing);
            if ($amount <= 0) {
                $errors['amount'] = 'Enter the amount awarded.';
            } elseif ((float) ($data['requested'] ?? 0) > 0 && $amount > (float) $data['requested']) {
                $errors['amount'] = 'The award is more than was requested.';
            } elseif ($amount > $left) {
                $errors['amount'] = 'Only '.$this->money($left).' is left in '.$programme->title.'.';
            }
        }

        return $errors;
    }

    /** The programme budget not yet awarded, leaving out the given application's own award. */
    protected function left(Record $programme, ?Record $except = null): float
    {
        $awarded = $this->linked('applications', 'programme', $programme)->whereIn('status', self::AWARDED)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->sum('amount');

        return round($this->number($programme, 'budget') - (float) $awarded, 2);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();

        if ($record->entity === 'applications') {
            $this->put($record, [
                '_disbursed_on' => in_array($record->status, ['disbursed', 'reporting', 'closed'], true) ? $record->value('_disbursed_on') : null,
                '_report_overdue' => in_array($record->status, ['disbursed', 'reporting'], true) && filled($record->value('report_due')) && Carbon::parse($record->value('report_due'))->lt(today()),
            ]);

            return;
        }

        $applications = $record->exists ? $this->linked('applications', 'programme', $record)->get() : collect();
        $awarded = $applications->whereIn('status', self::AWARDED);
        $this->put($record, [
            '_applications' => $applications->count(),
            '_pending' => $applications->whereIn('status', ['submitted', 'screening'])->count(),
            '_grants' => $awarded->count(),
            '_awarded' => round($awarded->sum('amount'), 2),
            '_disbursed' => round($applications->whereIn('status', ['disbursed', 'reporting', 'closed'])->sum('amount'), 2),
            '_remaining' => round($this->number($record, 'budget') - $awarded->sum('amount'), 2),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'applications') {
            $this->recalculate($this->parent($record, 'programme'));
            $this->recalculate($this->previousParent($record, 'programme'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'applications') {
            $this->recalculate($this->parent($record, 'programme'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('programmes')->where('status', 'open')->whereDate('due_on', '<', today())->get() as $programme) {
            $programme->update(['status' => 'closed']);
            $changed++;
        }
        foreach ($this->records('applications')->where('status', 'disbursed')->get() as $application) {
            if (filled($application->value('report_due')) && Carbon::parse($application->value('report_due'))->lte(today())) {
                $application->update(['status' => 'reporting']);
                $changed++;
            }
        }

        return $changed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'programmes') {
            return match ($record->status) {
                'open' => ['close' => ['label' => 'Close applications', 'icon' => 'lock']],
                'closed' => ['reopen' => ['label' => 'Reopen', 'icon' => 'lock-open', 'fields' => [['name' => 'due_on', 'label' => 'Closes on', 'type' => 'date', 'value' => today()->addMonth()->toDateString()]]], 'complete' => ['label' => 'Complete', 'icon' => 'check']],
                default => [],
            };
        }

        $reject = ['label' => 'Reject', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]];

        return match ($record->status) {
            'submitted' => ['screen' => ['label' => 'Screen', 'icon' => 'search'], 'reject' => $reject],
            'screening' => ['approve' => ['label' => 'Approve', 'icon' => 'check', 'fields' => [
                ['name' => 'amount', 'label' => 'Amount awarded', 'type' => 'number', 'value' => $record->value('requested')],
                ['name' => 'score', 'label' => 'Score', 'type' => 'number', 'value' => $record->value('score')],
            ]], 'reject' => $reject],
            'approved' => ['disburse' => ['label' => 'Disburse', 'icon' => 'banknote', 'fields' => [['name' => 'report_due', 'label' => 'Report due', 'type' => 'date', 'value' => today()->addMonths(6)->toDateString()]]]],
            'disbursed', 'reporting' => ['close' => ['label' => 'Report received', 'icon' => 'file-check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'programmes') {
            switch ($action) {
                case 'close':
                    $record->update(['status' => 'closed']);

                    return $record->title.' is closed to applications.';
                case 'reopen':
                    $due = Carbon::parse($request->validate(['due_on' => ['required', 'date', 'after:today']])['due_on']);
                    $record->update(['status' => 'open', 'due_on' => $due]);

                    return $record->title.' is open until '.$due->format('d M Y').'.';
            }

            $live = $this->linked('applications', 'programme', $record)->whereIn('status', self::LIVE)->count();
            if ($live > 0) {
                throw ValidationException::withMessages(['status' => $live.' applications on '.$record->title.' are not settled yet.']);
            }
            $record->update(['status' => 'completed']);

            return $record->title.' completed.';
        }

        switch ($action) {
            case 'screen':
                $record->update(['status' => 'screening']);

                return 'Screening '.$record->title.'.';
            case 'reject':
                $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
                $record->update(['status' => 'rejected', 'data' => [...$record->data, '_reason' => $reason]]);

                return $record->title.' rejected.';
            case 'approve':
                $input = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'score' => ['nullable', 'numeric', 'between:0,100']]);
                $programme = $this->parent($record, 'programme');
                $requested = $this->number($record, 'requested');
                if ($requested > 0 && (float) $input['amount'] > $requested) {
                    throw ValidationException::withMessages(['amount' => 'The award is more than was requested.']);
                }
                if ($programme && (float) $input['amount'] > $this->left($programme, $record)) {
                    throw ValidationException::withMessages(['amount' => 'Only '.$this->money($this->left($programme, $record)).' is left in '.$programme->title.'.']);
                }
                $record->update(['status' => 'approved', 'amount' => (float) $input['amount'], 'data' => [...$record->data, 'score' => $input['score'] ?? $record->value('score')]]);

                return $record->title.' approved.';
            case 'disburse':
                $due = Carbon::parse($request->validate(['report_due' => ['required', 'date', 'after:today']])['report_due']);
                $record->update(['status' => 'disbursed', 'data' => [...$record->data, 'report_due' => $due->toDateString(), '_disbursed_on' => today()->toDateString()]]);

                return 'Grant to '.$record->title.' disbursed; report due '.$due->format('d M Y').'.';
        }

        $record->update(['status' => 'closed']);

        return 'Report from '.$record->title.' received; grant closed.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'programmes') {
            return [];
        }

        $applications = $this->linked('applications', 'programme', $record)->get()->sortByDesc(fn (Record $application) => (float) $application->value('score'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Programme', 'icon' => 'folder-tree', 'stats' => [
                ['label' => 'Budget', 'value' => $this->money($this->number($record, 'budget'))],
                ['label' => 'Awarded', 'value' => $this->money((float) $record->value('_awarded')).' · '.(int) $record->value('_grants').' grants'],
                ['label' => 'Disbursed', 'value' => $this->money((float) $record->value('_disbursed'))],
                ['label' => 'Left', 'value' => $this->money((float) $record->value('_remaining')), 'tone' => (float) $record->value('_remaining') <= 0 ? 'warning' : 'success'],
                ['label' => 'Applications', 'value' => (int) $record->value('_applications').' · '.(int) $record->value('_pending').' waiting'],
                ['label' => 'Closes', 'value' => $record->due_on?->format('d M Y') ?? '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Applications', 'icon' => 'hand-coins', 'empty' => 'No applications yet.',
                'rows' => $applications->take(20)->map(fn (Record $application) => [
                    'label' => $application->title, 'sub' => ucfirst($application->status).(filled($application->value('score')) ? ' · score '.(float) $application->value('score') : ''), 'value' => in_array($application->status, self::AWARDED, true) ? $this->money($application->amount) : $this->money($this->number($application, 'requested')).' asked', 'href' => $application->url(), 'tone' => $application->status === 'rejected' ? 'danger' : (in_array($application->status, self::AWARDED, true) ? 'success' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $programmes = $this->records('programmes')->get();
        $applications = $this->records('applications')->get();
        $reports = $applications->whereIn('status', ['disbursed', 'reporting'])->filter(fn (Record $application) => filled($application->value('report_due')))->sortBy(fn (Record $application) => $application->value('report_due'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Grants', 'icon' => 'hand-coins', 'stats' => [
                ['label' => 'Open programmes', 'value' => (string) $programmes->where('status', 'open')->count()],
                ['label' => 'Waiting for screening', 'value' => (string) $applications->whereIn('status', ['submitted', 'screening'])->count()],
                ['label' => 'Awarded', 'value' => $this->money($applications->whereIn('status', self::AWARDED)->sum('amount'))],
                ['label' => 'Still to disburse', 'value' => $this->money($applications->where('status', 'approved')->sum('amount'))],
                ['label' => 'Reports overdue', 'value' => (string) $applications->filter(fn (Record $application) => $application->value('_report_overdue'))->count(), 'tone' => $applications->contains(fn (Record $application) => $application->value('_report_overdue')) ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reports due', 'icon' => 'file-clock', 'empty' => 'No grant reports are due.',
                'rows' => $reports->take(10)->map(fn (Record $application) => [
                    'label' => $application->title, 'sub' => $this->parent($application, 'programme')?->title, 'value' => Carbon::parse($application->value('report_due'))->format('d M Y'), 'href' => $application->url(), 'tone' => Carbon::parse($application->value('report_due'))->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $programmes = $this->records('programmes')->get()->map(fn (Record $programme) => [
            $programme->title, ucfirst($programme->status), (int) $programme->value('_applications'), (int) $programme->value('_grants'), $this->money($this->number($programme, 'budget')), $this->money((float) $programme->value('_awarded')), $this->money((float) $programme->value('_disbursed')), $this->money((float) $programme->value('_remaining')),
        ])->values()->all();

        $applications = $this->dated('applications', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($applications) {
            $group = $applications->filter(fn (Record $application) => $application->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->whereIn('status', self::AWARDED)->count(), $group->where('status', 'rejected')->count(), $this->money($group->sum(fn (Record $application) => (float) $application->value('requested'))), $this->money($group->whereIn('status', self::AWARDED)->sum('amount'))];
        })->values()->all();

        $statuses = ['submitted' => 'Submitted', 'screening' => 'Screening', 'approved' => 'Approved', 'rejected' => 'Rejected', 'disbursed' => 'Disbursed', 'reporting' => 'Report due', 'closed' => 'Closed'];
        $byStatus = collect($statuses)->map(fn (string $label, string $status) => [$label, $applications->where('status', $status)->count(), $this->money($applications->where('status', $status)->sum('amount'))])->values()->all();

        return [
            ['title' => 'Programmes', 'columns' => ['Programme', 'Status', 'Applications', 'Grants', 'Budget', 'Awarded', 'Disbursed', 'Left'], 'rows' => $programmes],
            ['title' => 'Applications by month', 'columns' => ['Month', 'Received', 'Awarded', 'Rejected', 'Requested', 'Awarded value'], 'rows' => $byMonth],
            ['title' => 'Applications by status', 'columns' => ['Status', 'Applications', 'Value'], 'rows' => $byStatus],
        ];
    }
}
