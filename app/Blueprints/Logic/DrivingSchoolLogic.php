<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Driving school: lessons are booked only for active learners who still have lessons left in their
 * package, never two in the same car or with the same instructor at the same time. A learner books
 * a test only with a learner's licence, and is licensed only after a test was booked.
 */
class DrivingSchoolLogic extends AppLogic
{
    /** Lesson statuses that use up a lesson from the package. */
    public const USED = ['booked', 'completed', 'missed'];

    public const LEARNING = ['active', 'test_booked'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'learners') {
            if ((int) ($data['lessons_bought'] ?? 0) < 0) {
                $errors['data.lessons_bought'] = 'Lessons bought cannot be negative.';
            }
            if ($existing && (int) ($data['lessons_bought'] ?? 0) < (int) $existing->value('_lessons_used')) {
                $errors['data.lessons_bought'] = 'This learner has already used '.$existing->value('_lessons_used').' lessons.';
            }
            if ($payload['status'] === 'test_booked' && blank($data['learner_licence'] ?? null)) {
                $errors['data.learner_licence'] = 'A test is booked only with a learner licence number.';
            }
            if ($payload['status'] === 'licensed' && ($existing?->status ?? 'active') !== 'test_booked' && ($existing?->status ?? 'active') !== 'licensed') {
                $errors['status'] = 'Book the test before marking the learner licensed.';
            }

            return $errors;
        }

        $learner = ! empty($data['learner']) ? $this->records('learners')->find($data['learner']) : null;
        $newBooking = $payload['status'] === 'booked' && (! $existing || $existing->status !== 'booked' || (int) $existing->value('learner') !== $learner?->id);
        if ($learner && $newBooking) {
            if (! in_array($learner->status, self::LEARNING, true)) {
                $errors['data.learner'] = $learner->title.' is '.str_replace('_', ' ', $learner->status).' and cannot book lessons.';
            } elseif ($this->remaining($learner, $existing) < 1) {
                $errors['data.learner'] = $learner->title.' has used every lesson in the package.';
            }
        }
        if ($payload['status'] === 'booked' && filled($payload['occurs_on'] ?? null) && filled($data['start_time'] ?? null)) {
            $sameSlot = $this->records('lessons')->where('status', 'booked')->whereDate('occurs_on', Carbon::parse($payload['occurs_on']))
                ->where('data->start_time', $data['start_time'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get();
            if (filled($data['vehicle'] ?? null) && $sameSlot->contains(fn (Record $lesson) => strcasecmp((string) $lesson->value('vehicle'), $data['vehicle']) === 0)) {
                $errors['data.vehicle'] = $data['vehicle'].' is already booked at '.$data['start_time'].' that day.';
            }
            if (filled($payload['assignee_id'] ?? null) && $sameSlot->contains(fn (Record $lesson) => (int) $lesson->assignee_id === (int) $payload['assignee_id'])) {
                $errors['assignee_id'] = 'This instructor already has a lesson at '.$data['start_time'].' that day.';
            }
        }
        if ($payload['status'] === 'completed' && blank($data['feedback'] ?? null)) {
            $errors['data.feedback'] = 'Give the learner feedback when completing a lesson.';
        }

        return $errors;
    }

    /** Lessons left in the learner's package, ignoring one lesson that is being edited. */
    protected function remaining(Record $learner, ?Record $except = null): int
    {
        $used = $this->linked('lessons', 'learner', $learner)->whereIn('status', self::USED)->when($except, fn ($query) => $query->whereKeyNot($except->id))->count();

        return (int) $learner->value('lessons_bought') - $used;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'lessons') {
            $record->occurs_on ??= today();

            return;
        }

        $lessons = $record->exists ? $this->linked('lessons', 'learner', $record)->get() : collect();
        $used = $lessons->whereIn('status', self::USED)->count();
        $this->put($record, [
            '_lessons_used' => $used,
            '_lessons_done' => $lessons->where('status', 'completed')->count(),
            '_lessons_remaining' => max(0, (int) $record->value('lessons_bought') - $used),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'lessons') {
            $this->recalculate($this->parent($record, 'learner'));
            $this->recalculate($this->previousParent($record, 'learner'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'lessons') {
            $this->recalculate($this->parent($record, 'learner'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'learners') {
            return match ($record->status) {
                'active' => ['book_test' => ['label' => 'Book test', 'icon' => 'calendar-check', 'fields' => [
                    ['name' => 'learner_licence', 'label' => 'Learner licence number', 'type' => 'text', 'value' => $record->value('learner_licence')],
                ]]],
                'test_booked' => [
                    'passed' => ['label' => 'Passed', 'icon' => 'award'],
                    'failed' => ['label' => 'Failed', 'icon' => 'rotate-ccw', 'confirm' => 'Put the learner back to active for more lessons?'],
                ],
                default => [],
            };
        }

        return $record->status === 'booked' ? [
            'complete' => ['label' => 'Completed', 'icon' => 'check', 'fields' => [
                ['name' => 'feedback', 'label' => 'Feedback', 'type' => 'textarea', 'value' => $record->value('feedback')],
            ]],
            'missed' => ['label' => 'Missed', 'icon' => 'user-x', 'confirm' => 'Mark this lesson missed? It still counts against the package.'],
            'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this lesson? It goes back into the package.'],
        ] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'book_test':
                $licence = $request->validate(['learner_licence' => ['required', 'string']])['learner_licence'];
                $record->update(['status' => 'test_booked', 'data' => [...(array) $record->data, 'learner_licence' => $licence]]);

                return 'Test booked for '.$record->title.'.';
            case 'passed':
                $record->update(['status' => 'licensed']);

                return $record->title.' is licensed.';
            case 'failed':
                $record->update(['status' => 'active']);

                return $record->title.' is back on lessons.';
            case 'complete':
                $feedback = $request->validate(['feedback' => ['required', 'string']])['feedback'];
                $record->update(['status' => 'completed', 'data' => [...(array) $record->data, 'feedback' => $feedback]]);

                return 'Lesson completed. '.$this->parent($record, 'learner')?->fresh()?->value('_lessons_remaining').' lessons left in the package.';
            case 'missed':
                $record->update(['status' => 'missed']);

                return 'Lesson marked missed.';
        }
        $record->update(['status' => 'cancelled']);

        return 'Lesson cancelled.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'learners') {
            return [];
        }

        $lessons = $this->linked('lessons', 'learner', $record)->orderByDesc('occurs_on')->get();
        $codes = $this->app->entities['learners']->field('licence_code')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Package', 'icon' => 'car-front', 'stats' => [
                ['label' => 'Licence code', 'value' => $codes[$record->value('licence_code')] ?? ($record->value('licence_code') ?: '—')],
                ['label' => 'Bought', 'value' => (string) (int) $record->value('lessons_bought')],
                ['label' => 'Completed', 'value' => (string) (int) $record->value('_lessons_done')],
                ['label' => 'Missed', 'value' => (string) $lessons->where('status', 'missed')->count(), 'tone' => $lessons->where('status', 'missed')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Remaining', 'value' => (string) (int) $record->value('_lessons_remaining'), 'tone' => (int) $record->value('_lessons_remaining') > 0 ? 'success' : 'warning'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Lessons', 'icon' => 'calendar', 'empty' => 'No lessons yet.',
                'rows' => $lessons->take(10)->map(fn (Record $lesson) => [
                    'label' => $lesson->title, 'sub' => $lesson->occurs_on?->format('d M Y').' '.$lesson->value('start_time').' · '.($lesson->value('vehicle') ?: 'no car'), 'value' => ucfirst($lesson->status), 'href' => $lesson->url(),
                    'tone' => $lesson->status === 'missed' ? 'danger' : ($lesson->status === 'booked' ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $learners = $this->records('learners')->get();
        $lessons = $this->records('lessons')->get();
        $todays = $lessons->where('status', 'booked')->filter(fn (Record $lesson) => $lesson->occurs_on?->isToday())->sortBy('data.start_time');
        $names = $learners->pluck('title', 'id');
        $thisYear = $learners->filter(fn (Record $learner) => $learner->status === 'licensed' && $learner->updated_at?->isCurrentYear())->count();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Driving school', 'icon' => 'car-front', 'stats' => [
                ['label' => 'Learning', 'value' => (string) $learners->whereIn('status', self::LEARNING)->count()],
                ['label' => 'Tests booked', 'value' => (string) $learners->where('status', 'test_booked')->count()],
                ['label' => 'Licensed this year', 'value' => (string) $thisYear, 'tone' => 'success'],
                ['label' => 'Lessons today', 'value' => (string) $todays->count()],
                ['label' => 'Packages used up', 'value' => (string) $learners->whereIn('status', self::LEARNING)->filter(fn (Record $learner) => (int) $learner->value('_lessons_remaining') < 1)->count(), 'tone' => 'warning'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => "Today's lessons", 'icon' => 'calendar', 'empty' => 'No lessons booked today.',
                'rows' => $todays->take(10)->map(fn (Record $lesson) => [
                    'label' => ($names[(int) $lesson->value('learner')] ?? '').' · '.$lesson->title, 'sub' => $lesson->value('vehicle') ?: 'No car assigned', 'value' => (string) $lesson->value('start_time'), 'href' => $lesson->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $learners = $this->records('learners')->get();
        $codes = $this->app->entities['learners']->field('licence_code')?->options ?? [];
        $byCode = collect($codes)->map(fn (string $label, string $code) => [
            $label, $learners->where('data.licence_code', $code)->whereIn('status', self::LEARNING)->count(), $learners->where('data.licence_code', $code)->where('status', 'licensed')->count(),
            $learners->where('data.licence_code', $code)->where('status', 'dropped')->count(), $this->money($learners->where('data.licence_code', $code)->sum('amount')),
        ])->values()->all();

        $lessons = $this->dated('lessons', $from, $to)->get();
        $instructors = User::whereIn('id', $lessons->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $byInstructor = $lessons->groupBy(fn (Record $lesson) => $lesson->assignee_id ? ($instructors[$lesson->assignee_id] ?? 'Unknown') : 'Unassigned')->sortKeys()->map(fn ($group, $instructor) => [
            $instructor, $group->count(), $group->where('status', 'completed')->count(), $group->where('status', 'missed')->count(), $group->where('status', 'cancelled')->count(),
        ])->values()->all();

        $byVehicle = $lessons->whereIn('status', ['completed', 'booked'])->groupBy(fn (Record $lesson) => $lesson->value('vehicle') ?: 'No car')->sortKeys()->map(fn ($group, $vehicle) => [$vehicle, $group->count()])->values()->all();

        return [
            ['title' => 'Learners by licence code', 'columns' => ['Code', 'Learning', 'Licensed', 'Dropped', 'Package sales'], 'rows' => $byCode],
            ['title' => 'Lessons by instructor', 'columns' => ['Instructor', 'Lessons', 'Completed', 'Missed', 'Cancelled'], 'rows' => $byInstructor],
            ['title' => 'Vehicle use', 'columns' => ['Vehicle', 'Lessons'], 'rows' => $byVehicle],
        ];
    }
}
