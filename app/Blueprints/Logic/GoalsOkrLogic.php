<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Goals & OKRs: each key result's progress runs from its start value to its target (falling
 * targets work too) and it is marked achieved when it gets there. An objective's progress is the
 * average of its key results, it can only be marked achieved once they all are, and objectives
 * that are behind as their due date nears are flagged at risk each night.
 */
class GoalsOkrLogic extends AppLogic
{
    public const CLOSED = ['achieved', 'dropped'];

    /** Objectives due within this many days must be at least this far along to stay on track. */
    public const RISK_WINDOW_DAYS = 14;

    public const RISK_PROGRESS = 70;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'objectives') {
            if ($payload['status'] === 'achieved' && $existing) {
                $open = $this->linked('key_results', 'objective', $existing)->where('status', '!=', 'achieved')->count();
                if ($open > 0) {
                    $errors['status'] = $open.' key '.str('result')->plural($open).' still to achieve.';
                }
            }
            if (($data['level'] ?? null) === 'team' && blank($data['team'] ?? null)) {
                $errors['data.team'] = 'Say which team owns this objective.';
            }

            return $errors;
        }

        if (filled($data['start_value'] ?? null) && (float) $data['start_value'] === (float) ($data['target_value'] ?? 0)) {
            $errors['data.target_value'] = 'The target must differ from the start value.';
        }
        $objective = ! empty($data['objective']) ? $this->records('objectives')->find($data['objective']) : null;
        if ($objective && in_array($objective->status, self::CLOSED, true) && (! $existing || (int) $existing->value('objective') !== $objective->id)) {
            $errors['data.objective'] = $objective->title.' is '.$objective->status.'.';
        }

        return $errors;
    }

    /** Progress from start to target, 0–100. */
    public function progress(Record $keyResult): float
    {
        $start = $this->number($keyResult, 'start_value');
        $target = $this->number($keyResult, 'target_value');
        $current = $keyResult->value('current_value') === null ? $start : $this->number($keyResult, 'current_value');
        if ($target === $start) {
            return 0;
        }

        return round(max(0, min(100, ($current - $start) / ($target - $start) * 100)), 1);
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'key_results') {
            $progress = $this->progress($record);
            $this->put($record, ['_progress' => $progress]);
            if ($progress >= 100) {
                $record->status = 'achieved';
            } elseif ($record->status === 'achieved') {
                $record->status = 'on_track';
            }

            return;
        }

        $results = $record->exists ? $this->linked('key_results', 'objective', $record)->get() : collect();
        $this->put($record, ['_key_results' => $results->count(), '_progress' => $results->isEmpty() ? 0 : round($results->avg(fn (Record $result) => (float) $result->value('_progress')), 1)]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'key_results') {
            $this->recalculate($this->parent($record, 'objective'));
            $this->recalculate($this->previousParent($record, 'objective'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'key_results') {
            $this->recalculate($this->parent($record, 'objective'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $flagged = 0;
        $due = $this->records('objectives')->where('status', 'on_track')->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(self::RISK_WINDOW_DAYS))->get();
        foreach ($due as $objective) {
            if ((float) $objective->value('_progress') < self::RISK_PROGRESS) {
                $objective->update(['status' => $objective->due_on->lt(today()) ? 'off_track' : 'at_risk']);
                $flagged++;
            }
        }

        return $flagged;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'objectives') {
            return [];
        }

        $results = $this->linked('key_results', 'objective', $record)->orderBy('title')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Progress', 'icon' => 'goal', 'stats' => [
                ['label' => 'Overall', 'value' => (float) $record->value('_progress').'%'],
                ['label' => 'Key results achieved', 'value' => $results->where('status', 'achieved')->count().' of '.$results->count()],
                ['label' => 'Due', 'value' => $record->due_on?->format('d M Y') ?? '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Key results', 'icon' => 'target', 'empty' => 'Add the key results that measure this objective.',
                'rows' => $results->map(fn (Record $result) => [
                    'label' => $result->title, 'sub' => ($result->value('current_value') ?? $result->value('start_value') ?? 0).' → '.$result->value('target_value'),
                    'value' => (float) $result->value('_progress').'%', 'href' => $result->url(),
                    'tone' => match ($result->status) {
                        'achieved' => 'success', 'at_risk' => 'warning', 'off_track' => 'danger', default => null
                    },
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $objectives = $this->records('objectives')->where('status', '!=', 'dropped')->get();
        $open = $objectives->where('status', '!=', 'achieved');
        $risky = $open->whereIn('status', ['at_risk', 'off_track']);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Objectives', 'icon' => 'goal', 'stats' => [
                ['label' => 'Average progress', 'value' => $open->isEmpty() ? '—' : round($open->avg(fn (Record $objective) => (float) $objective->value('_progress'))).'%'],
                ['label' => 'Achieved', 'value' => (string) $objectives->where('status', 'achieved')->count(), 'tone' => 'success'],
                ['label' => 'At risk or off track', 'value' => (string) $risky->count(), 'tone' => $risky->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Objectives needing attention', 'icon' => 'triangle-alert', 'empty' => 'Everything is on track.',
                'rows' => $risky->sortBy('due_on')->map(fn (Record $objective) => [
                    'label' => $objective->title, 'sub' => $objective->assignee?->name, 'value' => (float) $objective->value('_progress').'%', 'href' => $objective->url(),
                    'tone' => $objective->status === 'off_track' ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $objectives = $this->records('objectives')->where('status', '!=', 'dropped')->orderBy('title')->get();

        $rows = $objectives->map(fn (Record $objective) => [
            $objective->title, ucfirst((string) $objective->value('level')), (string) ($objective->value('period') ?? '—'), (int) $objective->value('_key_results'),
            (float) $objective->value('_progress').'%', ucfirst(str_replace('_', ' ', $objective->status)),
        ])->all();

        $levels = $objectives->groupBy(fn (Record $objective) => ucfirst((string) $objective->value('level')).($objective->value('team') ? ' — '.$objective->value('team') : ''))->sortKeys()
            ->map(fn ($group, $owner) => [$owner, $group->count(), $group->where('status', 'achieved')->count(), round($group->avg(fn (Record $objective) => (float) $objective->value('_progress'))).'%'])
            ->values()->all();

        return [
            ['title' => 'Progress by objective', 'columns' => ['Objective', 'Level', 'Period', 'Key results', 'Progress', 'Status'], 'rows' => $rows],
            ['title' => 'Progress by team', 'columns' => ['Level / team', 'Objectives', 'Achieved', 'Average progress'], 'rows' => $levels],
        ];
    }
}
