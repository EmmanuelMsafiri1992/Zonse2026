<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Volunteers: shifts go to active volunteers on days they said they are available, carry sensible
 * hours, and are completed or missed only once the day has come. Each volunteer shows their hours,
 * missed shifts and reliability, and nobody is deactivated with a shift still scheduled.
 */
class VolunteersLogic extends AppLogic
{
    public const MAX_HOURS = 16;

    public const DEFAULT_HOURS = 2;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'volunteers') {
            if ($payload['status'] === 'inactive' && $existing && $existing->status !== 'inactive' && ($scheduled = $this->linked('shifts', 'volunteer', $existing)->where('status', 'scheduled')->count()) > 0) {
                $errors['status'] = $existing->title.' still has '.$scheduled.' scheduled shifts.';
            }

            return $errors;
        }

        $volunteer = ! empty($data['volunteer']) ? $this->records('volunteers')->find($data['volunteer']) : null;
        if ($volunteer && $volunteer->status !== 'active' && $payload['status'] === 'scheduled') {
            $errors['data.volunteer'] = $volunteer->title.' is inactive.';
        }
        if (filled($data['hours'] ?? null) && ((float) $data['hours'] <= 0 || (float) $data['hours'] > self::MAX_HOURS)) {
            $errors['data.hours'] = 'A shift is between a quarter of an hour and '.self::MAX_HOURS.' hours.';
        }
        $day = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        if ($volunteer && $payload['status'] === 'scheduled' && ($clash = $this->availabilityClash($volunteer, $day))) {
            $errors['occurs_on'] = $clash;
        }
        if (in_array($payload['status'], ['completed', 'missed'], true) && $day->gt(today())) {
            $errors['status'] = 'A shift is completed or missed only once its day has come.';
        }

        return $errors;
    }

    /** Why this day does not suit the volunteer's availability, or null when it does. */
    protected function availabilityClash(Record $volunteer, Carbon $day): ?string
    {
        return match ($volunteer->value('availability')) {
            'weekdays' => $day->isWeekend() ? $volunteer->title.' is only available on weekdays.' : null,
            'weekends' => $day->isWeekday() ? $volunteer->title.' is only available at weekends.' : null,
            default => null,
        };
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'shifts') {
            $record->occurs_on ??= today();
            $this->put($record, ['hours' => filled($record->value('hours')) ? round((float) $record->value('hours'), 2) : self::DEFAULT_HOURS]);

            return;
        }

        $shifts = $record->exists ? $this->linked('shifts', 'volunteer', $record)->get() : collect();
        $done = $shifts->whereIn('status', ['completed', 'missed']);
        $this->put($record, [
            '_shifts' => $shifts->where('status', 'completed')->count(),
            '_hours' => round($shifts->where('status', 'completed')->sum(fn (Record $shift) => (float) $shift->value('hours')), 2),
            '_missed' => $shifts->where('status', 'missed')->count(),
            '_scheduled' => $shifts->where('status', 'scheduled')->count(),
            '_reliability' => $done->isEmpty() ? null : (int) round($shifts->where('status', 'completed')->count() / $done->count() * 100),
            '_last_shift' => $shifts->where('status', 'completed')->sortByDesc('occurs_on')->first()?->occurs_on?->toDateString(),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'shifts') {
            $this->recalculate($this->parent($record, 'volunteer'));
            $this->recalculate($this->previousParent($record, 'volunteer'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'shifts') {
            $this->recalculate($this->parent($record, 'volunteer'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'volunteers') {
            return $record->status === 'active'
                ? [
                    'schedule' => ['label' => 'Schedule shift', 'icon' => 'calendar-plus', 'fields' => [
                        ['name' => 'task', 'label' => 'Task', 'type' => 'text'],
                        ['name' => 'occurs_on', 'label' => 'Date', 'type' => 'date', 'value' => today()->toDateString()],
                        ['name' => 'hours', 'label' => 'Hours', 'type' => 'number', 'value' => self::DEFAULT_HOURS],
                        ['name' => 'location', 'label' => 'Location', 'type' => 'text'],
                    ]],
                    'deactivate' => ['label' => 'Deactivate', 'icon' => 'user-x', 'confirm' => 'Deactivate '.$record->title.'?'],
                ]
                : ['activate' => ['label' => 'Activate', 'icon' => 'user-check']];
        }

        return $record->status === 'scheduled'
            ? [
                'complete' => ['label' => 'Completed', 'icon' => 'check', 'fields' => [
                    ['name' => 'hours', 'label' => 'Hours worked', 'type' => 'number', 'value' => $record->value('hours')],
                ]],
                'miss' => ['label' => 'Missed', 'icon' => 'x', 'confirm' => 'Mark this shift as missed?'],
            ]
            : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'schedule':
                $input = $request->validate(['task' => ['required', 'string'], 'occurs_on' => ['required', 'date', 'after_or_equal:today'], 'hours' => ['nullable', 'numeric', 'gt:0', 'max:'.self::MAX_HOURS], 'location' => ['nullable', 'string']]);
                $day = Carbon::parse($input['occurs_on']);
                if ($clash = $this->availabilityClash($record, $day)) {
                    throw ValidationException::withMessages(['occurs_on' => $clash]);
                }
                Record::create([
                    'workspace_id' => $record->workspace_id, 'branch_id' => $record->branch_id, 'blueprint' => $record->blueprint, 'entity' => 'shifts',
                    'title' => $input['task'], 'status' => 'scheduled', 'occurs_on' => $day,
                    'data' => ['volunteer' => $record->id, 'hours' => $input['hours'] ?? self::DEFAULT_HOURS, 'location' => $input['location'] ?? null],
                ]);

                return $record->title.' is scheduled for '.$input['task'].' on '.$day->format('d M Y').'.';
            case 'deactivate':
                $scheduled = $this->linked('shifts', 'volunteer', $record)->where('status', 'scheduled')->count();
                if ($scheduled > 0) {
                    throw ValidationException::withMessages(['status' => $record->title.' still has '.$scheduled.' scheduled shifts.']);
                }
                $record->update(['status' => 'inactive']);

                return $record->title.' is inactive.';
            case 'activate':
                $record->update(['status' => 'active']);

                return $record->title.' is active.';
            case 'miss':
                $record->update(['status' => 'missed']);

                return 'Shift marked as missed.';
        }

        $hours = $request->validate(['hours' => ['nullable', 'numeric', 'gt:0', 'max:'.self::MAX_HOURS]])['hours'] ?? $record->value('hours');
        if ($record->occurs_on?->gt(today())) {
            throw ValidationException::withMessages(['status' => 'A shift is completed only once its day has come.']);
        }
        $record->update(['status' => 'completed', 'data' => [...$record->data, 'hours' => round((float) $hours, 2)]]);

        return 'Shift completed: '.round((float) $hours, 2).' hours.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'volunteers') {
            return [];
        }

        $shifts = $this->linked('shifts', 'volunteer', $record)->orderByDesc('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Volunteer', 'icon' => 'hand-helping', 'stats' => [
                ['label' => 'Shifts completed', 'value' => (string) (int) $record->value('_shifts')],
                ['label' => 'Hours given', 'value' => (string) $this->number($record, '_hours')],
                ['label' => 'Missed', 'value' => (string) (int) $record->value('_missed'), 'tone' => (int) $record->value('_missed') > 0 ? 'warning' : null],
                ['label' => 'Reliability', 'value' => $record->value('_reliability') === null ? '—' : (int) $record->value('_reliability').'%', 'tone' => $record->value('_reliability') !== null && (int) $record->value('_reliability') < 70 ? 'warning' : null],
                ['label' => 'Scheduled', 'value' => (string) (int) $record->value('_scheduled')],
                ['label' => 'Last shift', 'value' => $record->value('_last_shift') ? Carbon::parse($record->value('_last_shift'))->format('d M Y') : 'None yet'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Shifts', 'icon' => 'clock', 'empty' => 'No shifts yet.',
                'rows' => $shifts->take(10)->map(fn (Record $shift) => [
                    'label' => $shift->title, 'sub' => $shift->occurs_on?->format('d M Y').($shift->value('location') ? ' · '.$shift->value('location') : ''), 'value' => $shift->status === 'missed' ? 'Missed' : $shift->value('hours').' h', 'href' => $shift->url(), 'tone' => $shift->status === 'missed' ? 'danger' : ($shift->status === 'completed' ? 'success' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $volunteers = $this->records('volunteers')->get();
        $shifts = $this->records('shifts')->get();
        $month = $shifts->filter(fn (Record $shift) => $shift->status === 'completed' && $shift->occurs_on?->isCurrentMonth());
        $upcoming = $shifts->filter(fn (Record $shift) => $shift->status === 'scheduled' && $shift->occurs_on?->between(today(), today()->addDays(7)))->sortBy('occurs_on');
        $overdue = $shifts->filter(fn (Record $shift) => $shift->status === 'scheduled' && $shift->occurs_on?->lt(today()));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Volunteers', 'icon' => 'hand-helping', 'stats' => [
                ['label' => 'Active', 'value' => (string) $volunteers->where('status', 'active')->count()],
                ['label' => 'Hours this month', 'value' => (string) round($month->sum(fn (Record $shift) => (float) $shift->value('hours')), 1)],
                ['label' => 'Shifts this month', 'value' => (string) $month->count()],
                ['label' => 'This week', 'value' => (string) $upcoming->count()],
                ['label' => 'Not marked', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Upcoming shifts', 'icon' => 'calendar-days', 'empty' => 'Nothing scheduled this week.',
                'rows' => $upcoming->take(10)->map(fn (Record $shift) => [
                    'label' => $shift->title, 'sub' => $volunteers->firstWhere('id', (int) $shift->value('volunteer'))?->title.($shift->value('location') ? ' · '.$shift->value('location') : ''), 'value' => $shift->occurs_on->format('D d M'), 'href' => $shift->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $shifts = $this->dated('shifts', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($shifts) {
            $group = $shifts->filter(fn (Record $shift) => $shift->occurs_on?->format('Y-m') === $month);

            return [$label, $group->where('status', 'completed')->count(), round($group->where('status', 'completed')->sum(fn (Record $shift) => (float) $shift->value('hours')), 1), $group->where('status', 'missed')->count(), $group->where('status', 'completed')->pluck('data.volunteer')->unique()->count()];
        })->values()->all();

        $volunteers = $this->records('volunteers')->get();
        $byVolunteer = $volunteers->sortByDesc(fn (Record $volunteer) => (float) $volunteer->value('_hours'))->map(fn (Record $volunteer) => [
            $volunteer->title, ucfirst((string) $volunteer->status), ucfirst((string) ($volunteer->value('availability') ?: 'any')), (int) $volunteer->value('_shifts'), (float) $volunteer->value('_hours'), (int) $volunteer->value('_missed'), $volunteer->value('_reliability') === null ? '—' : (int) $volunteer->value('_reliability').'%',
        ])->values()->all();

        $byLocation = $shifts->where('status', 'completed')->groupBy(fn (Record $shift) => $shift->value('location') ?: 'No location')->sortKeys()->map(fn ($group, $location) => [
            $location, $group->count(), round($group->sum(fn (Record $shift) => (float) $shift->value('hours')), 1), $group->pluck('data.volunteer')->unique()->count(),
        ])->values()->all();

        return [
            ['title' => 'Hours by month', 'columns' => ['Month', 'Shifts', 'Hours', 'Missed', 'Volunteers'], 'rows' => $byMonth],
            ['title' => 'Volunteers', 'columns' => ['Volunteer', 'Status', 'Availability', 'Shifts', 'Hours', 'Missed', 'Reliability'], 'rows' => $byVolunteer],
            ['title' => 'Shifts by location', 'columns' => ['Location', 'Shifts', 'Hours', 'Volunteers'], 'rows' => $byLocation],
        ];
    }
}
