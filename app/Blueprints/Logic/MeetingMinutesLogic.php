<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Meeting minutes & action items: a held meeting records who attended, minutes are written before
 * they are approved, and approved minutes are locked. Action items are due on or after their
 * meeting, each meeting counts its open actions, and everyone sees the actions they owe.
 */
class MeetingMinutesLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'meetings') {
            if (in_array($payload['status'], ['held', 'minutes_approved'], true) && blank($data['attendees'] ?? null)) {
                $errors['data.attendees'] = 'Record who attended.';
            }
            if ($payload['status'] === 'minutes_approved' && blank($data['minutes'] ?? null)) {
                $errors['data.minutes'] = 'Write the minutes before approving them.';
            }
            if ($existing?->status === 'minutes_approved' && (string) $existing->value('minutes') !== (string) ($data['minutes'] ?? '')) {
                $errors['data.minutes'] = 'These minutes are approved and cannot be changed.';
            }
            if (in_array($payload['status'], ['held', 'minutes_approved'], true) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
                $errors['status'] = 'A meeting cannot be held before its date.';
            }

            return $errors;
        }

        $meeting = ! empty($data['meeting']) ? $this->records('meetings')->find($data['meeting']) : null;
        if ($meeting?->occurs_on && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt($meeting->occurs_on)) {
            $errors['due_on'] = 'An action cannot be due before its meeting ('.$meeting->occurs_on->format('d M Y').').';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'meetings') {
            $actions = $record->exists ? $this->linked('actions', 'meeting', $record)->get() : collect();
            $this->put($record, ['_actions' => $actions->count(), '_open_actions' => $actions->where('status', 'open')->count()]);

            return;
        }

        $this->put($record, ['_done_on' => $record->status === 'done' ? ($record->value('_done_on') ?? today()->toDateString()) : null]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'actions') {
            $this->recalculate($this->parent($record, 'meeting'));
            $this->recalculate($this->previousParent($record, 'meeting'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'actions') {
            $this->recalculate($this->parent($record, 'meeting'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'meetings' && $record->status === 'held' && filled($record->value('minutes'))) {
            return ['approve_minutes' => ['label' => 'Approve minutes', 'icon' => 'stamp', 'confirm' => 'Approve and lock the minutes of '.$record->title.'?']];
        }
        if ($record->entity === 'actions' && $record->status === 'open') {
            return ['complete' => ['label' => 'Mark done', 'icon' => 'check']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'approve_minutes') {
            $record->update(['status' => 'minutes_approved']);

            return 'The minutes of '.$record->title.' are approved.';
        }

        $record->update(['status' => 'done']);

        return $record->title.' is done.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'meetings') {
            return [];
        }

        $owners = User::query()->pluck('name', 'id');
        $actions = $this->linked('actions', 'meeting', $record)->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Action items', 'icon' => 'list-todo', 'empty' => 'No actions agreed.',
            'rows' => $actions->map(fn (Record $action) => [
                'label' => $action->title, 'sub' => $owners[$action->value('owner')] ?? '—', 'value' => $action->status === 'open' ? ($action->due_on?->format('d M') ?? 'Open') : ucfirst($action->status),
                'href' => $action->url(), 'tone' => $this->isOverdue($action) ? 'danger' : ($action->status === 'done' ? 'success' : null),
            ])->values()->all(),
        ]]];
    }

    public function isOverdue(Record $action): bool
    {
        return $action->status === 'open' && $action->due_on !== null && $action->due_on->lt(today());
    }

    public function homeCards(): array
    {
        $open = $this->records('actions')->where('status', 'open')->orderBy('due_on')->get();
        $mine = $open->filter(fn (Record $action) => (int) $action->value('owner') === (int) auth()->id());
        $meetings = $this->records('meetings')->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Action items', 'icon' => 'list-todo', 'stats' => [
                ['label' => 'Open', 'value' => (string) $open->count()],
                ['label' => 'Overdue', 'value' => (string) $open->filter(fn (Record $action) => $this->isOverdue($action))->count(), 'tone' => 'danger'],
                ['label' => 'Minutes to approve', 'value' => (string) $this->records('meetings')->where('status', 'held')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'My open actions', 'icon' => 'user-check', 'empty' => 'You have no open actions.',
                'rows' => $mine->map(fn (Record $action) => [
                    'label' => $action->title, 'sub' => $meetings[$action->value('meeting')] ?? null, 'value' => $action->due_on?->format('d M') ?? '', 'href' => $action->url(),
                    'tone' => $this->isOverdue($action) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $meetings = $this->dated('meetings', $from, $to)->get();
        $meetingIds = $meetings->pluck('id')->all();
        $actions = $this->records('actions')->get()->filter(fn (Record $action) => in_array((int) $action->value('meeting'), $meetingIds, true));
        $owners = User::query()->whereIn('id', $actions->map(fn (Record $action) => (int) $action->value('owner'))->filter()->unique())->pluck('name', 'id');

        $byOwner = $actions->groupBy(fn (Record $action) => $owners[$action->value('owner')] ?? 'No owner')->sortKeys()->map(fn ($group, $owner) => [
            $owner, $group->where('status', 'open')->count(), $group->filter(fn (Record $action) => $this->isOverdue($action))->count(), $group->where('status', 'done')->count(),
        ])->values()->all();

        $byMeeting = $meetings->sortBy('occurs_on')->map(fn (Record $meeting) => [
            $meeting->occurs_on?->format('d M Y') ?? '—', $meeting->title, ucfirst(str_replace('_', ' ', $meeting->status)), (int) $meeting->value('_actions'), (int) $meeting->value('_open_actions'),
        ])->values()->all();

        return [
            ['title' => 'Action items by owner', 'columns' => ['Owner', 'Open', 'Overdue', 'Done'], 'rows' => $byOwner],
            ['title' => 'Meetings', 'columns' => ['Date', 'Meeting', 'Status', 'Actions', 'Still open'], 'rows' => $byMeeting],
        ];
    }
}
