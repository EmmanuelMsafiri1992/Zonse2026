<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Citizen service desk: every request gets a service-level due date from its type when none is
 * given, is assigned to a department, worked on and resolved with a written resolution, and is
 * escalated by itself once it passes its due date unresolved. The desk tracks how many requests
 * were resolved within their service level.
 */
class CitizenServiceDeskLogic extends AppLogic
{
    /** @var array<string, int> */
    public const SLA_DAYS = ['complaint' => 5, 'service_request' => 10, 'enquiry' => 2, 'compliment' => 1, 'fault_report' => 3];

    public const OPEN = ['received', 'assigned', 'in_progress', 'escalated'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['occurs_on'] = 'A request is logged when it is received, not before.';
        }
        if (in_array($payload['status'], ['resolved', 'closed'], true) && blank($data['resolution'] ?? null)) {
            $errors['data.resolution'] = 'Write what was done to resolve it.';
        }
        if (in_array($payload['status'], ['assigned', 'in_progress'], true) && blank($data['department'] ?? null)) {
            $errors['data.department'] = 'Choose the department handling it.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $sla = self::SLA_DAYS[$record->value('type')] ?? 7;
        $record->due_on ??= $record->occurs_on->copy()->addDays($sla);
        $open = in_array($record->status, self::OPEN, true);
        $resolvedOn = in_array($record->status, ['resolved', 'closed'], true) ? ($record->value('_resolved_on') ?? today()->toDateString()) : null;
        $this->put($record, [
            '_sla_days' => $sla,
            '_breached' => $open && $record->due_on->lt(today()),
            '_resolved_on' => $resolvedOn,
            '_days_to_resolve' => $resolvedOn ? (int) $record->occurs_on->diffInDays(Carbon::parse($resolvedOn)) : null,
            '_within_sla' => $resolvedOn ? Carbon::parse($resolvedOn)->lte($record->due_on) : null,
            '_escalated_on' => $record->status === 'escalated' ? ($record->value('_escalated_on') ?? today()->toDateString()) : $record->value('_escalated_on'),
        ]);
    }

    public function daily(Workspace $workspace): int
    {
        $escalated = 0;
        foreach ($this->records('requests')->whereIn('status', ['received', 'assigned', 'in_progress'])->whereDate('due_on', '<', today())->get() as $request) {
            $request->update(['status' => 'escalated']);
            $escalated++;
        }

        return $escalated;
    }

    public function actions(Record $record): array
    {
        $assign = ['label' => 'Assign', 'icon' => 'user-check', 'fields' => [['name' => 'department', 'label' => 'Department', 'type' => 'text', 'value' => $record->value('department')]]];
        $resolve = ['label' => 'Resolve', 'icon' => 'check', 'fields' => [['name' => 'resolution', 'label' => 'Resolution', 'type' => 'textarea', 'value' => $record->value('resolution')]]];
        $escalate = ['label' => 'Escalate', 'icon' => 'arrow-up', 'confirm' => 'Escalate '.$record->title.'?'];

        return match ($record->status) {
            'received' => ['assign' => $assign, 'resolve' => $resolve],
            'assigned' => ['start' => ['label' => 'Start work', 'icon' => 'play'], 'resolve' => $resolve, 'escalate' => $escalate],
            'in_progress' => ['resolve' => $resolve, 'escalate' => $escalate],
            'escalated' => ['assign' => $assign, 'resolve' => $resolve],
            'resolved' => ['close' => ['label' => 'Close', 'icon' => 'folder-check'], 'reopen' => ['label' => 'Reopen', 'icon' => 'undo']],
            default => ['reopen' => ['label' => 'Reopen', 'icon' => 'undo']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'assign':
                $department = $request->validate(['department' => ['required', 'string']])['department'];
                $record->update(['status' => $record->status === 'escalated' ? 'escalated' : 'assigned', 'data' => [...$record->data, 'department' => $department]]);

                return $record->title.' assigned to '.$department.'.';
            case 'start':
                $record->update(['status' => 'in_progress']);

                return 'Work on '.$record->title.' started.';
            case 'resolve':
                $resolution = $request->validate(['resolution' => ['required', 'string']])['resolution'];
                $record->update(['status' => 'resolved', 'data' => [...$record->data, 'resolution' => $resolution]]);
                $record = $record->fresh();
                $late = (int) $record->due_on->diffInDays(today());

                return $record->value('_within_sla') ? $record->title.' resolved within its service level.' : $record->title.' resolved '.$late.' days past its service level.';
            case 'escalate':
                $record->update(['status' => 'escalated']);

                return $record->title.' escalated.';
            case 'close':
                $record->update(['status' => 'closed']);

                return $record->title.' closed.';
        }

        $record->update(['status' => filled($record->value('department')) ? 'assigned' : 'received', 'data' => [...$record->data, '_resolved_on' => null]]);

        return $record->title.' reopened.';
    }

    public function recordCards(Record $record): array
    {
        $types = $this->app->entities['requests']->field('type')?->options ?? [];
        $channels = $this->app->entities['requests']->field('channel')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Request', 'icon' => 'messages-square', 'stats' => [
                ['label' => 'Type', 'value' => $types[$record->value('type')] ?? ucfirst(str_replace('_', ' ', (string) $record->value('type')))],
                ['label' => 'Channel', 'value' => $channels[$record->value('channel')] ?? ucfirst(str_replace('_', ' ', (string) ($record->value('channel') ?: '—')))],
                ['label' => 'Department', 'value' => (string) ($record->value('department') ?: 'Unassigned')],
                ['label' => 'Ward', 'value' => (string) ($record->value('ward') ?: '—')],
                ['label' => 'Received', 'value' => $record->occurs_on?->format('d M Y') ?? '—'],
                ['label' => 'Service level', 'value' => (int) $record->value('_sla_days').' days, due '.$record->due_on?->format('d M Y'), 'tone' => $record->value('_breached') ? 'danger' : null],
                ['label' => 'Resolved', 'value' => $record->value('_resolved_on') ? Carbon::parse($record->value('_resolved_on'))->format('d M Y').($record->value('_within_sla') ? ' · within SLA' : ' · late') : 'Open', 'tone' => $record->value('_resolved_on') ? ($record->value('_within_sla') ? 'success' : 'warning') : null],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $requests = $this->records('requests')->get();
        $open = $requests->whereIn('status', self::OPEN);
        $breached = $open->filter(fn (Record $request) => $request->value('_breached'))->sortBy('due_on');
        $resolvedThisMonth = $requests->filter(fn (Record $request) => filled($request->value('_resolved_on')) && Carbon::parse($request->value('_resolved_on'))->isCurrentMonth());
        $withinSla = $resolvedThisMonth->isEmpty() ? null : (int) round($resolvedThisMonth->filter(fn (Record $request) => $request->value('_within_sla'))->count() / $resolvedThisMonth->count() * 100);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Service desk', 'icon' => 'messages-square', 'stats' => [
                ['label' => 'Open', 'value' => (string) $open->count()],
                ['label' => 'Received today', 'value' => (string) $requests->filter(fn (Record $request) => $request->occurs_on?->isToday())->count()],
                ['label' => 'Past service level', 'value' => (string) $breached->count(), 'tone' => $breached->isNotEmpty() ? 'danger' : null],
                ['label' => 'Escalated', 'value' => (string) $open->where('status', 'escalated')->count(), 'tone' => $open->where('status', 'escalated')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Resolved this month', 'value' => (string) $resolvedThisMonth->count()],
                ['label' => 'Within service level', 'value' => $withinSla === null ? '—' : $withinSla.'%', 'tone' => $withinSla === null ? null : ($withinSla >= 80 ? 'success' : 'warning')],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Past service level', 'icon' => 'alarm-clock', 'empty' => 'Every open request is within its service level.',
                'rows' => $breached->take(10)->map(fn (Record $request) => [
                    'label' => $request->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $request->value('type'))).' · '.($request->value('department') ?: 'Unassigned').($request->value('ward') ? ' · '.$request->value('ward') : ''), 'value' => 'Due '.$request->due_on->format('d M Y'), 'href' => $request->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $requests = $this->dated('requests', $from, $to)->get();
        $slaRow = function (string $label, $group) {
            $resolved = $group->filter(fn (Record $request) => filled($request->value('_resolved_on')));
            $within = $resolved->isEmpty() ? '—' : (int) round($resolved->filter(fn (Record $request) => $request->value('_within_sla'))->count() / $resolved->count() * 100).'%';

            return [$label, $group->count(), $resolved->count(), $within, $resolved->isEmpty() ? '—' : round($resolved->avg(fn (Record $request) => (int) $request->value('_days_to_resolve')), 1)];
        };
        $types = $this->app->entities['requests']->field('type')?->options ?? [];
        $byType = collect($types)->map(fn (string $label, string $type) => $slaRow($label, $requests->where('data.type', $type)))->values()->all();
        $byDepartment = $requests->groupBy(fn (Record $request) => $request->value('department') ?: 'Unassigned')->sortKeys()->map(fn ($group, $department) => $slaRow($department, $group))->values()->all();
        $byWard = $requests->groupBy(fn (Record $request) => $request->value('ward') ?: 'Unknown')->sortKeys()->map(fn ($group, $ward) => [
            $ward, $group->count(), $group->where('data.type', 'complaint')->count(), $group->where('data.type', 'fault_report')->count(), $group->filter(fn (Record $request) => filled($request->value('_resolved_on')))->count(),
        ])->values()->all();
        $byMonth = collect($this->months($from, $to))->map(fn (string $label, string $month) => $slaRow($label, $requests->filter(fn (Record $request) => $request->occurs_on?->format('Y-m') === $month)))->values()->all();

        return [
            ['title' => 'Requests by type', 'columns' => ['Type', 'Received', 'Resolved', 'Within service level', 'Average days'], 'rows' => $byType],
            ['title' => 'Requests by department', 'columns' => ['Department', 'Received', 'Resolved', 'Within service level', 'Average days'], 'rows' => $byDepartment],
            ['title' => 'Requests by ward', 'columns' => ['Ward', 'Received', 'Complaints', 'Fault reports', 'Resolved'], 'rows' => $byWard],
            ['title' => 'Requests by month', 'columns' => ['Month', 'Received', 'Resolved', 'Within service level', 'Average days'], 'rows' => $byMonth],
        ];
    }
}
