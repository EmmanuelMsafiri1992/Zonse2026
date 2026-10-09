<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Projects: milestones cannot be added to a closed project, milestone billing stays within the
 * project budget, and a project cannot be completed while milestones are open. Each project
 * tracks its progress and billed amount and goes active once work on a milestone starts.
 */
class ProjectsLogic extends AppLogic
{
    public const CLOSED = ['completed', 'cancelled'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $errors = [];

        if ($entity->key === 'projects') {
            if ($payload['status'] === 'completed' && $existing && $this->linked('milestones', 'project', $existing)->whereNot('status', 'done')->exists()) {
                $errors['status'] = 'Finish or remove the open milestones before completing the project.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The deadline cannot be before the start.';
            }

            return $errors;
        }

        $project = ! empty($payload['data']['project']) ? $this->records('projects')->find($payload['data']['project']) : null;
        if (! $project) {
            return $errors;
        }

        $joining = ! $existing || (int) $existing->value('project') !== $project->id;
        if ($joining && in_array($project->status, self::CLOSED, true)) {
            $errors['data.project'] = $project->title.' is '.$project->status.', so it takes no new milestones.';
        }
        if ((float) $project->amount > 0) {
            $other = (float) $this->linked('milestones', 'project', $project)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->sum('amount');
            if ($other + (float) ($payload['amount'] ?? 0) - (float) $project->amount > 0.004) {
                $errors['amount'] = 'Only '.$this->money(max(0, (float) $project->amount - $other)).' of the '.$this->money($project->amount).' budget is left to bill.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'projects') {
            return;
        }

        $milestones = $record->exists ? $this->linked('milestones', 'project', $record)->get() : collect();
        $done = $milestones->where('status', 'done');
        $this->put($record, [
            '_milestones' => $milestones->count(),
            '_progress' => $milestones->isNotEmpty() ? (int) round($done->count() / $milestones->count() * 100) : 0,
            '_billed' => round((float) $done->sum('amount'), 2),
        ]);

        if ($record->status === 'planning' && $milestones->whereIn('status', ['in_progress', 'done'])->isNotEmpty()) {
            $record->status = 'active';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'milestones') {
            $this->recalculate($this->parent($record, 'project'));
            $this->recalculate($this->previousParent($record, 'project'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'milestones') {
            $this->recalculate($this->parent($record, 'project'));
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'projects') {
            return [];
        }

        $cards = [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Progress', 'icon' => 'gauge', 'stats' => [
            ['label' => 'Milestones done', 'value' => (int) $record->value('_progress').'% of '.(int) $record->value('_milestones')],
            ['label' => 'Billed', 'value' => $this->money($record->value('_billed'))],
            ['label' => 'Budget', 'value' => $this->money($record->amount)],
        ]]]];

        if ($record->due_on && $record->due_on->lt(today()) && ! in_array($record->status, self::CLOSED, true)) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'danger', 'icon' => 'alarm-clock', 'title' => 'Past its deadline',
                'body' => 'The deadline was '.$record->due_on->format('d M Y').'.']];
        }

        return $cards;
    }

    public function homeCards(): array
    {
        $overdue = $this->records('milestones')->whereNot('status', 'done')->whereDate('due_on', '<', today())->orderBy('due_on')->get();
        $active = $this->records('projects')->where('status', 'active')->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue milestones', 'icon' => 'alarm-clock', 'empty' => 'Nothing is overdue.',
                'rows' => $overdue->take(15)->map(fn (Record $milestone) => [
                    'label' => $milestone->title, 'sub' => $this->parent($milestone, 'project')?->title, 'value' => $milestone->due_on->format('d M'), 'href' => $milestone->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Active projects', 'icon' => 'kanban-square', 'empty' => 'No active projects.',
                'rows' => $active->map(fn (Record $project) => [
                    'label' => $project->title, 'sub' => $project->due_on ? 'Due '.$project->due_on->format('d M Y') : null, 'value' => (int) $project->value('_progress').'%', 'href' => $project->url(),
                    'tone' => $project->due_on?->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $projects = $this->records('projects')->orderBy('title')->get()->map(fn (Record $project) => [
            $project->title, ucfirst(str_replace('_', ' ', $project->status)), (int) $project->value('_progress').'%', $this->money($project->amount),
            $this->money($project->value('_billed')), $project->due_on?->format('d M Y') ?? '—',
        ])->all();

        $due = $this->records('milestones')->whereBetween('due_on', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('due_on')->get()->map(fn (Record $milestone) => [
            $milestone->due_on->format('d M Y'), $milestone->title, (string) ($this->parent($milestone, 'project')?->title ?? '—'), ucfirst(str_replace('_', ' ', $milestone->status)), $this->money($milestone->amount),
        ])->all();

        return [
            ['title' => 'Project status', 'columns' => ['Project', 'Status', 'Progress', 'Budget', 'Billed', 'Deadline'], 'rows' => $projects],
            ['title' => 'Milestones due', 'columns' => ['Due', 'Milestone', 'Project', 'Status', 'Amount'], 'rows' => $due],
        ];
    }
}
