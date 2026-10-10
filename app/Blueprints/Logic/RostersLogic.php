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
 * Shifts & rosters: a shift may run past midnight, and nobody can be rostered on two shifts that
 * overlap on the same day. A swap request hands a planned or confirmed shift to someone else; approving
 * it moves the shift to them, but only if they are free. Shifts are confirmed, completed or marked as a
 * no-show, and reports total the hours each person worked.
 */
class RostersLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'shifts') {
            if (filled($data['start_time'] ?? null) && ($data['start_time'] ?? null) === ($data['end_time'] ?? null)) {
                $errors['data.end_time'] = 'The shift must end at a different time from when it starts.';
            }
            $clash = filled($payload['assignee_id'] ?? null) && filled($data['start_time'] ?? null) && filled($data['end_time'] ?? null)
                ? $this->clash((int) $payload['assignee_id'], Carbon::parse($payload['occurs_on'] ?? today()), (string) $data['start_time'], (string) $data['end_time'], $existing?->id)
                : null;
            if ($clash) {
                $errors['assignee_id'] = $this->name((int) $payload['assignee_id']).' is already on the '.$clash->value('start_time').'–'.$clash->value('end_time').' shift that day.';
            }

            return $errors;
        }
        $shift = filled($data['shift'] ?? null) ? $this->records('shifts')->find($data['shift']) : null;
        if ($shift && ! $existing && ! in_array($shift->status, ['planned', 'confirmed'], true)) {
            $errors['data.shift'] = 'Only planned or confirmed shifts can be swapped.';
        }
        if ($shift && filled($data['swap_with'] ?? null) && (int) $data['swap_with'] === (int) $shift->assignee_id) {
            $errors['data.swap_with'] = 'Pick someone other than the person on the shift.';
        }

        return $errors;
    }

    /**
     * Another shift for the person on the same day that overlaps the given times.
     */
    protected function clash(int $userId, Carbon $day, string $start, string $end, ?int $ignore = null): ?Record
    {
        [$from, $to] = $this->span($start, $end);

        return $this->records('shifts')->where('assignee_id', $userId)->whereDate('occurs_on', $day->toDateString())->whereNotIn('status', ['no_show'])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore))->get()
            ->first(function (Record $shift) use ($from, $to) {
                [$otherFrom, $otherTo] = $this->span((string) $shift->value('start_time'), (string) $shift->value('end_time'));

                return $from < $otherTo && $otherFrom < $to;
            });
    }

    /**
     * A shift's start and end as minutes after midnight, the end running into the next day when it is earlier.
     *
     * @return array{0: int, 1: int}
     */
    protected function span(string $start, string $end): array
    {
        $minutes = fn (string $time) => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        $from = $minutes($start);
        $to = $minutes($end);

        return [$from, $to <= $from ? $to + 1440 : $to];
    }

    protected function name(?int $userId): string
    {
        return (string) ($userId ? User::query()->whereKey($userId)->value('name') : null) ?: 'Unassigned';
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'shifts' && filled($record->value('start_time')) && filled($record->value('end_time'))) {
            [$from, $to] = $this->span((string) $record->value('start_time'), (string) $record->value('end_time'));
            $this->put($record, ['_hours' => round(($to - $from) / 60, 2)]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'swaps') {
            return $record->status === 'requested' ? ['approve' => ['label' => 'Approve', 'icon' => 'check'], 'decline' => ['label' => 'Decline', 'icon' => 'x']] : [];
        }

        return match ($record->status) {
            'planned' => ['confirm' => ['label' => 'Confirm', 'icon' => 'check'], 'no_show' => ['label' => 'No-show', 'icon' => 'user-x']],
            'confirmed', 'swapped' => ['complete' => ['label' => 'Worked', 'icon' => 'check-check'], 'no_show' => ['label' => 'No-show', 'icon' => 'user-x']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'shifts') {
            $status = ['confirm' => 'confirmed', 'complete' => 'completed', 'no_show' => 'no_show'][$action];
            $record->update(['status' => $status]);

            return $this->name($record->assignee_id).' '.match ($status) {
                'confirmed' => 'is confirmed for '.$record->occurs_on->format('d M').'.',
                'completed' => 'worked '.$this->number($record, '_hours').' hours.',
                default => 'did not turn up.',
            };
        }
        if ($action === 'decline') {
            $record->update(['status' => 'declined']);

            return 'Swap declined.';
        }
        $shift = $this->parent($record, 'shift');
        $to = (int) $record->value('swap_with');
        if (! $shift || ! in_array($shift->status, ['planned', 'confirmed', 'swapped'], true)) {
            throw ValidationException::withMessages(['shift' => 'This shift can no longer be swapped.']);
        }
        if ($clash = $this->clash($to, $shift->occurs_on, (string) $shift->value('start_time'), (string) $shift->value('end_time'), $shift->id)) {
            throw ValidationException::withMessages(['swap_with' => $this->name($to).' is already on the '.$clash->value('start_time').'–'.$clash->value('end_time').' shift that day.']);
        }
        $from = $shift->assignee_id;
        $shift->update(['assignee_id' => $to, 'status' => 'swapped', 'data' => [...$shift->data, '_swapped_from' => $from]]);
        $record->update(['status' => 'approved']);

        return $this->name($to).' now works the '.$shift->occurs_on->format('d M').' shift instead of '.$this->name($from).'.';
    }

    public function homeCards(): array
    {
        $today = $this->records('shifts')->whereDate('occurs_on', today()->toDateString())->orderBy('data->start_time')->get();
        $swaps = $this->records('swaps')->where('status', 'requested')->count();
        $names = User::query()->whereIn('id', $today->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'On shift today'.($swaps ? ' · '.$swaps.' swap '.str('request')->plural($swaps).' waiting' : ''), 'icon' => 'calendar-days', 'empty' => 'No shifts today.',
            'rows' => $today->map(fn (Record $shift) => [
                'label' => $names[$shift->assignee_id] ?? 'Unassigned', 'sub' => trim($shift->title.' · '.($shift->value('role') ?? ''), ' ·'),
                'value' => $shift->value('start_time').'–'.$shift->value('end_time'), 'href' => $shift->url(),
                'tone' => match ($shift->status) {
                    'no_show' => 'danger', 'planned' => 'warning', default => null
                },
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $shifts = $this->dated('shifts', $from, $to)->get();
        $names = User::query()->whereIn('id', $shifts->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        return [['title' => 'Hours by person', 'columns' => ['Person', 'Shifts', 'Hours rostered', 'Hours worked', 'No-shows'], 'rows' => $shifts
            ->groupBy(fn (Record $shift) => $names[$shift->assignee_id] ?? 'Unassigned')->sortKeys()
            ->map(fn ($group, string $name) => [
                $name, $group->count(), round($group->sum(fn (Record $shift) => $this->number($shift, '_hours')), 2),
                round($group->where('status', 'completed')->sum(fn (Record $shift) => $this->number($shift, '_hours')), 2), $group->where('status', 'no_show')->count(),
            ])->values()->all()]];
    }
}
