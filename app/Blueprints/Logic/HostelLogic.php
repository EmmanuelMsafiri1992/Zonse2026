<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Hostel & boarding: a room's occupancy is counted from its active allocations and it is full when
 * every bed is taken, so a student is only allocated a room with a free bed and a bed number nobody
 * else holds. Exeats are approved with the person collecting the student, signed out and signed back
 * in, and the hostel flags anyone not back on the day they promised.
 */
class HostelLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'rooms') {
            $beds = (int) ($data['beds'] ?? 0);
            if ($beds < 1) {
                $errors['data.beds'] = 'A room has at least one bed.';
            }
            $occupied = $existing ? $this->linked('allocations', 'room', $existing)->where('status', 'active')->count() : 0;
            if ($beds < $occupied) {
                $errors['data.beds'] = $occupied.' students already sleep in this room.';
            }
            if ($existing && $payload['status'] === 'closed' && $occupied > 0) {
                $errors['status'] = 'Move the students out before closing this room.';
            }

            return $errors;
        }

        if ($entity->key === 'allocations') {
            $room = ! empty($data['room']) ? $this->records('rooms')->find($data['room']) : null;
            $movingIn = $payload['status'] === 'active' && (! $existing || $existing->status !== 'active' || (int) $existing->value('room') !== $room?->id);
            if ($room && $movingIn) {
                $residents = $this->linked('allocations', 'room', $room)->where('status', 'active')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get();
                if ($room->status === 'closed') {
                    $errors['data.room'] = $room->title.' is closed.';
                } elseif ($residents->count() >= (int) $room->value('beds')) {
                    $errors['data.room'] = $room->title.' is full.';
                } elseif (filled($data['bed'] ?? null) && $residents->contains(fn (Record $allocation) => strtolower(trim((string) $allocation->value('bed'))) === strtolower(trim($data['bed'])))) {
                    $errors['data.bed'] = 'Bed '.$data['bed'].' is taken.';
                }
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The allocation cannot end before it starts.';
            }
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'The boarding fee cannot be negative.';
            }

            return $errors;
        }

        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The student cannot return before leaving.';
        }
        if (in_array($payload['status'], ['approved', 'out'], true) && blank($data['collected_by'] ?? null)) {
            $errors['data.collected_by'] = 'Say who collects the student before approving the exeat.';
        }
        if ($payload['status'] === 'returned' && $existing?->status !== 'out' && $existing?->status !== 'returned') {
            $errors['status'] = 'A student is signed back in only after signing out.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'rooms') {
            $occupied = $record->exists ? $this->linked('allocations', 'room', $record)->where('status', 'active')->count() : 0;
            $beds = (int) $record->value('beds');
            $this->put($record, ['occupied' => $occupied, '_free' => max(0, $beds - $occupied)]);
            if ($record->status !== 'closed') {
                $record->status = $occupied >= $beds ? 'full' : 'available';
            }

            return;
        }

        $record->occurs_on ??= today();
        if ($record->entity === 'allocations') {
            if ($record->status === 'ended') {
                $record->due_on ??= today();
            }

            return;
        }

        $this->put($record, [
            '_out_on' => $record->status === 'out' ? ($record->value('_out_on') ?? today()->toDateString()) : $record->value('_out_on'),
            '_returned_on' => $record->status === 'returned' ? ($record->value('_returned_on') ?? today()->toDateString()) : null,
            '_days_late' => $record->status === 'returned' && $record->due_on ? max(0, (int) $record->due_on->copy()->startOfDay()->diffInDays(Carbon::parse($record->value('_returned_on') ?? today())->startOfDay(), false)) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'allocations') {
            $this->recalculate($this->parent($record, 'room'));
            $this->recalculate($this->previousParent($record, 'room'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'allocations') {
            $this->recalculate($this->parent($record, 'room'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'rooms') {
            return $record->status === 'closed'
                ? ['reopen' => ['label' => 'Reopen', 'icon' => 'door-open']]
                : ['close' => ['label' => 'Close room', 'icon' => 'door-closed', 'confirm' => 'Close this room? It must be empty.']];
        }

        if ($record->entity === 'allocations') {
            return $record->status === 'active' ? ['end' => ['label' => 'Move out', 'icon' => 'log-out', 'confirm' => 'End this allocation and free the bed?']] : [];
        }

        return match ($record->status) {
            'requested' => [
                'approve' => ['label' => 'Approve', 'icon' => 'check', 'fields' => [
                    ['name' => 'collected_by', 'label' => 'Collected by', 'type' => 'text', 'value' => $record->value('collected_by')],
                ]],
                'decline' => ['label' => 'Decline', 'icon' => 'x'],
            ],
            'approved' => ['sign_out' => ['label' => 'Sign out', 'icon' => 'log-out']],
            'out' => ['sign_in' => ['label' => 'Sign in', 'icon' => 'log-in']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'close':
                if ($this->linked('allocations', 'room', $record)->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['status' => 'Move the students out before closing this room.']);
                }
                $record->update(['status' => 'closed']);

                return $record->title.' is closed.';
            case 'reopen':
                $record->update(['status' => 'available']);

                return $record->title.' is '.$record->fresh()->status.'.';
            case 'end':
                $record->update(['status' => 'ended', 'due_on' => today()]);

                return $record->title.' has moved out.';
            case 'approve':
                $collectedBy = $request->validate(['collected_by' => ['required', 'string']])['collected_by'];
                $record->update(['status' => 'approved', 'data' => [...(array) $record->data, 'collected_by' => $collectedBy]]);

                return 'Exeat approved; '.$collectedBy.' collects '.$record->title.'.';
            case 'decline':
                $record->update(['status' => 'declined']);

                return 'Exeat declined.';
            case 'sign_out':
                $record->update(['status' => 'out']);

                return $record->title.' signed out'.($record->value('destination') ? ' to '.$record->value('destination') : '').($record->due_on ? ', back on '.$record->due_on->format('d M Y') : '').'.';
        }
        $record->update(['status' => 'returned']);
        $late = (int) $record->fresh()->value('_days_late');

        return $record->title.' signed in'.($late > 0 ? ', '.$late.' days late' : ' on time').'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'rooms') {
            return [];
        }

        $residents = $this->linked('allocations', 'room', $record)->where('status', 'active')->orderBy('data->bed')->get();
        $genders = $this->app->entities['rooms']->field('gender')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Room', 'icon' => 'bed', 'stats' => [
                ['label' => 'Hostel', 'value' => (string) $record->value('hostel_name')],
                ['label' => 'For', 'value' => $genders[$record->value('gender')] ?? ucfirst((string) ($record->value('gender') ?: 'anyone'))],
                ['label' => 'Beds', 'value' => (string) (int) $record->value('beds')],
                ['label' => 'Occupied', 'value' => (string) (int) $record->value('occupied')],
                ['label' => 'Free', 'value' => (string) (int) $record->value('_free'), 'tone' => (int) $record->value('_free') === 0 ? 'warning' : 'success'],
                ['label' => 'Boarding fees', 'value' => $this->money($residents->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Residents', 'icon' => 'users-round', 'empty' => 'The room is empty.',
                'rows' => $residents->map(fn (Record $allocation) => [
                    'label' => $allocation->title, 'sub' => 'Since '.$allocation->occurs_on?->format('d M Y'), 'value' => $allocation->value('bed') ? 'Bed '.$allocation->value('bed') : '', 'href' => $allocation->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $rooms = $this->records('rooms')->where('status', '!=', 'closed')->get();
        $exeats = $this->records('exeats')->get();
        $out = $exeats->where('status', 'out')->sortBy('due_on');
        $overdue = $out->filter(fn (Record $exeat) => $exeat->due_on && $exeat->due_on->lt(today()));
        $beds = $rooms->sum(fn (Record $room) => (int) $room->value('beds'));
        $occupied = $rooms->sum(fn (Record $room) => (int) $room->value('occupied'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Hostel', 'icon' => 'bed', 'stats' => [
                ['label' => 'Beds', 'value' => (string) $beds],
                ['label' => 'Occupied', 'value' => (string) $occupied],
                ['label' => 'Free beds', 'value' => (string) max(0, $beds - $occupied)],
                ['label' => 'Occupancy', 'value' => $beds > 0 ? round($occupied / $beds * 100).'%' : '—'],
                ['label' => 'Students out', 'value' => (string) $out->count()],
                ['label' => 'Overdue returns', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'danger' : null],
                ['label' => 'Exeats to approve', 'value' => (string) $exeats->where('status', 'requested')->count(), 'tone' => $exeats->where('status', 'requested')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Out on exeat', 'icon' => 'log-out', 'empty' => 'Everyone is in.',
                'rows' => $out->take(10)->map(fn (Record $exeat) => [
                    'label' => $exeat->title, 'sub' => ($exeat->value('destination') ?: 'Destination not given').' · with '.$exeat->value('collected_by'), 'value' => $exeat->due_on ? ($exeat->due_on->lt(today()) ? (int) $exeat->due_on->diffInDays(today()).' days late' : 'Back '.$exeat->due_on->format('d M')) : 'No return date', 'href' => $exeat->url(),
                    'tone' => $exeat->due_on && $exeat->due_on->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $rooms = $this->records('rooms')->get();
        $byHostel = $rooms->groupBy(fn (Record $room) => $room->value('hostel_name') ?: 'Unknown')->sortKeys()->map(function ($group, $hostel) {
            $beds = $group->where('status', '!=', 'closed')->sum(fn (Record $room) => (int) $room->value('beds'));
            $occupied = $group->sum(fn (Record $room) => (int) $room->value('occupied'));

            return [$hostel, $group->count(), $beds, $occupied, max(0, $beds - $occupied), $beds > 0 ? round($occupied / $beds * 100).'%' : '—'];
        })->values()->all();

        $exeats = $this->dated('exeats', $from, $to)->get();
        $approvers = User::whereIn('id', $exeats->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($exeats) {
            $group = $exeats->filter(fn (Record $exeat) => $exeat->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->whereIn('status', ['approved', 'out', 'returned'])->count(), $group->where('status', 'declined')->count(), $group->filter(fn (Record $exeat) => (int) $exeat->value('_days_late') > 0)->count()];
        })->values()->all();
        $byApprover = $exeats->whereIn('status', ['approved', 'out', 'returned', 'declined'])->groupBy(fn (Record $exeat) => $exeat->assignee_id ? ($approvers[$exeat->assignee_id] ?? 'Unknown') : 'Unassigned')->sortKeys()->map(fn ($group, $approver) => [
            $approver, $group->whereIn('status', ['approved', 'out', 'returned'])->count(), $group->where('status', 'declined')->count(),
        ])->values()->all();

        return [
            ['title' => 'Occupancy by hostel', 'columns' => ['Hostel', 'Rooms', 'Beds', 'Occupied', 'Free', 'Occupancy'], 'rows' => $byHostel],
            ['title' => 'Exeats by month', 'columns' => ['Month', 'Requested', 'Approved', 'Declined', 'Returned late'], 'rows' => $byMonth],
            ['title' => 'Exeats by approver', 'columns' => ['Approver', 'Approved', 'Declined'], 'rows' => $byApprover],
        ];
    }
}
