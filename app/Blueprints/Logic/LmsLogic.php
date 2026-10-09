<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Learning management: a course is published only once it has a published lesson, lessons are
 * numbered uniquely within their course, learners enrol only on published courses and pay the course
 * price, and an enrolment's status follows its progress: not started, in progress, completed.
 */
class LmsLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'courses') {
            if ((float) ($data['price'] ?? 0) < 0) {
                $errors['data.price'] = 'The price cannot be negative.';
            }
            if ($payload['status'] === 'published' && (! $existing || ! $this->linked('lessons', 'course', $existing)->where('status', 'published')->exists())) {
                $errors['status'] = 'Publish at least one lesson before publishing the course.';
            }
            if ($payload['status'] === 'archived' && $existing && $this->linked('enrolments', 'course', $existing)->whereIn('status', ['enrolled', 'in_progress'])->exists()) {
                $errors['status'] = 'Learners are still working through this course.';
            }

            return $errors;
        }

        $course = ! empty($data['course']) ? $this->records('courses')->find($data['course']) : null;
        if ($entity->key === 'lessons') {
            if (filled($data['order'] ?? null) && (int) $data['order'] < 1) {
                $errors['data.order'] = 'Lesson order starts at 1.';
            }
            if ($course && filled($data['order'] ?? null) && $this->linked('lessons', 'course', $course)->where('data->order', (int) $data['order'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['data.order'] = 'Lesson '.(int) $data['order'].' already exists in '.$course->title.'.';
            }
            if ($course && $course->status === 'archived' && (! $existing || (int) $existing->value('course') !== $course->id)) {
                $errors['data.course'] = $course->title.' is archived.';
            }

            return $errors;
        }

        if ($course && $course->status !== 'published' && (! $existing || (int) $existing->value('course') !== $course->id)) {
            $errors['data.course'] = $course->title.' is not published yet.';
        }
        $progress = (float) ($data['progress'] ?? 0);
        if ($progress < 0 || $progress > 100) {
            $errors['data.progress'] = 'Progress is between 0 and 100.';
        }
        if ($payload['status'] === 'completed' && $progress < 100) {
            $errors['status'] = 'An enrolment is completed at 100% progress.';
        }
        if ($course && (float) ($payload['amount'] ?? 0) > (float) $course->value('price')) {
            $errors['amount'] = 'The learner cannot pay more than the course price of '.$this->money((float) $course->value('price')).'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'courses') {
            $lessons = $record->exists ? $this->linked('lessons', 'course', $record)->get() : collect();
            $enrolments = $record->exists ? $this->linked('enrolments', 'course', $record)->get() : collect();
            $active = $enrolments->whereIn('status', ['enrolled', 'in_progress', 'completed']);
            $this->put($record, [
                '_lessons' => $lessons->count(),
                '_published_lessons' => $lessons->where('status', 'published')->count(),
                '_enrolled' => $active->count(),
                '_completed' => $enrolments->where('status', 'completed')->count(),
                '_completion_rate' => $active->count() > 0 ? round($enrolments->where('status', 'completed')->count() / $active->count() * 100) : 0,
                '_revenue' => round($active->sum('amount'), 2),
            ]);

            return;
        }

        $course = $this->parent($record, 'course');
        if ($record->entity === 'lessons') {
            if (blank($record->value('order')) && $course) {
                $this->put($record, ['order' => (int) $this->linked('lessons', 'course', $course)->max('data->order') + 1]);
            }

            return;
        }

        $record->occurs_on ??= today();
        if (! $record->exists && $course && blank($record->amount)) {
            $record->amount = (float) $course->value('price');
        }
        $progress = (float) $record->value('progress');
        if ($record->status !== 'dropped') {
            $record->status = $progress >= 100 ? 'completed' : ($progress > 0 ? 'in_progress' : 'enrolled');
        }
        $this->put($record, ['_completed_on' => $record->status === 'completed' ? ($record->value('_completed_on') ?? today()->toDateString()) : null]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'courses') {
            $this->recalculate($this->parent($record, 'course'));
            $this->recalculate($this->previousParent($record, 'course'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity !== 'courses') {
            $this->recalculate($this->parent($record, 'course'));
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->entity) {
            'courses' => match ($record->status) {
                'draft' => ['publish' => ['label' => 'Publish', 'icon' => 'rocket']],
                'published' => ['archive' => ['label' => 'Archive', 'icon' => 'archive', 'confirm' => 'Archive this course? New learners cannot enrol.']],
                default => [],
            },
            'lessons' => $record->status === 'draft' ? ['publish' => ['label' => 'Publish', 'icon' => 'rocket']] : [],
            default => in_array($record->status, ['enrolled', 'in_progress'], true) ? [
                'progress' => ['label' => 'Update progress', 'icon' => 'trending-up', 'fields' => [
                    ['name' => 'progress', 'label' => 'Progress %', 'type' => 'number', 'value' => (int) $record->value('progress')],
                ]],
                'drop' => ['label' => 'Drop', 'icon' => 'user-minus', 'confirm' => 'Drop this learner from the course?'],
            ] : [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'publish':
                if ($record->entity === 'courses' && (int) $record->value('_published_lessons') < 1) {
                    throw ValidationException::withMessages(['status' => 'Publish at least one lesson before publishing the course.']);
                }
                $record->update(['status' => 'published']);

                return $record->title.' is published.';
            case 'archive':
                if ($this->linked('enrolments', 'course', $record)->whereIn('status', ['enrolled', 'in_progress'])->exists()) {
                    throw ValidationException::withMessages(['status' => 'Learners are still working through this course.']);
                }
                $record->update(['status' => 'archived']);

                return $record->title.' is archived.';
            case 'progress':
                $progress = (int) $request->validate(['progress' => ['required', 'integer', 'min:0', 'max:100']])['progress'];
                $record->update(['data' => [...(array) $record->data, 'progress' => $progress]]);

                return $progress >= 100 ? $record->title.' has completed the course.' : 'Progress is '.$progress.'%.';
        }
        $record->update(['status' => 'dropped']);

        return $record->title.' dropped the course.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'courses') {
            return [];
        }

        $lessons = $this->linked('lessons', 'course', $record)->get()->sortBy(fn (Record $lesson) => (int) $lesson->value('order'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Course', 'icon' => 'monitor-play', 'stats' => [
                ['label' => 'Lessons', 'value' => $record->value('_published_lessons').' of '.$record->value('_lessons').' published'],
                ['label' => 'Learners', 'value' => (string) (int) $record->value('_enrolled')],
                ['label' => 'Completed', 'value' => (string) (int) $record->value('_completed')],
                ['label' => 'Completion rate', 'value' => (int) $record->value('_completion_rate').'%'],
                ['label' => 'Revenue', 'value' => $this->money($this->number($record, '_revenue'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Lessons', 'icon' => 'play', 'empty' => 'No lessons yet.',
                'rows' => $lessons->map(fn (Record $lesson) => [
                    'label' => (int) $lesson->value('order').'. '.$lesson->title, 'sub' => $lesson->value('video_url') ? 'Video' : 'Reading', 'value' => ucfirst($lesson->status), 'href' => $lesson->url(),
                    'tone' => $lesson->status === 'draft' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $courses = $this->records('courses')->get();
        $enrolments = $this->records('enrolments')->get();
        $thisMonth = $enrolments->filter(fn (Record $enrolment) => $enrolment->occurs_on?->isCurrentMonth());

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'LMS', 'icon' => 'monitor-play', 'stats' => [
                ['label' => 'Published courses', 'value' => (string) $courses->where('status', 'published')->count()],
                ['label' => 'Learning now', 'value' => (string) $enrolments->whereIn('status', ['enrolled', 'in_progress'])->count()],
                ['label' => 'Enrolled this month', 'value' => (string) $thisMonth->count()],
                ['label' => 'Revenue this month', 'value' => $this->money($thisMonth->where('status', '!=', 'dropped')->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Top courses', 'icon' => 'trophy', 'empty' => 'No enrolments yet.',
                'rows' => $courses->sortByDesc(fn (Record $course) => (int) $course->value('_enrolled'))->take(8)->map(fn (Record $course) => [
                    'label' => $course->title, 'sub' => $course->value('_completed').' completed · '.(int) $course->value('_completion_rate').'%', 'value' => $course->value('_enrolled').' learners', 'href' => $course->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $courses = $this->records('courses')->get();
        $enrolments = $this->dated('enrolments', $from, $to)->get();
        $byCourse = $courses->sortBy('title')->map(fn (Record $course) => [
            $course->title, ucfirst((string) $course->value('level')), (int) $course->value('_enrolled'), (int) $course->value('_completed'), (int) $course->value('_completion_rate').'%', $this->money($this->number($course, '_revenue')),
        ])->values()->all();

        $totals = $this->sumByMonth($enrolments->where('status', '!=', 'dropped'));
        $byMonth = collect($this->months($from, $to))->map(fn (string $label, string $month) => [
            $label, $enrolments->filter(fn (Record $enrolment) => $enrolment->occurs_on?->format('Y-m') === $month)->count(), $this->money($totals[$month] ?? 0),
        ])->values()->all();

        return [
            ['title' => 'Courses', 'columns' => ['Course', 'Level', 'Learners', 'Completed', 'Completion', 'Revenue'], 'rows' => $byCourse],
            ['title' => 'Enrolments by month', 'columns' => ['Month', 'Enrolments', 'Revenue'], 'rows' => $byMonth],
        ];
    }
}
