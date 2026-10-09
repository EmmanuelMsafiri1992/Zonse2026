<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Client portal for agencies: a project ends after it starts and is only completed once every
 * deliverable is approved; completed projects take no new work or updates. A deliverable sent
 * for approval has something to look at, the client approves it or asks for changes (saying
 * what) in one click, and each project tracks how much has been signed off.
 */
class ClientPortalLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'projects') {
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The project must end after it starts.';
            }
            if ($payload['status'] === 'completed' && $existing) {
                $pending = $this->linked('deliverables', 'project', $existing)->where('status', '!=', 'approved')->count();
                if ($pending > 0) {
                    $errors['status'] = $pending.' '.str('deliverable')->plural($pending).' still need the client\'s approval.';
                }
            }

            return $errors;
        }

        $project = ! empty($data['project']) ? $this->records('projects')->find($data['project']) : null;
        if ($project?->status === 'completed' && (! $existing || (int) $existing->value('project') !== $project->id)) {
            $errors['data.project'] = $project->title.' is completed.';
        }
        if ($entity->key === 'deliverables') {
            if ($payload['status'] === 'awaiting_approval' && blank($data['file_url'] ?? null)) {
                $errors['data.file_url'] = 'Add a link the client can review.';
            }
            if ($payload['status'] === 'changes_requested' && blank($data['client_feedback'] ?? null)) {
                $errors['data.client_feedback'] = 'Say what the client wants changed.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'updates') {
            $record->occurs_on ??= today();

            return;
        }
        if ($record->entity === 'deliverables') {
            $this->put($record, ['_approved_on' => $record->status === 'approved' ? ($record->value('_approved_on') ?? today()->toDateString()) : null]);

            return;
        }

        $deliverables = $record->exists ? $this->linked('deliverables', 'project', $record)->get() : collect();
        $approved = $deliverables->where('status', 'approved')->count();
        $this->put($record, ['_deliverables' => $deliverables->count(), '_approved' => $approved,
            '_progress' => $deliverables->isEmpty() ? 0 : round($approved / $deliverables->count() * 100)]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'deliverables') {
            $this->recalculate($this->parent($record, 'project'));
            $this->recalculate($this->previousParent($record, 'project'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'deliverables') {
            $this->recalculate($this->parent($record, 'project'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'deliverables' || $record->status !== 'awaiting_approval') {
            return [];
        }

        return [
            'approve' => ['label' => 'Client approved', 'icon' => 'check', 'confirm' => 'Record that the client approved '.$record->title.'?'],
            'request_changes' => ['label' => 'Changes requested', 'icon' => 'pencil', 'fields' => [['name' => 'client_feedback', 'label' => 'What should change?', 'type' => 'text']]],
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'approve') {
            $record->update(['status' => 'approved']);

            return $record->title.' is approved.';
        }

        $feedback = $request->validate(['client_feedback' => ['required', 'string', 'max:2000']])['client_feedback'];
        $record->update(['status' => 'changes_requested', 'data' => [...(array) $record->data, 'client_feedback' => $feedback]]);

        return 'Changes requested on '.$record->title.'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'projects') {
            return [];
        }

        $deliverables = $this->linked('deliverables', 'project', $record)->orderBy('due_on')->get();
        $updates = $this->linked('updates', 'project', $record)->orderByDesc('occurs_on')->take(5)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Sign-off', 'icon' => 'package-check', 'stats' => [
                ['label' => 'Approved', 'value' => (int) $record->value('_approved').' of '.(int) $record->value('_deliverables')],
                ['label' => 'Progress', 'value' => (int) $record->value('_progress').'%'],
                ['label' => 'Budget', 'value' => $record->amount !== null ? $this->money($record->amount) : '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Deliverables', 'icon' => 'package', 'empty' => 'No deliverables yet.',
                'rows' => $deliverables->map(fn (Record $deliverable) => [
                    'label' => $deliverable->title, 'sub' => $deliverable->due_on?->format('d M Y'), 'value' => ucfirst(str_replace('_', ' ', $deliverable->status)), 'href' => $deliverable->url(),
                    'tone' => match ($deliverable->status) {
                        'approved' => 'success', 'changes_requested' => 'warning', default => null
                    },
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Latest updates', 'icon' => 'megaphone', 'empty' => 'No updates shared yet.',
                'rows' => $updates->map(fn (Record $update) => [
                    'label' => $update->title, 'sub' => str((string) $update->value('body'))->limit(80)->toString(), 'value' => $update->occurs_on?->format('d M') ?? '', 'href' => $update->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $projects = $this->records('projects')->pluck('title', 'id');
        $open = $this->records('deliverables')->where('status', '!=', 'approved')->orderBy('due_on')->get();
        $waiting = $open->where('status', 'awaiting_approval');
        $due = $open->where('status', '!=', 'awaiting_approval')->filter(fn (Record $deliverable) => $deliverable->due_on && $deliverable->due_on->lte(today()->addDays(7)));

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting for client approval', 'icon' => 'hourglass', 'empty' => 'Nothing is waiting on a client.',
                'rows' => $waiting->map(fn (Record $deliverable) => [
                    'label' => $deliverable->title, 'sub' => $projects[$deliverable->value('project')] ?? null, 'value' => $deliverable->due_on?->format('d M') ?? '', 'href' => $deliverable->url(),
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Due in the next week', 'icon' => 'calendar-clock', 'empty' => 'Nothing due this week.',
                'rows' => $due->map(fn (Record $deliverable) => [
                    'label' => $deliverable->title, 'sub' => $projects[$deliverable->value('project')] ?? null, 'value' => $deliverable->due_on->format('d M'), 'href' => $deliverable->url(),
                    'tone' => $deliverable->due_on->lt(today()) ? 'danger' : ($deliverable->status === 'changes_requested' ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $projects = $this->records('projects')->with('contact')->orderBy('title')->get();
        $deliverables = $this->records('deliverables')->get();

        $rows = $projects->map(function (Record $project) use ($deliverables) {
            $mine = $deliverables->filter(fn (Record $deliverable) => (int) $deliverable->value('project') === $project->id);

            return [
                $project->title, $project->contact?->name ?? '—', ucfirst(str_replace('_', ' ', $project->status)), $mine->count(), $mine->where('status', 'approved')->count(),
                $mine->where('status', 'changes_requested')->count(), (int) $project->value('_progress').'%',
            ];
        })->all();

        return [['title' => 'Project sign-off', 'columns' => ['Project', 'Client', 'Status', 'Deliverables', 'Approved', 'Changes requested', 'Progress'], 'rows' => $rows]];
    }
}
