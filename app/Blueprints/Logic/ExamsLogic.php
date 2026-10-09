<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Exams & report cards: marks stay between zero and the exam's maximum, one per student per exam, and
 * get a letter grade from their percentage. Marks are entered while an exam is marking and locked
 * once it is published. A report card compiles the student's average and class position from the
 * published exams of its term and class, and is issued only with a teacher's comment.
 */
class ExamsLogic extends AppLogic
{
    /** Letter grade => minimum percentage. */
    public const GRADES = ['A' => 80, 'B' => 70, 'C' => 60, 'D' => 50, 'E' => 40, 'F' => 0];

    public const PASS_MARK = 50;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'exams') {
            if ((float) ($data['max_mark'] ?? 0) <= 0) {
                $errors['data.max_mark'] = 'The maximum mark must be more than zero.';
            }
            if ($payload['status'] === 'published' && (! $existing || ! $this->linked('marks', 'exam', $existing)->exists())) {
                $errors['status'] = 'Enter marks before publishing the exam.';
            }
            if ($existing?->status === 'published' && (float) ($data['max_mark'] ?? 0) !== (float) $existing->value('max_mark')) {
                $errors['data.max_mark'] = 'The maximum mark is locked once results are published.';
            }

            return $errors;
        }

        if ($entity->key === 'marks') {
            $exam = ! empty($data['exam']) ? $this->records('exams')->find($data['exam']) : null;
            if ($exam?->status === 'published') {
                $errors['data.exam'] = $exam->title.' is published; its marks are locked.';
            }
            $mark = (float) ($data['mark'] ?? 0);
            if ($mark < 0 || ($exam && $mark > (float) $exam->value('max_mark'))) {
                $errors['data.mark'] = 'The mark is between 0 and '.($exam ? (float) $exam->value('max_mark') : 'the maximum').'.';
            }
            if ($exam && filled($payload['title'] ?? null) && $this->linked('marks', 'exam', $exam)->where('title', $payload['title'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['title'] = $payload['title'].' already has a mark for '.$exam->title.'.';
            }

            return $errors;
        }

        if (filled($data['average'] ?? null) && ((float) $data['average'] < 0 || (float) $data['average'] > 100)) {
            $errors['data.average'] = 'The average is a percentage.';
        }
        if (filled($data['position'] ?? null) && (int) $data['position'] < 1) {
            $errors['data.position'] = 'Positions start at 1.';
        }
        if ($payload['status'] === 'issued') {
            if (blank($data['teacher_comment'] ?? null)) {
                $errors['data.teacher_comment'] = "A report card is issued with the teacher's comment.";
            }
            if (blank($data['average'] ?? null)) {
                $errors['data.average'] = 'Compile the results before issuing the report card.';
            }
        }

        return $errors;
    }

    public static function grade(float $percentage): string
    {
        foreach (self::GRADES as $grade => $minimum) {
            if ($percentage >= $minimum) {
                return $grade;
            }
        }

        return 'F';
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'exams') {
            $record->occurs_on ??= today();
            $marks = $record->exists ? $this->linked('marks', 'exam', $record)->get() : collect();
            $max = (float) $record->value('max_mark') ?: 100;
            $percentages = $marks->map(fn (Record $mark) => $this->number($mark, 'mark') / $max * 100);
            $this->put($record, [
                '_entered' => $marks->count(),
                '_average' => $marks->isEmpty() ? null : round($percentages->avg(), 1),
                '_highest' => $marks->isEmpty() ? null : $marks->max(fn (Record $mark) => $this->number($mark, 'mark')),
                '_pass_rate' => $marks->isEmpty() ? null : round($percentages->filter(fn (float $pct) => $pct >= self::PASS_MARK)->count() / $marks->count() * 100),
            ]);
            if ($record->status === 'scheduled' && $marks->isNotEmpty()) {
                $record->status = 'marking';
            }

            return;
        }

        if ($record->entity === 'marks') {
            $exam = $this->parent($record, 'exam');
            $max = (float) ($exam?->value('max_mark') ?: 100);
            $percentage = round($this->number($record, 'mark') / $max * 100, 1);
            $this->put($record, ['grade' => self::grade($percentage), '_percentage' => $percentage]);

            return;
        }

        if ($record->status === 'issued') {
            $record->occurs_on ??= today();
        }
        $this->put($record, ['grade' => filled($record->value('average')) ? self::grade((float) $record->value('average')) : null]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'marks') {
            $this->recalculate($this->parent($record, 'exam'));
            $this->recalculate($this->previousParent($record, 'exam'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'marks') {
            $this->recalculate($this->parent($record, 'exam'));
        }
    }

    /**
     * Average percentage per student across the published exams of a term and class.
     *
     * @return array<string, float> student name => average
     */
    protected function averages(string $term, string $className): array
    {
        $exams = $this->records('exams')->where('status', 'published')->where('data->term', $term)->where('data->class_name', $className)->get();
        if ($exams->isEmpty()) {
            return [];
        }
        $marks = $this->records('marks')->whereIn('data->exam', $exams->pluck('id'))->get();
        $averages = $marks->groupBy('title')->map(fn ($group) => round($group->avg(fn (Record $mark) => $this->number($mark, '_percentage')), 1));

        return $averages->sortDesc()->all();
    }

    public function actions(Record $record): array
    {
        return match ($record->entity) {
            'exams' => match ($record->status) {
                'scheduled' => ['start_marking' => ['label' => 'Start marking', 'icon' => 'pen']],
                'marking' => ['publish' => ['label' => 'Publish results', 'icon' => 'megaphone', 'confirm' => 'Publish the results? Marks are locked afterwards.']],
                default => [],
            },
            'marks' => $record->status === 'entered' ? ['moderate' => ['label' => 'Moderated', 'icon' => 'check-check']] : [],
            default => $record->status === 'draft' ? [
                'compile' => ['label' => 'Compile results', 'icon' => 'calculator'],
                'issue' => ['label' => 'Issue', 'icon' => 'send', 'fields' => [
                    ['name' => 'teacher_comment', 'label' => "Teacher's comment", 'type' => 'textarea', 'value' => $record->value('teacher_comment')],
                ]],
            ] : [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'start_marking':
                $record->update(['status' => 'marking']);

                return 'Marking has started.';
            case 'publish':
                if ((int) $record->value('_entered') < 1) {
                    throw ValidationException::withMessages(['status' => 'Enter marks before publishing the exam.']);
                }
                $record->update(['status' => 'published']);

                return 'Results published: average '.$record->value('_average').'%, pass rate '.$record->value('_pass_rate').'%.';
            case 'moderate':
                $record->update(['status' => 'moderated']);

                return 'Mark moderated.';
            case 'compile':
                $averages = $this->averages((string) $record->value('term'), (string) $record->value('class_name'));
                if (! array_key_exists($record->title, $averages)) {
                    throw ValidationException::withMessages(['title' => 'No published exam in '.$record->value('term').' for '.$record->value('class_name').' has a mark for '.$record->title.'.']);
                }
                $position = array_search($record->title, array_keys($averages), true) + 1;
                $record->update(['data' => [...(array) $record->data, 'average' => $averages[$record->title], 'position' => $position]]);

                return $record->title.' averaged '.$averages[$record->title].'%, position '.$position.' of '.count($averages).'.';
        }
        $comment = $request->validate(['teacher_comment' => ['required', 'string']])['teacher_comment'];
        if (blank($record->value('average'))) {
            throw ValidationException::withMessages(['teacher_comment' => 'Compile the results before issuing the report card.']);
        }
        $record->update(['status' => 'issued', 'occurs_on' => today(), 'data' => [...(array) $record->data, 'teacher_comment' => $comment]]);

        return 'Report card issued for '.$record->title.'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'exams') {
            return [];
        }

        $marks = $this->linked('marks', 'exam', $record)->get()->sortByDesc(fn (Record $mark) => $this->number($mark, 'mark'));
        $grades = collect(array_keys(self::GRADES))->map(fn (string $grade) => $grade.': '.$marks->where('data.grade', $grade)->count())->implode(' · ');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Results', 'icon' => 'file-badge', 'stats' => [
                ['label' => 'Marks entered', 'value' => (string) (int) $record->value('_entered')],
                ['label' => 'Average', 'value' => $record->value('_average') === null ? '—' : $record->value('_average').'%'],
                ['label' => 'Highest', 'value' => $record->value('_highest') === null ? '—' : $record->value('_highest').' / '.(float) $record->value('max_mark')],
                ['label' => 'Pass rate', 'value' => $record->value('_pass_rate') === null ? '—' : $record->value('_pass_rate').'%', 'tone' => (int) $record->value('_pass_rate') < self::PASS_MARK ? 'danger' : 'success'],
                ['label' => 'Grades', 'value' => $grades],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Marks', 'icon' => 'check-check', 'empty' => 'No marks entered.',
                'rows' => $marks->take(15)->map(fn (Record $mark) => [
                    'label' => $mark->title, 'sub' => $mark->value('comment') ?: ucfirst($mark->status), 'value' => $this->number($mark, 'mark').' ('.$mark->value('grade').')', 'href' => $mark->url(),
                    'tone' => $this->number($mark, '_percentage') < self::PASS_MARK ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $exams = $this->records('exams')->get();
        $reports = $this->records('reports')->get();
        $marking = $exams->where('status', 'marking')->sortBy('occurs_on');
        $upcoming = $exams->where('status', 'scheduled')->filter(fn (Record $exam) => $exam->occurs_on?->gte(today()));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Exams', 'icon' => 'file-badge', 'stats' => [
                ['label' => 'Upcoming', 'value' => (string) $upcoming->count()],
                ['label' => 'Being marked', 'value' => (string) $marking->count(), 'tone' => $marking->isNotEmpty() ? 'warning' : null],
                ['label' => 'Published', 'value' => (string) $exams->where('status', 'published')->count()],
                ['label' => 'Report cards to issue', 'value' => (string) $reports->where('status', 'draft')->count(), 'tone' => $reports->where('status', 'draft')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Marking in progress', 'icon' => 'pen', 'empty' => 'Nothing is being marked.',
                'rows' => $marking->take(10)->map(fn (Record $exam) => [
                    'label' => $exam->title, 'sub' => $exam->value('class_name').' · '.$exam->value('subject'), 'value' => $exam->value('_entered').' marks', 'href' => $exam->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $exams = $this->dated('exams', $from, $to)->where('status', 'published')->get();
        $bySubject = $exams->groupBy(fn (Record $exam) => $exam->value('subject') ?: 'Unknown')->sortKeys()->map(fn ($group, $subject) => [
            $subject, $group->count(), $group->sum(fn (Record $exam) => (int) $exam->value('_entered')),
            round($group->avg(fn (Record $exam) => (float) $exam->value('_average')), 1).'%', round($group->avg(fn (Record $exam) => (float) $exam->value('_pass_rate'))).'%',
        ])->values()->all();

        $reports = $this->records('reports')->get();
        $byClass = $reports->groupBy(fn (Record $report) => ($report->value('class_name') ?: 'Unknown').' · '.$report->value('term'))->sortKeys()->map(fn ($group, $class) => [
            $class, $group->count(), $group->where('status', 'issued')->count(), round($group->filter(fn (Record $report) => filled($report->value('average')))->avg(fn (Record $report) => (float) $report->value('average')) ?? 0, 1).'%',
        ])->values()->all();

        return [
            ['title' => 'Results by subject', 'columns' => ['Subject', 'Exams', 'Marks', 'Average', 'Pass rate'], 'rows' => $bySubject],
            ['title' => 'Report cards by class', 'columns' => ['Class · term', 'Reports', 'Issued', 'Average'], 'rows' => $byClass],
        ];
    }
}
