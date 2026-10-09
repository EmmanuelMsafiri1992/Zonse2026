<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Issue tracking & roadmap: high and critical bugs need steps to reproduce, released versions take
 * no new issues, and a release cannot ship while its issues are still open. Releases track how
 * much of their scope is done, and shipping one writes its release notes from the finished issues.
 */
class IssuesLogic extends AppLogic
{
    public const CLOSED = ['done', 'wont_fix'];

    public const PRIORITY_ORDER = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'releases') {
            if ($payload['status'] === 'released' && $existing) {
                $open = $this->linked('issues', 'release', $existing)->whereNotIn('status', self::CLOSED)->count();
                if ($open > 0) {
                    $errors['status'] = $open.' '.str('issue')->plural($open).' in this release '.($open === 1 ? 'is' : 'are').' still open.';
                }
            }
            if ($this->records('releases')->where('title', $payload['title'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['title'] = 'There is already a release called '.$payload['title'].'.';
            }

            return $errors;
        }

        if (($data['type'] ?? null) === 'bug' && in_array($data['priority'] ?? null, ['high', 'critical'], true) && blank($data['steps'] ?? null)) {
            $errors['data.steps'] = 'Describe how to reproduce a '.$data['priority'].' bug.';
        }
        $release = ! empty($data['release']) ? $this->records('releases')->find($data['release']) : null;
        if ($release?->status === 'released' && (! $existing || (int) $existing->value('release') !== $release->id)) {
            $errors['data.release'] = $release->title.' has already shipped.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The due date cannot be before the issue was reported.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'issues') {
            $record->occurs_on ??= today();
            $closed = in_array($record->status, self::CLOSED, true);
            $this->put($record, ['_closed_on' => $closed ? ($record->value('_closed_on') ?? today()->toDateString()) : null]);

            return;
        }

        $issues = $record->exists ? $this->linked('issues', 'release', $record)->get() : collect();
        $closed = $issues->whereIn('status', self::CLOSED)->count();
        $this->put($record, ['_issues' => $issues->count(), '_closed' => $closed, '_progress' => $issues->isEmpty() ? 0 : round($closed / $issues->count() * 100)]);

        if ($record->status === 'released' && blank($record->value('release_notes'))) {
            $done = $issues->where('status', 'done')->sortBy(fn (Record $issue) => $issue->value('type').$issue->title);
            $this->put($record, ['release_notes' => $done->map(fn (Record $issue) => '- '.ucfirst((string) $issue->value('type')).': '.$issue->title)->implode("\n")]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'issues') {
            $this->recalculate($this->parent($record, 'release'));
            $this->recalculate($this->previousParent($record, 'release'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'issues') {
            $this->recalculate($this->parent($record, 'release'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'issues' && $record->status === 'open') {
            return ['triage' => ['label' => 'Triage', 'icon' => 'list-filter', 'fields' => [
                ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'options' => ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'], 'value' => 'medium'],
            ]]];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $priority = $request->validate(['priority' => ['required', 'in:'.implode(',', array_keys(self::PRIORITY_ORDER))]])['priority'];
        $record->update(['status' => 'triaged', 'data' => [...(array) $record->data, 'priority' => $priority]]);

        return $record->title.' is triaged as '.$priority.'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'releases') {
            return [];
        }

        $issues = $this->linked('issues', 'release', $record)->get()->sortBy(fn (Record $issue) => (in_array($issue->status, self::CLOSED, true) ? 1 : 0).(self::PRIORITY_ORDER[$issue->value('priority')] ?? 9));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Release scope', 'icon' => 'milestone', 'stats' => [
                ['label' => 'Issues', 'value' => (string) (int) $record->value('_issues')],
                ['label' => 'Closed', 'value' => (int) $record->value('_progress').'%', 'tone' => (int) $record->value('_progress') === 100 ? 'success' : null],
                ['label' => 'Target', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->status !== 'released' && $record->due_on?->lt(today()) ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Issues in this release', 'icon' => 'bug', 'empty' => 'No issues planned yet.',
                'rows' => $issues->map(fn (Record $issue) => [
                    'label' => $issue->title, 'sub' => ucfirst((string) $issue->value('priority')).' '.$issue->value('type'), 'value' => ucfirst(str_replace('_', ' ', $issue->status)), 'href' => $issue->url(),
                    'tone' => in_array($issue->status, self::CLOSED, true) ? 'success' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $open = $this->records('issues')->whereNotIn('status', self::CLOSED)->with('assignee')->get();
        $urgent = $open->whereIn('data.priority', ['critical', 'high'])->sortBy(fn (Record $issue) => self::PRIORITY_ORDER[$issue->value('priority')].$issue->occurs_on?->toDateString());
        $overdue = $open->filter(fn (Record $issue) => $issue->due_on?->lt(today()));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Issues', 'icon' => 'bug', 'stats' => [
                ['label' => 'Open', 'value' => (string) $open->count()],
                ['label' => 'Open bugs', 'value' => (string) $open->where('data.type', 'bug')->count()],
                ['label' => 'Critical', 'value' => (string) $open->where('data.priority', 'critical')->count(), 'tone' => $open->where('data.priority', 'critical')->isNotEmpty() ? 'danger' : null],
                ['label' => 'Overdue', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Critical and high priority', 'icon' => 'siren', 'empty' => 'Nothing urgent is open.',
                'rows' => $urgent->take(10)->map(fn (Record $issue) => [
                    'label' => $issue->title, 'sub' => $issue->assignee?->name ?? 'Unassigned', 'value' => ucfirst((string) $issue->value('priority')), 'href' => $issue->url(),
                    'tone' => $issue->value('priority') === 'critical' ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $issues = $this->records('issues')->get();
        $open = $issues->whereNotIn('status', self::CLOSED);

        $priorities = collect(array_keys(self::PRIORITY_ORDER))->map(fn (string $priority) => [
            ucfirst($priority), $open->where('data.priority', $priority)->where('data.type', 'bug')->count(), $open->where('data.priority', $priority)->where('data.type', '!=', 'bug')->count(),
            $open->where('data.priority', $priority)->count(),
        ])->all();

        $releases = $this->records('releases')->orderBy('due_on')->get()->map(fn (Record $release) => [
            $release->title, ucfirst(str_replace('_', ' ', $release->status)), $release->due_on?->format('d M Y') ?? '—', (int) $release->value('_issues'), (int) $release->value('_progress').'%',
        ])->all();

        $closed = $this->dated('issues', $from, $to)->whereIn('status', self::CLOSED)->get();
        $resolution = $closed->groupBy(fn (Record $issue) => ucfirst((string) $issue->value('type')))->sortKeys()->map(fn ($group, $type) => [
            $type, $group->count(), round($group->avg(fn (Record $issue) => $issue->value('_closed_on') ? (int) $issue->occurs_on->diffInDays(Carbon::parse($issue->value('_closed_on'))) : 0), 1).' days',
        ])->values()->all();

        return [
            ['title' => 'Open issues by priority', 'columns' => ['Priority', 'Bugs', 'Other', 'Total'], 'rows' => $priorities],
            ['title' => 'Release progress', 'columns' => ['Release', 'Status', 'Target', 'Issues', 'Closed'], 'rows' => $releases],
            ['title' => 'Time to close', 'columns' => ['Type', 'Closed', 'Average time'], 'rows' => $resolution],
        ];
    }
}
