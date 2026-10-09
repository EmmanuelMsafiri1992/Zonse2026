<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Permits & licences: an application is received with a decision due in a month, goes under
 * review, is inspected when the type needs it, and is approved only once an inspection has passed.
 * A licence is issued with a unique number and an expiry date, and the office sees decisions that
 * are overdue and licences about to expire.
 */
class PermitsLogic extends AppLogic
{
    public const DECISION_DAYS = 30;

    public const DECIDED = ['approved', 'rejected', 'issued'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'applications') {
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'The fee cannot be negative.';
            }
            $number = strtoupper(trim((string) ($data['licence_number'] ?? '')));
            if ($number !== '' && $this->records('applications')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $application) => strtoupper(trim((string) $application->value('licence_number'))) === $number)) {
                $errors['data.licence_number'] = 'Licence '.$number.' has already been issued.';
            }
            if ($payload['status'] === 'issued') {
                if ($number === '') {
                    $errors['data.licence_number'] = 'Enter the licence number to issue it.';
                }
                if (filled($data['valid_until'] ?? null) && Carbon::parse($data['valid_until'])->lt(today())) {
                    $errors['data.valid_until'] = 'The licence would already have expired.';
                }
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The decision is due after the application was received.';
            }

            return $errors;
        }

        $application = ! empty($data['application']) ? $this->records('applications')->find($data['application']) : null;
        if ($application && in_array($application->status, ['rejected', 'issued'], true) && (! $existing || (int) $existing->value('application') !== $application->id)) {
            $errors['data.application'] = $application->title.' is already '.$application->status.'.';
        }
        if (in_array($payload['status'], ['passed', 'failed'], true) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['status'] = 'The inspection has not happened yet.';
        }
        if ($payload['status'] === 'failed' && blank($data['findings'] ?? null)) {
            $errors['data.findings'] = 'Record what failed the inspection.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'inspections') {
            $record->occurs_on ??= today();
            $this->put($record, ['_done_on' => in_array($record->status, ['passed', 'failed'], true) ? ($record->value('_done_on') ?? today()->toDateString()) : null]);

            return;
        }

        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDays(self::DECISION_DAYS);
        $inspections = $record->exists ? $this->linked('inspections', 'application', $record)->get() : collect();
        $last = $inspections->whereIn('status', ['passed', 'failed'])->sortByDesc('occurs_on')->first();
        $validUntil = filled($record->value('valid_until')) ? Carbon::parse($record->value('valid_until')) : null;
        $decided = in_array($record->status, self::DECIDED, true);
        $this->put($record, [
            'licence_number' => strtoupper(trim((string) $record->value('licence_number'))) ?: null,
            '_inspections' => $inspections->count(),
            '_passed' => $last?->status === 'passed',
            '_last_inspection' => $last?->occurs_on?->toDateString(),
            '_next_inspection' => $inspections->where('status', 'scheduled')->sortBy('occurs_on')->first()?->occurs_on?->toDateString(),
            '_overdue' => ! $decided && $record->due_on?->lt(today()),
            '_decided_on' => $decided ? ($record->value('_decided_on') ?? today()->toDateString()) : null,
            '_issued_on' => $record->status === 'issued' ? ($record->value('_issued_on') ?? today()->toDateString()) : null,
            '_expired' => $record->status === 'issued' && $validUntil?->lt(today()),
            '_days_left' => $record->status === 'issued' && $validUntil ? (int) today()->diffInDays($validUntil, false) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'inspections') {
            return;
        }

        $application = $this->parent($record, 'application');
        if ($application && $record->status === 'passed' && $application->status === 'inspection') {
            $application->update(['status' => 'approved']);
        } else {
            $this->recalculate($application);
        }
        $this->recalculate($this->previousParent($record, 'application'));
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'inspections') {
            $this->recalculate($this->parent($record, 'application'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'inspections') {
            return match ($record->status) {
                'scheduled' => [
                    'pass' => ['label' => 'Passed', 'icon' => 'check', 'fields' => [['name' => 'findings', 'label' => 'Findings', 'type' => 'textarea', 'value' => $record->value('findings')]]],
                    'fail' => ['label' => 'Failed', 'icon' => 'x', 'fields' => [['name' => 'findings', 'label' => 'Findings', 'type' => 'textarea', 'value' => $record->value('findings')]]],
                ],
                'failed' => ['reinspect' => ['label' => 'Schedule re-inspection', 'icon' => 'calendar-plus', 'fields' => [['name' => 'occurs_on', 'label' => 'Date', 'type' => 'date', 'value' => today()->addDays(7)->toDateString()]]]],
                default => [],
            };
        }

        $inspect = ['label' => 'Schedule inspection', 'icon' => 'clipboard-check', 'fields' => [['name' => 'occurs_on', 'label' => 'Date', 'type' => 'date', 'value' => today()->addDays(7)->toDateString()]]];
        $reject = ['label' => 'Reject', 'icon' => 'x', 'confirm' => 'Reject '.$record->title.'?'];

        return match ($record->status) {
            'received' => ['review' => ['label' => 'Start review', 'icon' => 'search'], 'reject' => $reject],
            'under_review' => ['inspect' => $inspect, 'approve' => ['label' => 'Approve', 'icon' => 'check'], 'reject' => $reject],
            'inspection' => ['inspect' => $inspect, 'approve' => ['label' => 'Approve', 'icon' => 'check'], 'reject' => $reject],
            'approved' => ['issue' => ['label' => 'Issue licence', 'icon' => 'stamp', 'fields' => [
                ['name' => 'licence_number', 'label' => 'Licence number', 'type' => 'text', 'value' => $record->value('licence_number')],
                ['name' => 'valid_until', 'label' => 'Valid until', 'type' => 'date', 'value' => $record->value('valid_until') ?: today()->addYear()->toDateString()],
            ]]],
            'rejected' => ['reopen' => ['label' => 'Reopen', 'icon' => 'undo']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'pass':
            case 'fail':
                $findings = $request->validate(['findings' => [$action === 'fail' ? 'required' : 'nullable', 'string']])['findings'] ?? $record->value('findings');
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'The inspection has not happened yet.']);
                }
                $record->update(['status' => $action === 'pass' ? 'passed' : 'failed', 'data' => [...$record->data, 'findings' => $findings]]);

                return $action === 'pass' ? 'Inspection of '.$record->title.' passed.' : 'Inspection of '.$record->title.' failed; a re-inspection is needed.';
            case 'reinspect':
            case 'inspect':
                $day = Carbon::parse($request->validate(['occurs_on' => ['required', 'date', 'after_or_equal:today']])['occurs_on']);
                $application = $action === 'inspect' ? $record : $this->parent($record, 'application');
                if (! $application) {
                    throw ValidationException::withMessages(['occurs_on' => 'This inspection has no application.']);
                }
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'inspections', 'title' => $application->value('property') ?: $application->title, 'status' => 'scheduled',
                    'occurs_on' => $day, 'assignee_id' => $record->assignee_id, 'data' => ['application' => $application->id],
                ]);
                if ($action === 'reinspect') {
                    $record->update(['status' => 're_inspect']);
                } else {
                    $record->update(['status' => 'inspection']);
                }

                return ($action === 'reinspect' ? 'Re-inspection' : 'Inspection').' of '.($application->value('property') ?: $application->title).' scheduled for '.$day->format('d M Y').'.';
            case 'review':
                $record->update(['status' => 'under_review']);

                return 'Reviewing '.$record->title.'.';
            case 'approve':
                if ($record->status === 'inspection' && ! $record->value('_passed')) {
                    throw ValidationException::withMessages(['status' => 'No inspection has passed yet.']);
                }
                $record->update(['status' => 'approved']);

                return $record->title.' approved.';
            case 'reject':
                $record->update(['status' => 'rejected']);

                return $record->title.' rejected.';
            case 'issue':
                $input = $request->validate(['licence_number' => ['required', 'string'], 'valid_until' => ['required', 'date', 'after:today']]);
                $number = strtoupper(trim($input['licence_number']));
                if ($this->records('applications')->whereKeyNot($record->id)->get()->contains(fn (Record $other) => strtoupper(trim((string) $other->value('licence_number'))) === $number)) {
                    throw ValidationException::withMessages(['licence_number' => 'Licence '.$number.' has already been issued.']);
                }
                $record->update(['status' => 'issued', 'data' => [...$record->data, 'licence_number' => $number, 'valid_until' => $input['valid_until']]]);

                return 'Licence '.$number.' issued to '.$record->title.', valid until '.Carbon::parse($input['valid_until'])->format('d M Y').'.';
        }

        $record->update(['status' => 'under_review']);

        return $record->title.' is under review again.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'applications') {
            return [];
        }

        $inspections = $this->linked('inspections', 'application', $record)->orderByDesc('occurs_on')->get();
        $types = $this->app->entities['applications']->field('type')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Application', 'icon' => 'stamp', 'stats' => [
                ['label' => 'Type', 'value' => $types[$record->value('type')] ?? ucfirst(str_replace('_', ' ', (string) $record->value('type')))],
                ['label' => 'Fee', 'value' => $this->money($record->amount)],
                ['label' => 'Received', 'value' => $record->occurs_on?->format('d M Y') ?? '—'],
                ['label' => 'Decision due', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->value('_overdue') ? 'danger' : null],
                ['label' => 'Inspections', 'value' => (int) $record->value('_inspections').($record->value('_passed') ? ' · passed' : '')],
                ['label' => 'Licence', 'value' => $record->value('licence_number') ?: 'Not issued'],
                ['label' => 'Valid until', 'value' => filled($record->value('valid_until')) ? Carbon::parse($record->value('valid_until'))->format('d M Y') : '—', 'tone' => $record->value('_expired') ? 'danger' : ($record->value('_days_left') !== null && (int) $record->value('_days_left') <= 30 ? 'warning' : null)],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Inspections', 'icon' => 'clipboard-check', 'empty' => 'No inspections yet.',
                'rows' => $inspections->take(10)->map(fn (Record $inspection) => [
                    'label' => $inspection->title, 'sub' => ($inspection->occurs_on?->format('d M Y') ?? '').($inspection->value('findings') ? ' · '.str($inspection->value('findings'))->limit(60) : ''), 'value' => ucfirst(str_replace('_', ' ', $inspection->status)), 'href' => $inspection->url(), 'tone' => $inspection->status === 'passed' ? 'success' : ($inspection->status === 'failed' ? 'danger' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $applications = $this->records('applications')->get();
        $open = $applications->whereNotIn('status', self::DECIDED);
        $overdue = $open->filter(fn (Record $application) => $application->value('_overdue'))->sortBy('due_on');
        $expiring = $applications->filter(fn (Record $application) => $application->status === 'issued' && $application->value('_days_left') !== null && (int) $application->value('_days_left') <= 30);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Permits', 'icon' => 'stamp', 'stats' => [
                ['label' => 'Received', 'value' => (string) $applications->where('status', 'received')->count()],
                ['label' => 'Under review', 'value' => (string) $applications->where('status', 'under_review')->count()],
                ['label' => 'Awaiting inspection', 'value' => (string) $applications->where('status', 'inspection')->count()],
                ['label' => 'Decisions overdue', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'danger' : null],
                ['label' => 'Issued this year', 'value' => (string) $applications->filter(fn (Record $application) => $application->status === 'issued' && filled($application->value('_issued_on')) && Carbon::parse($application->value('_issued_on'))->isCurrentYear())->count()],
                ['label' => 'Expiring in 30 days', 'value' => (string) $expiring->count(), 'tone' => $expiring->isNotEmpty() ? 'warning' : null],
                ['label' => 'Fees this month', 'value' => $this->money($applications->filter(fn (Record $application) => $application->occurs_on?->isCurrentMonth())->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Decisions overdue', 'icon' => 'alarm-clock', 'empty' => 'Every application is within its decision time.',
                'rows' => $overdue->take(10)->map(fn (Record $application) => [
                    'label' => $application->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $application->value('type'))).' · '.ucfirst(str_replace('_', ' ', $application->status)), 'value' => 'Due '.$application->due_on->format('d M Y'), 'href' => $application->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $applications = $this->dated('applications', $from, $to)->get();
        $types = $this->app->entities['applications']->field('type')?->options ?? [];
        $byType = collect($types)->map(fn (string $label, string $type) => [
            $label, $applications->where('data.type', $type)->count(), $applications->where('data.type', $type)->where('status', 'issued')->count(), $applications->where('data.type', $type)->where('status', 'rejected')->count(), $this->money($applications->where('data.type', $type)->sum('amount')),
        ])->filter(fn (array $row) => $row[1] > 0)->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($applications) {
            $group = $applications->filter(fn (Record $application) => $application->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'issued')->count(), $group->filter(fn (Record $application) => $application->value('_overdue'))->count(), $this->money($group->sum('amount'))];
        })->values()->all();

        $inspections = $this->dated('inspections', $from, $to)->get();
        $byOutcome = collect(['scheduled' => 'Scheduled', 'passed' => 'Passed', 'failed' => 'Failed', 're_inspect' => 'Re-inspection'])->map(fn (string $label, string $status) => [$label, $inspections->where('status', $status)->count()])->values()->all();

        return [
            ['title' => 'Applications by type', 'columns' => ['Type', 'Applications', 'Issued', 'Rejected', 'Fees'], 'rows' => $byType],
            ['title' => 'Applications by month', 'columns' => ['Month', 'Received', 'Issued', 'Overdue', 'Fees'], 'rows' => $byMonth],
            ['title' => 'Inspections by outcome', 'columns' => ['Outcome', 'Inspections'], 'rows' => $byOutcome],
        ];
    }
}
