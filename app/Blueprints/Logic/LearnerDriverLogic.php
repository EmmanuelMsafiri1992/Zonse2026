<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Driving school: lessons are only booked for learners still learning with lessons left on their package and a
 * learner's licence valid on the day, and an instructor can't teach two lessons within the hour. Completing a
 * lesson uses one of the learner's lessons. A learner has one test booked at a time; booking it marks the learner
 * test booked, passing marks them passed and failing sends them back to learning. Booked lessons from earlier days
 * are marked missed each morning.
 */
class LearnerDriverLogic extends AppLogic
{
    /**
     * Minutes a lesson takes.
     */
    public const LESSON_MINUTES = 60;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'learners') {
            return $errors;
        }
        $learner = filled($data['learner'] ?? null) ? $this->records('learners')->find($data['learner']) : null;
        $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        $booking = $payload['status'] === 'booked';
        if ($learner && $booking) {
            if (in_array($learner->status, ['passed', 'left'], true)) {
                $errors['data.learner'] = $learner->title.' has '.($learner->status === 'passed' ? 'passed' : 'left').'.';
            } elseif (filled($expiry = $learner->value('learners_licence_expiry')) && Carbon::parse($expiry)->lt($date)) {
                $errors['data.learner'] = $learner->title.'\'s learner licence expires on '.Carbon::parse($expiry)->format('d M Y').'.';
            }
        }
        if ($entity->key === 'tests') {
            if ($learner && $booking && ($other = $this->linked('tests', 'learner', $learner)->where('status', 'booked')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first())) {
                $errors['data.learner'] = $learner->title.' already has a test booked on '.$other->occurs_on?->format('d M Y').'.';
            }

            return $errors;
        }
        if ($learner && $booking && ! $existing && filled($learner->value('lessons_remaining')) && (int) $learner->value('lessons_remaining') <= 0) {
            $errors['data.learner'] ??= $learner->title.' has no lessons left on the package.';
        }
        if ($booking && filled($data['instructor'] ?? null) && filled($data['start_time'] ?? null)
            && ($clash = $this->clash((int) $data['instructor'], $date, (string) $data['start_time'], $existing))) {
            $errors['data.instructor'] = 'The instructor has '.$clash->title.' at '.substr((string) $clash->value('start_time'), 0, 5).'.';
        }

        return $errors;
    }

    /**
     * Another booked lesson the instructor has within the hour.
     */
    protected function clash(int $instructor, Carbon $date, string $time, ?Record $existing): ?Record
    {
        $minutes = fn (string $value) => (int) substr($value, 0, 2) * 60 + (int) substr($value, 3, 2);

        return $this->records('lessons')->where('status', 'booked')->whereDate('occurs_on', $date->toDateString())
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->first(fn (Record $lesson) => (int) $lesson->value('instructor') === $instructor && abs($minutes((string) $lesson->value('start_time')) - $minutes($time)) < self::LESSON_MINUTES);
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'learners') {
            return;
        }
        $record->occurs_on ??= today();
        if (($learner = $this->parent($record, 'learner')) && $record->title !== $learner->title) {
            $record->title = $learner->title;
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'learners' || ! ($record->wasRecentlyCreated || $record->wasChanged('status')) || ! ($learner = $this->parent($record, 'learner'))) {
            return;
        }
        if ($record->entity === 'lessons') {
            if ($record->status === 'completed') {
                $learner->update(['status' => $learner->status === 'enrolled' ? 'learning' : $learner->status, 'data' => [...$learner->data, 'lessons_remaining' => max(0, (int) $learner->value('lessons_remaining') - 1)]]);
            }

            return;
        }
        $status = match ($record->status) {
            'booked' => 'test_booked',
            'passed' => 'passed',
            default => $learner->status === 'test_booked' ? 'learning' : null,
        };
        if ($status && $status !== $learner->status) {
            $learner->update(['status' => $status]);
        }
    }

    public function daily(Workspace $workspace): int
    {
        $missed = $this->records('lessons')->where('status', 'booked')->whereDate('occurs_on', '<', today()->toDateString())->get();
        $missed->each(fn (Record $lesson) => $lesson->update(['status' => 'missed']));

        return $missed->count();
    }

    public function actions(Record $record): array
    {
        if ($record->status !== 'booked') {
            return [];
        }
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x']];

        return match ($record->entity) {
            'lessons' => ['complete' => ['label' => 'Completed', 'icon' => 'check', 'fields' => [['name' => 'feedback', 'label' => 'Feedback', 'type' => 'textarea', 'value' => $record->value('feedback')]]], 'missed' => ['label' => 'Missed', 'icon' => 'user-x'], ...$cancel],
            'tests' => ['pass' => ['label' => 'Passed', 'icon' => 'award'], 'fail' => ['label' => 'Failed', 'icon' => 'x-circle'], ...$cancel],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'complete':
                $record->update(['status' => 'completed', 'data' => [...$record->data, 'feedback' => $request->input('feedback', $record->value('feedback'))]]);
                $left = (int) $this->parent($record, 'learner')?->value('lessons_remaining');

                return $record->title.'\'s lesson completed, '.$left.' '.str('lesson')->plural($left).' left.';
            case 'missed':
                $record->update(['status' => 'missed']);

                return $record->title.' missed the lesson.';
            case 'pass':
                $record->update(['status' => 'passed']);

                return $record->title.' passed the driving test.';
            case 'fail':
                $record->update(['status' => 'failed']);

                return $record->title.' failed the driving test.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s '.($record->entity === 'tests' ? 'test' : 'lesson').' cancelled.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'learners') {
            return [];
        }
        $lessons = $this->linked('lessons', 'learner', $record)->get();
        $tests = $this->linked('tests', 'learner', $record)->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Progress', 'icon' => 'car-front', 'stats' => [
            ['label' => 'Lessons taken', 'value' => $lessons->where('status', 'completed')->count()],
            ['label' => 'Lessons missed', 'value' => $lessons->where('status', 'missed')->count()],
            ['label' => 'Lessons left', 'value' => (int) $record->value('lessons_remaining')],
            ['label' => 'Test attempts', 'value' => $tests->whereIn('status', ['passed', 'failed'])->count()],
        ]]]];
    }

    public function homeCards(): array
    {
        $today = $this->records('lessons')->where('status', 'booked')->whereDate('occurs_on', today()->toDateString())->get();
        $names = User::query()->whereIn('id', $today->map(fn (Record $lesson) => $lesson->value('instructor'))->filter()->unique())->pluck('name', 'id');
        $active = $this->records('learners')->whereIn('status', ['enrolled', 'learning', 'test_booked'])->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Today\'s lessons', 'icon' => 'car-front', 'empty' => 'No lessons booked today.',
                'rows' => $today->sortBy(fn (Record $lesson) => (string) $lesson->value('start_time'))
                    ->map(fn (Record $lesson) => ['label' => $lesson->title, 'sub' => ($names[$lesson->value('instructor')] ?? 'No instructor').(filled($lesson->value('vehicle')) ? ' · '.$lesson->value('vehicle') : ''), 'value' => substr((string) $lesson->value('start_time'), 0, 5), 'href' => $lesson->url()])
                    ->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Learners', 'icon' => 'user-round', 'stats' => [
                ['label' => 'Learning', 'value' => $active->count()],
                ['label' => 'Two lessons or fewer left', 'value' => $active->filter(fn (Record $learner) => filled($learner->value('lessons_remaining')) && (int) $learner->value('lessons_remaining') <= 2)->count()],
                ['label' => 'Licence expiring in 30 days', 'value' => $active->filter(fn (Record $learner) => filled($expiry = $learner->value('learners_licence_expiry')) && Carbon::parse($expiry)->lte(today()->addDays(30)))->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $tests = $this->dated('tests', $from, $to)->whereIn('status', ['passed', 'failed'])->get();
        $lessons = $this->dated('lessons', $from, $to)->whereIn('status', ['completed', 'missed'])->get();
        $names = User::query()->whereIn('id', $lessons->map(fn (Record $lesson) => $lesson->value('instructor'))->filter()->unique())->pluck('name', 'id');

        return [
            ['title' => 'Pass rate by testing centre', 'columns' => ['Testing centre', 'Tests', 'Passed', 'Pass rate'], 'rows' => $tests
                ->groupBy(fn (Record $test) => filled($test->value('testing_centre')) ? trim((string) $test->value('testing_centre')) : 'Not given')->sortKeys()
                ->map(fn ($group, string $centre) => [$centre, $group->count(), $group->where('status', 'passed')->count(), round($group->where('status', 'passed')->count() / $group->count() * 100).'%'])
                ->values()->all()],
            ['title' => 'Lessons by instructor', 'columns' => ['Instructor', 'Completed', 'Missed'], 'rows' => $lessons
                ->groupBy(fn (Record $lesson) => $names[$lesson->value('instructor')] ?? 'No instructor')->sortKeys()
                ->map(fn ($group, string $instructor) => [$instructor, $group->where('status', 'completed')->count(), $group->where('status', 'missed')->count()])
                ->values()->all()],
        ];
    }
}
