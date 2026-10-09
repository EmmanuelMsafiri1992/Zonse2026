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
 * Tutoring: lessons are booked only for active students, last between a quarter of an hour and four
 * hours, and never overlap another lesson of the same tutor. A student cannot be paused or finished
 * with lessons still booked. Each student totals their lessons, hours, fees and no-shows.
 */
class TutoringLogic extends AppLogic
{
    public const MIN_MINUTES = 15;

    public const MAX_MINUTES = 240;

    public const DEFAULT_MINUTES = 60;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'students') {
            if ($existing && $payload['status'] !== 'active' && $this->linked('lessons', 'student', $existing)->where('status', 'booked')->exists()) {
                $errors['status'] = 'This student still has lessons booked.';
            }

            return $errors;
        }

        $student = ! empty($data['student']) ? $this->records('students')->find($data['student']) : null;
        if ($student && $student->status !== 'active' && $payload['status'] === 'booked' && (! $existing || (int) $existing->value('student') !== $student->id || $existing->status !== 'booked')) {
            $errors['data.student'] = $student->title.' is '.$student->status.'.';
        }
        $minutes = (int) ($data['duration'] ?? self::DEFAULT_MINUTES);
        if ($minutes < self::MIN_MINUTES || $minutes > self::MAX_MINUTES) {
            $errors['data.duration'] = 'A lesson lasts between '.self::MIN_MINUTES.' minutes and '.(self::MAX_MINUTES / 60).' hours.';
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The fee cannot be negative.';
        }
        if ($payload['status'] === 'booked' && filled($payload['assignee_id'] ?? null) && filled($payload['occurs_on'] ?? null) && filled($data['start_time'] ?? null)) {
            $start = TimetableLogic::minutes('00:00', $data['start_time']);
            $end = $start + $minutes;
            $clash = $this->records('lessons')->where('status', 'booked')->where('assignee_id', $payload['assignee_id'])->whereDate('occurs_on', Carbon::parse($payload['occurs_on']))
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(function (Record $lesson) use ($start, $end) {
                    $otherStart = TimetableLogic::minutes('00:00', (string) $lesson->value('start_time'));
                    $otherEnd = $otherStart + (int) ($lesson->value('duration') ?: self::DEFAULT_MINUTES);

                    return $otherStart < $end && $otherEnd > $start;
                });
            if ($clash) {
                $errors['data.start_time'] = 'The tutor already has '.$clash->title.' at '.$clash->value('start_time').' that day.';
            }
        }
        if (in_array($payload['status'], ['done', 'no_show'], true) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
            $errors['status'] = 'A lesson in the future has not happened yet.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'lessons') {
            $record->occurs_on ??= today();
            $this->put($record, ['duration' => (int) ($record->value('duration') ?: self::DEFAULT_MINUTES)]);
            if (! $record->exists && blank($record->amount) && ($student = $this->parent($record, 'student'))) {
                $last = $this->linked('lessons', 'student', $student)->orderByDesc('id')->first();
                $record->amount = (float) ($last?->amount ?? 0);
            }

            return;
        }

        $lessons = $record->exists ? $this->linked('lessons', 'student', $record)->get() : collect();
        $done = $lessons->where('status', 'done');
        $this->put($record, [
            '_lessons' => $done->count(),
            '_hours' => round($done->sum(fn (Record $lesson) => (int) $lesson->value('duration')) / 60, 1),
            '_fees' => round($lessons->whereIn('status', ['done', 'no_show'])->sum('amount'), 2),
            '_no_shows' => $lessons->where('status', 'no_show')->count(),
            '_last_lesson' => $done->max(fn (Record $lesson) => $lesson->occurs_on?->toDateString()),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'lessons') {
            $this->recalculate($this->parent($record, 'student'));
            $this->recalculate($this->previousParent($record, 'student'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'lessons') {
            $this->recalculate($this->parent($record, 'student'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'students') {
            return match ($record->status) {
                'active' => [
                    'pause' => ['label' => 'Pause', 'icon' => 'pause', 'confirm' => 'Pause this student? No lessons can be booked until they resume.'],
                    'finish' => ['label' => 'Finish', 'icon' => 'flag', 'confirm' => 'Mark this student finished?'],
                ],
                'paused' => ['resume' => ['label' => 'Resume', 'icon' => 'play']],
                default => [],
            };
        }

        return $record->status === 'booked' ? [
            'done' => ['label' => 'Done', 'icon' => 'check', 'fields' => [
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $record->value('notes')],
            ]],
            'no_show' => ['label' => 'No-show', 'icon' => 'user-x', 'confirm' => 'Mark this lesson a no-show? The fee is still charged.'],
            'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this lesson?'],
        ] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'pause':
            case 'finish':
                if ($this->linked('lessons', 'student', $record)->where('status', 'booked')->exists()) {
                    throw ValidationException::withMessages(['status' => 'This student still has lessons booked.']);
                }
                $record->update(['status' => $action === 'pause' ? 'paused' : 'finished']);

                return $record->title.' is '.$record->fresh()->status.'.';
            case 'resume':
                $record->update(['status' => 'active']);

                return $record->title.' is active again.';
            case 'done':
                if ($record->occurs_on?->isFuture()) {
                    throw ValidationException::withMessages(['status' => 'A lesson in the future has not happened yet.']);
                }
                $record->update(['status' => 'done', 'data' => [...(array) $record->data, 'notes' => $request->input('notes', $record->value('notes'))]]);

                return 'Lesson done.';
            case 'no_show':
                $record->update(['status' => 'no_show']);

                return 'Lesson marked as a no-show.';
        }
        $record->update(['status' => 'cancelled']);

        return 'Lesson cancelled.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'students') {
            return [];
        }

        $lessons = $this->linked('lessons', 'student', $record)->orderByDesc('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Tutoring', 'icon' => 'presentation', 'stats' => [
                ['label' => 'Lessons done', 'value' => (string) (int) $record->value('_lessons')],
                ['label' => 'Hours', 'value' => (string) (float) $record->value('_hours')],
                ['label' => 'Fees', 'value' => $this->money($this->number($record, '_fees'))],
                ['label' => 'No-shows', 'value' => (string) (int) $record->value('_no_shows'), 'tone' => (int) $record->value('_no_shows') > 0 ? 'warning' : null],
                ['label' => 'Last lesson', 'value' => $record->value('_last_lesson') ? Carbon::parse($record->value('_last_lesson'))->format('d M Y') : '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Lessons', 'icon' => 'calendar', 'empty' => 'No lessons yet.',
                'rows' => $lessons->take(10)->map(fn (Record $lesson) => [
                    'label' => $lesson->title, 'sub' => $lesson->occurs_on?->format('d M Y').' '.$lesson->value('start_time').' · '.$lesson->value('duration').' min', 'value' => ucfirst(str_replace('_', ' ', $lesson->status)), 'href' => $lesson->url(),
                    'tone' => $lesson->status === 'no_show' ? 'danger' : ($lesson->status === 'booked' ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $lessons = $this->records('lessons')->get();
        $students = $this->records('students')->get()->pluck('title', 'id');
        $week = $lessons->filter(fn (Record $lesson) => $lesson->occurs_on?->isCurrentWeek());
        $month = $lessons->filter(fn (Record $lesson) => $lesson->occurs_on?->isCurrentMonth());
        $upcoming = $lessons->where('status', 'booked')->filter(fn (Record $lesson) => $lesson->occurs_on?->gte(today()))->sortBy(fn (Record $lesson) => $lesson->occurs_on->toDateString().' '.$lesson->value('start_time'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Tutoring', 'icon' => 'presentation', 'stats' => [
                ['label' => 'Active students', 'value' => (string) $this->records('students')->where('status', 'active')->count()],
                ['label' => 'Lessons this week', 'value' => (string) $week->whereIn('status', ['booked', 'done'])->count()],
                ['label' => 'Fees this month', 'value' => $this->money($month->whereIn('status', ['done', 'no_show'])->sum('amount'))],
                ['label' => 'No-shows this month', 'value' => (string) $month->where('status', 'no_show')->count(), 'tone' => $month->where('status', 'no_show')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Upcoming lessons', 'icon' => 'calendar', 'empty' => 'Nothing booked.',
                'rows' => $upcoming->take(10)->map(fn (Record $lesson) => [
                    'label' => ($students[(int) $lesson->value('student')] ?? '').' · '.$lesson->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $lesson->value('mode'))), 'value' => $lesson->occurs_on->format('D d M').' '.$lesson->value('start_time'), 'href' => $lesson->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $students = $this->records('students')->get();
        $byStudent = $students->sortBy('title')->map(fn (Record $student) => [
            $student->title, $student->value('subjects') ?: '—', ucfirst($student->status), (int) $student->value('_lessons'), (float) $student->value('_hours'), (int) $student->value('_no_shows'), $this->money($this->number($student, '_fees')),
        ])->values()->all();

        $lessons = $this->dated('lessons', $from, $to)->get();
        $tutors = User::whereIn('id', $lessons->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $byTutor = $lessons->groupBy(fn (Record $lesson) => $lesson->assignee_id ? ($tutors[$lesson->assignee_id] ?? 'Unknown') : 'Unassigned')->sortKeys()->map(fn ($group, $tutor) => [
            $tutor, $group->where('status', 'done')->count(), round($group->where('status', 'done')->sum(fn (Record $lesson) => (int) $lesson->value('duration')) / 60, 1), $group->where('status', 'no_show')->count(), $this->money($group->whereIn('status', ['done', 'no_show'])->sum('amount')),
        ])->values()->all();

        $totals = $this->sumByMonth($lessons->whereIn('status', ['done', 'no_show']));
        $byMonth = collect($this->months($from, $to))->map(fn (string $label, string $month) => [
            $label, $lessons->filter(fn (Record $lesson) => $lesson->status === 'done' && $lesson->occurs_on?->format('Y-m') === $month)->count(), $this->money($totals[$month] ?? 0),
        ])->values()->all();

        return [
            ['title' => 'Students', 'columns' => ['Student', 'Subjects', 'Status', 'Lessons', 'Hours', 'No-shows', 'Fees'], 'rows' => $byStudent],
            ['title' => 'Lessons by tutor', 'columns' => ['Tutor', 'Lessons', 'Hours', 'No-shows', 'Fees'], 'rows' => $byTutor],
            ['title' => 'Fees by month', 'columns' => ['Month', 'Lessons', 'Fees'], 'rows' => $byMonth],
        ];
    }
}
