<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Health & safety incident reporting: an incident is reported on the day it happened or after,
 * serious and fatal incidents must be reported to the authority, and an investigation ends with a
 * root cause. Corrective actions are raised against the incident, each is done and then verified,
 * and the incident is closed only with its root cause recorded and no action still open.
 */
class IncidentReportingLogic extends AppLogic
{
    public const SERIOUS = ['serious', 'fatal'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'incidents') {
            if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['occurs_on'] = 'An incident is reported after it happened.';
            }
            if (in_array($data['severity'] ?? null, self::SERIOUS, true) && empty($data['reportable'])) {
                $errors['data.reportable'] = 'Serious and fatal incidents must be reported to the authority.';
            }
            if ($payload['status'] === 'closed') {
                if (blank($data['root_cause'] ?? null)) {
                    $errors['data.root_cause'] = 'Record the root cause before closing.';
                }
                if ($existing && ($open = $this->linked('actions', 'incident', $existing)->where('status', 'open')->count()) > 0) {
                    $errors['status'] = $open.' corrective actions are still open.';
                }
            }

            return $errors;
        }

        $incident = ! empty($data['incident']) ? $this->records('incidents')->find($data['incident']) : null;
        if ($incident && $incident->status === 'closed' && (! $existing || (int) $existing->value('incident') !== $incident->id)) {
            $errors['data.incident'] = 'Incident '.$incident->title.' is closed.';
        }
        if ($payload['status'] === 'verified' && (! $existing || $existing->status === 'open')) {
            $errors['status'] = 'An action is verified after it is done.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'actions') {
            $this->put($record, [
                '_done_on' => in_array($record->status, ['done', 'verified'], true) ? ($record->value('_done_on') ?? today()->toDateString()) : null,
                '_overdue' => $record->status === 'open' && $record->due_on?->lt(today()),
            ]);

            return;
        }

        $record->occurs_on ??= today();
        $actions = $record->exists ? $this->linked('actions', 'incident', $record)->get() : collect();
        $open = $actions->where('status', 'open');
        $this->put($record, [
            'reportable' => (bool) $record->value('reportable'),
            '_lost_time' => in_array($record->value('severity'), ['lost_time', ...self::SERIOUS], true),
            '_actions' => $actions->count(),
            '_open_actions' => $open->count(),
            '_overdue_actions' => $open->filter(fn (Record $action) => $action->due_on?->lt(today()))->count(),
            '_verified_actions' => $actions->where('status', 'verified')->count(),
            '_days_open' => $record->status === 'closed' ? null : (int) $record->occurs_on->diffInDays(today()),
            '_closed_on' => $record->status === 'closed' ? ($record->value('_closed_on') ?? today()->toDateString()) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'actions') {
            return;
        }

        $incident = $this->parent($record, 'incident');
        if ($incident && $record->status === 'open' && in_array($incident->status, ['reported', 'investigating'], true)) {
            $incident->update(['status' => 'actions_open']);
        } else {
            $this->recalculate($incident);
        }
        $this->recalculate($this->previousParent($record, 'incident'));
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'actions') {
            $this->recalculate($this->parent($record, 'incident'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'actions') {
            return match ($record->status) {
                'open' => ['done' => ['label' => 'Done', 'icon' => 'check']],
                'done' => ['verify' => ['label' => 'Verify', 'icon' => 'badge-check'], 'reopen' => ['label' => 'Reopen', 'icon' => 'undo']],
                default => [],
            };
        }

        $raise = ['label' => 'Raise action', 'icon' => 'list-plus', 'fields' => [
            ['name' => 'title', 'label' => 'Action', 'type' => 'text'],
            ['name' => 'due_on', 'label' => 'Due', 'type' => 'date', 'value' => today()->addDays(14)->toDateString()],
        ]];
        $close = ['label' => 'Close', 'icon' => 'folder-check', 'fields' => [['name' => 'root_cause', 'label' => 'Root cause', 'type' => 'textarea', 'value' => $record->value('root_cause')]]];

        return match ($record->status) {
            'reported' => ['investigate' => ['label' => 'Investigate', 'icon' => 'search'], 'raise_action' => $raise],
            'investigating', 'actions_open' => ['raise_action' => $raise, 'close' => $close],
            default => ['reopen' => ['label' => 'Reopen', 'icon' => 'undo']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'done':
                $record->update(['status' => 'done']);

                return $record->title.' done.';
            case 'verify':
                $record->update(['status' => 'verified']);

                return $record->title.' verified.';
            case 'investigate':
                $record->update(['status' => 'investigating']);

                return 'Investigating '.$record->title.'.';
            case 'raise_action':
                $input = $request->validate(['title' => ['required', 'string'], 'due_on' => ['required', 'date', 'after_or_equal:today']]);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'actions', 'title' => $input['title'], 'status' => 'open',
                    'due_on' => Carbon::parse($input['due_on']), 'assignee_id' => $record->assignee_id, 'data' => ['incident' => $record->id],
                ]);

                return 'Action "'.$input['title'].'" due '.Carbon::parse($input['due_on'])->format('d M Y').'.';
            case 'close':
                $rootCause = $request->validate(['root_cause' => ['required', 'string']])['root_cause'];
                $open = $this->linked('actions', 'incident', $record)->where('status', 'open')->count();
                if ($open > 0) {
                    throw ValidationException::withMessages(['status' => $open.' corrective actions are still open.']);
                }
                $record->update(['status' => 'closed', 'data' => [...$record->data, 'root_cause' => $rootCause]]);

                return 'Incident '.$record->title.' closed.';
        }

        $record->update(['status' => $record->entity === 'actions' ? 'open' : 'investigating']);

        return $record->title.' reopened.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'incidents') {
            return [];
        }

        $actions = $this->linked('actions', 'incident', $record)->orderBy('due_on')->get();
        $types = $this->app->entities['incidents']->field('type')?->options ?? [];
        $severities = $this->app->entities['incidents']->field('severity')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Incident', 'icon' => 'triangle-alert', 'stats' => [
                ['label' => 'Type', 'value' => $types[$record->value('type')] ?? ucfirst(str_replace('_', ' ', (string) $record->value('type')))],
                ['label' => 'Severity', 'value' => $severities[$record->value('severity')] ?? ucfirst(str_replace('_', ' ', (string) ($record->value('severity') ?: 'not set'))), 'tone' => in_array($record->value('severity'), self::SERIOUS, true) ? 'danger' : ($record->value('severity') === 'lost_time' ? 'warning' : null)],
                ['label' => 'Location', 'value' => (string) ($record->value('location') ?: '—')],
                ['label' => 'Reportable', 'value' => $record->value('reportable') ? 'Yes' : 'No'],
                ['label' => 'Days open', 'value' => $record->value('_days_open') === null ? 'Closed' : (string) (int) $record->value('_days_open')],
                ['label' => 'Actions', 'value' => (int) $record->value('_open_actions').' open of '.(int) $record->value('_actions'), 'tone' => (int) $record->value('_overdue_actions') > 0 ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Corrective actions', 'icon' => 'list-checks', 'empty' => 'No corrective actions raised.',
                'rows' => $actions->take(15)->map(fn (Record $item) => [
                    'label' => $item->title, 'sub' => $item->due_on ? 'Due '.$item->due_on->format('d M Y') : 'No due date', 'value' => ucfirst($item->status), 'href' => $item->url(), 'tone' => $item->status === 'verified' ? 'success' : ($item->value('_overdue') ? 'danger' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $incidents = $this->records('incidents')->get();
        $actions = $this->records('actions')->get();
        $overdue = $actions->filter(fn (Record $action) => $action->status === 'open' && $action->due_on?->lt(today()))->sortBy('due_on');
        $lastLostTime = $incidents->filter(fn (Record $incident) => $incident->value('_lost_time'))->sortByDesc('occurs_on')->first();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Safety', 'icon' => 'hard-hat', 'stats' => [
                ['label' => 'Incidents this month', 'value' => (string) $incidents->filter(fn (Record $incident) => $incident->occurs_on?->isCurrentMonth())->count()],
                ['label' => 'Lost-time this year', 'value' => (string) $incidents->filter(fn (Record $incident) => $incident->value('_lost_time') && $incident->occurs_on?->isCurrentYear())->count()],
                ['label' => 'Days since lost time', 'value' => $lastLostTime ? (string) (int) $lastLostTime->occurs_on->diffInDays(today()) : 'None recorded', 'tone' => 'success'],
                ['label' => 'Under investigation', 'value' => (string) $incidents->where('status', 'investigating')->count()],
                ['label' => 'Open actions', 'value' => (string) $actions->where('status', 'open')->count()],
                ['label' => 'Overdue actions', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'danger' : null],
                ['label' => 'Reportable this year', 'value' => (string) $incidents->filter(fn (Record $incident) => $incident->value('reportable') && $incident->occurs_on?->isCurrentYear())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue actions', 'icon' => 'alarm-clock', 'empty' => 'No corrective action is overdue.',
                'rows' => $overdue->take(10)->map(fn (Record $action) => [
                    'label' => $action->title, 'sub' => $incidents->firstWhere('id', (int) $action->value('incident'))?->title ?? '', 'value' => 'Due '.$action->due_on->format('d M Y'), 'href' => $action->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $incidents = $this->dated('incidents', $from, $to)->get();
        $types = $this->app->entities['incidents']->field('type')?->options ?? [];
        $byType = collect($types)->map(fn (string $label, string $type) => [
            $label, $incidents->where('data.type', $type)->count(), $incidents->where('data.type', $type)->filter(fn (Record $incident) => $incident->value('_lost_time'))->count(), $incidents->where('data.type', $type)->where('status', 'closed')->count(),
        ])->values()->all();

        $severities = $this->app->entities['incidents']->field('severity')?->options ?? [];
        $bySeverity = collect($severities)->map(fn (string $label, string $severity) => [
            $label, $incidents->where('data.severity', $severity)->count(), $incidents->where('data.severity', $severity)->filter(fn (Record $incident) => $incident->value('reportable'))->count(),
        ])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($incidents) {
            $group = $incidents->filter(fn (Record $incident) => $incident->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('data.type', 'near_miss')->count(), $group->filter(fn (Record $incident) => $incident->value('_lost_time'))->count(), $group->where('status', 'closed')->count()];
        })->values()->all();

        $actions = $this->records('actions')->get();
        $byStatus = collect(['open' => 'Open', 'done' => 'Done', 'verified' => 'Verified'])->map(fn (string $label, string $status) => [
            $label, $actions->where('status', $status)->count(), $status === 'open' ? $actions->where('status', 'open')->filter(fn (Record $action) => $action->value('_overdue'))->count() : 0,
        ])->values()->all();

        return [
            ['title' => 'Incidents by type', 'columns' => ['Type', 'Incidents', 'Lost time', 'Closed'], 'rows' => $byType],
            ['title' => 'Incidents by severity', 'columns' => ['Severity', 'Incidents', 'Reported to authority'], 'rows' => $bySeverity],
            ['title' => 'Incidents by month', 'columns' => ['Month', 'Incidents', 'Near misses', 'Lost time', 'Closed'], 'rows' => $byMonth],
            ['title' => 'Corrective actions', 'columns' => ['Status', 'Actions', 'Overdue'], 'rows' => $byStatus],
        ];
    }
}
