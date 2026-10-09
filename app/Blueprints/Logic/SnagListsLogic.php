<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Snag lists: snags are raised against an inspection that has not been handed over, must be fixed
 * before they are verified, and cannot be due before the inspection. Inspections count their snags
 * and only hand over once every snag is verified.
 */
class SnagListsLogic extends AppLogic
{
    public const ORDER = ['open' => 0, 'fixed' => 1, 'verified' => 2];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'inspections') {
            if ($payload['status'] === 'handed_over' && $existing) {
                $pending = $this->linked('snags', 'inspection', $existing)->where('status', '!=', 'verified')->count();
                if ($pending > 0) {
                    $errors['status'] = $pending.' '.str('snag')->plural($pending).' still '.($pending === 1 ? 'needs' : 'need').' fixing or verifying before handover.';
                }
            }

            return $errors;
        }

        $inspection = ! empty($data['inspection']) ? $this->records('inspections')->find($data['inspection']) : null;
        if ($inspection?->status === 'handed_over' && (! $existing || (int) $existing->value('inspection') !== $inspection->id)) {
            $errors['data.inspection'] = $inspection->title.' has been handed over. Raise a new inspection for it.';
        }
        if ($inspection?->occurs_on && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt($inspection->occurs_on)) {
            $errors['due_on'] = 'The fix-by date cannot be before the inspection on '.$inspection->occurs_on->format('d M Y').'.';
        }
        if ($payload['status'] === 'verified' && (! $existing || $existing->status === 'open')) {
            $errors['status'] = 'Mark the snag fixed before verifying it.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'snags') {
            $fixed = in_array($record->status, ['fixed', 'verified'], true);
            $this->put($record, [
                '_fixed_on' => $fixed ? ($record->value('_fixed_on') ?? today()->toDateString()) : null,
                '_verified_on' => $record->status === 'verified' ? ($record->value('_verified_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $record->occurs_on ??= today();
        $snags = $record->exists ? $this->linked('snags', 'inspection', $record)->get() : collect();
        $verified = $snags->where('status', 'verified')->count();
        $this->put($record, [
            '_snags' => $snags->count(), '_open' => $snags->where('status', 'open')->count(), '_verified' => $verified,
            '_progress' => $snags->isEmpty() ? 100 : round($verified / $snags->count() * 100),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'snags') {
            $this->recalculate($this->parent($record, 'inspection'));
            $this->recalculate($this->previousParent($record, 'inspection'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'snags') {
            $this->recalculate($this->parent($record, 'inspection'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'inspections') {
            return $record->status === 'done' && (int) $record->value('_progress') === 100
                ? ['hand_over' => ['label' => 'Hand over', 'icon' => 'key-round', 'confirm' => 'Hand over '.$record->title.'? Every snag is verified.']]
                : [];
        }

        return match ($record->status) {
            'open' => ['mark_fixed' => ['label' => 'Fixed', 'icon' => 'hammer']],
            'fixed' => [
                'verify' => ['label' => 'Verify', 'icon' => 'check-check'],
                'reopen' => ['label' => 'Reopen', 'icon' => 'rotate-ccw'],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $status = match ($action) {
            'hand_over' => 'handed_over',
            'mark_fixed' => 'fixed',
            'verify' => 'verified',
            default => 'open',
        };
        $record->update(['status' => $status]);

        return $record->title.' is '.str_replace('_', ' ', $status).'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'inspections') {
            return [];
        }

        $snags = $this->linked('snags', 'inspection', $record)->with('assignee')->get()->sortBy(fn (Record $snag) => (self::ORDER[$snag->status] ?? 9).($snag->due_on?->toDateString() ?? '9'));
        $progress = (int) $record->value('_progress');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Snags', 'icon' => 'list-checks', 'stats' => [
                ['label' => 'Raised', 'value' => (string) (int) $record->value('_snags')],
                ['label' => 'Open', 'value' => (string) (int) $record->value('_open'), 'tone' => (int) $record->value('_open') > 0 ? 'warning' : null],
                ['label' => 'Verified', 'value' => $progress.'%', 'tone' => $progress === 100 ? 'success' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Snag list', 'icon' => 'circle-alert', 'empty' => 'No snags raised.',
                'rows' => $snags->map(fn (Record $snag) => [
                    'label' => $snag->title, 'sub' => trim(($snag->value('trade') ?? '').' · '.($snag->value('location') ?? ''), ' ·'), 'value' => ucfirst($snag->status), 'href' => $snag->url(),
                    'tone' => $snag->status === 'verified' ? 'success' : ($snag->status === 'open' && $snag->due_on?->lt(today()) ? 'danger' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $snags = $this->records('snags')->where('status', '!=', 'verified')->with('assignee')->get();
        $overdue = $snags->filter(fn (Record $snag) => $snag->status === 'open' && $snag->due_on?->lt(today()))->sortBy('due_on');
        $ready = $this->records('inspections')->where('status', 'done')->get()->filter(fn (Record $inspection) => (int) $inspection->value('_progress') === 100);
        $inspections = $this->records('inspections')->get()->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Snags', 'icon' => 'list-checks', 'stats' => [
                ['label' => 'Open', 'value' => (string) $snags->where('status', 'open')->count()],
                ['label' => 'Overdue', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'danger' : null],
                ['label' => 'Awaiting verification', 'value' => (string) $snags->where('status', 'fixed')->count()],
                ['label' => 'Ready to hand over', 'value' => (string) $ready->count(), 'tone' => $ready->isNotEmpty() ? 'success' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue snags', 'icon' => 'clock-alert', 'empty' => 'No snag is past its fix-by date.',
                'rows' => $overdue->take(10)->map(fn (Record $snag) => [
                    'label' => $snag->title, 'sub' => trim(($inspections[$snag->value('inspection')] ?? '').' · '.($snag->assignee?->name ?? 'Unassigned'), ' ·'),
                    'value' => 'Due '.$snag->due_on->format('d M'), 'href' => $snag->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $snags = $this->records('snags')->get();
        $trades = $snags->groupBy(fn (Record $snag) => $snag->value('trade') ?: 'No trade')->sortKeys()->map(fn ($group, $trade) => [
            $trade, $group->where('status', 'open')->count(), $group->where('status', 'fixed')->count(), $group->where('status', 'verified')->count(), $group->count(),
        ])->values()->all();

        $statuses = $this->app->entities['inspections']->statuses;
        $types = $this->app->entities['inspections']->field('type')?->options ?? [];
        $inspections = $this->dated('inspections', $from, $to)->orderBy('occurs_on')->get()->map(fn (Record $inspection) => [
            $inspection->value('project'), $inspection->title, $types[$inspection->value('type')] ?? ucfirst(str_replace('_', ' ', (string) $inspection->value('type'))),
            $statuses[$inspection->status] ?? $inspection->status, (int) $inspection->value('_snags'), (int) $inspection->value('_progress').'%',
        ])->all();

        $fixed = $this->dated('snags', $from, $to)->whereIn('status', ['fixed', 'verified'])->get();
        $speed = $fixed->groupBy(fn (Record $snag) => $snag->value('trade') ?: 'No trade')->sortKeys()->map(fn ($group, $trade) => [
            $trade, $group->count(), round($group->avg(fn (Record $snag) => (int) $snag->created_at->copy()->startOfDay()->diffInDays(Carbon::parse($snag->value('_fixed_on')))), 1).' days',
        ])->values()->all();

        return [
            ['title' => 'Snags by trade', 'columns' => ['Trade', 'Open', 'Fixed', 'Verified', 'Total'], 'rows' => $trades],
            ['title' => 'Inspections', 'columns' => ['Project', 'Unit / area', 'Type', 'Status', 'Snags', 'Verified'], 'rows' => $inspections],
            ['title' => 'Time to fix', 'columns' => ['Trade', 'Fixed', 'Average'], 'rows' => $speed],
        ];
    }
}
