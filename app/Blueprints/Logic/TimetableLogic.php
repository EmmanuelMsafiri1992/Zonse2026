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
 * Timetabling: a period ends after it starts, and no class, teacher or room is in two active periods
 * at once on the same day. Each period knows its length, and the week's load is totted up by teacher
 * and class.
 */
class TimetableLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $start = $data['start_time'] ?? null;
        $end = $data['end_time'] ?? null;

        if (filled($start) && filled($end) && strcmp($end, $start) <= 0) {
            $errors['data.end_time'] = 'The period must end after it starts.';
        }
        if ($payload['status'] !== 'active' || blank($start) || blank($end) || blank($data['day'] ?? null)) {
            return $errors;
        }

        $clashes = $this->records('periods')->where('status', 'active')->where('data->day', $data['day'])
            ->where('data->start_time', '<', $end)->where('data->end_time', '>', $start)
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get();
        $describe = fn (Record $period) => $period->title.' '.$period->value('start_time').'–'.$period->value('end_time');

        if ($clash = $clashes->first(fn (Record $period) => strcasecmp((string) $period->value('class_name'), (string) $data['class_name']) === 0)) {
            $errors['data.class_name'] = $data['class_name'].' already has '.$describe($clash).' on '.ucfirst($data['day']).'.';
        }
        if (filled($data['teacher'] ?? null) && ($clash = $clashes->first(fn (Record $period) => (int) $period->value('teacher') === (int) $data['teacher']))) {
            $errors['data.teacher'] = 'This teacher already has '.$describe($clash).' with '.$clash->value('class_name').' on '.ucfirst($data['day']).'.';
        }
        if (filled($data['room'] ?? null) && ($clash = $clashes->first(fn (Record $period) => strcasecmp((string) $period->value('room'), (string) $data['room']) === 0))) {
            $errors['data.room'] = $data['room'].' is taken by '.$describe($clash).' ('.$clash->value('class_name').') on '.ucfirst($data['day']).'.';
        }

        return $errors;
    }

    public static function minutes(?string $start, ?string $end): int
    {
        if (blank($start) || blank($end)) {
            return 0;
        }
        [$startHour, $startMinute] = array_map('intval', explode(':', $start) + [0, 0]);
        [$endHour, $endMinute] = array_map('intval', explode(':', $end) + [0, 0]);

        return max(0, ($endHour * 60 + $endMinute) - ($startHour * 60 + $startMinute));
    }

    public function saving(Record $record): void
    {
        $this->put($record, ['_minutes' => self::minutes($record->value('start_time'), $record->value('end_time'))]);
    }

    public function actions(Record $record): array
    {
        return $record->status === 'active'
            ? ['cancel' => ['label' => 'Cancel period', 'icon' => 'x', 'confirm' => 'Cancel this period? It frees the teacher and room.']]
            : ['restore' => ['label' => 'Restore', 'icon' => 'rotate-ccw']];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'restore') {
            $errors = $this->validate($this->app->entities['periods'], ['status' => 'active', 'data' => (array) $record->data], $record);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $record->update(['status' => 'active']);

            return 'Period restored.';
        }
        $record->update(['status' => 'cancelled']);

        return 'Period cancelled.';
    }

    public function recordCards(Record $record): array
    {
        $sameDay = $this->records('periods')->where('status', 'active')->where('data->day', $record->value('day'))
            ->where('data->class_name', $record->value('class_name'))->whereKeyNot($record->id)->get()->sortBy('data.start_time');
        $teacher = $record->value('teacher') ? User::find($record->value('teacher'))?->name : null;

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Period', 'icon' => 'calendar-range', 'stats' => [
                ['label' => 'When', 'value' => ucfirst((string) $record->value('day')).' '.$record->value('start_time').'–'.$record->value('end_time')],
                ['label' => 'Length', 'value' => (int) $record->value('_minutes').' min'],
                ['label' => 'Teacher', 'value' => $teacher ?: 'Unassigned', 'tone' => $teacher ? null : 'warning'],
                ['label' => 'Room', 'value' => $record->value('room') ?: 'Unassigned'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Same day for '.$record->value('class_name'), 'icon' => 'list', 'empty' => 'No other periods that day.',
                'rows' => $sameDay->map(fn (Record $period) => [
                    'label' => $period->title, 'sub' => $period->value('room') ?: 'No room', 'value' => $period->value('start_time').'–'.$period->value('end_time'), 'href' => $period->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $periods = $this->records('periods')->where('status', 'active')->get();
        $today = strtolower(today()->format('l'));
        $todays = $periods->where('data.day', $today)->sortBy('data.start_time');
        $teachers = User::whereIn('id', $todays->pluck('data.teacher')->filter()->unique())->pluck('name', 'id');
        $unassigned = $periods->filter(fn (Record $period) => blank($period->value('teacher')));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Timetable', 'icon' => 'calendar-range', 'stats' => [
                ['label' => 'Periods a week', 'value' => (string) $periods->count()],
                ['label' => 'Classes', 'value' => (string) $periods->pluck('data.class_name')->filter()->unique()->count()],
                ['label' => 'Teachers', 'value' => (string) $periods->pluck('data.teacher')->filter()->unique()->count()],
                ['label' => 'Hours a week', 'value' => (string) round($periods->sum(fn (Record $period) => (int) $period->value('_minutes')) / 60, 1)],
                ['label' => 'Without a teacher', 'value' => (string) $unassigned->count(), 'tone' => $unassigned->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => "Today's periods", 'icon' => 'clock', 'empty' => 'No periods today.',
                'rows' => $todays->take(12)->map(fn (Record $period) => [
                    'label' => $period->value('class_name').' · '.$period->title, 'sub' => ($teachers[(int) $period->value('teacher')] ?? 'No teacher').' · '.($period->value('room') ?: 'no room'), 'value' => $period->value('start_time').'–'.$period->value('end_time'), 'href' => $period->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $periods = $this->records('periods')->where('status', 'active')->get();
        $teachers = User::whereIn('id', $periods->pluck('data.teacher')->filter()->unique())->pluck('name', 'id');
        $load = $periods->groupBy(fn (Record $period) => $period->value('teacher') ? ($teachers[(int) $period->value('teacher')] ?? 'Unknown') : 'Unassigned')->sortKeys()->map(fn ($group, $teacher) => [
            $teacher, $group->count(), round($group->sum(fn (Record $period) => (int) $period->value('_minutes')) / 60, 1), $group->pluck('data.class_name')->unique()->sort()->implode(', '),
        ])->values()->all();

        $byClass = $periods->groupBy(fn (Record $period) => $period->value('class_name') ?: 'Unknown')->sortKeys()->map(fn ($group, $class) => [
            $class, $group->count(), round($group->sum(fn (Record $period) => (int) $period->value('_minutes')) / 60, 1), $group->pluck('title')->unique()->sort()->implode(', '),
        ])->values()->all();

        $days = $this->app->entities['periods']->field('day')?->options ?? [];
        $byDay = collect($days)->map(fn (string $label, string $day) => [$label, $periods->where('data.day', $day)->count(), round($periods->where('data.day', $day)->sum(fn (Record $period) => (int) $period->value('_minutes')) / 60, 1)])->values()->all();

        return [
            ['title' => 'Teacher load', 'columns' => ['Teacher', 'Periods', 'Hours a week', 'Classes'], 'rows' => $load],
            ['title' => 'Class hours', 'columns' => ['Class', 'Periods', 'Hours a week', 'Subjects'], 'rows' => $byClass],
            ['title' => 'Periods by day', 'columns' => ['Day', 'Periods', 'Hours'], 'rows' => $byDay],
        ];
    }
}
