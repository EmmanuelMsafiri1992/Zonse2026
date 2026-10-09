<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Rehabilitation and occupational therapy: a case is assessed, set goals and then treated in
 * sessions that each score the patient's progress from 0 to 10. The case keeps its first and latest
 * score, the improvement between them and its attendance rate, and moves from assessment to active
 * on the first attended session. Attendance under 70% is flagged, and discharge needs the goals set.
 */
class RehabilitationLogic extends AppLogic
{
    /**
     * Attendance below this share of sessions is flagged.
     */
    protected const LOW_ATTENDANCE = 70;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'cases') {
            if (in_array($payload['status'], ['active', 'discharged'], true) && blank($data['goals'] ?? null)) {
                $errors['data.goals'] = 'Set the rehab goals after the assessment.';
            }

            return $errors;
        }

        $case = filled($data['case'] ?? null) ? $this->records('cases')->find($data['case']) : null;
        if ($case?->status === 'discharged' && (! $existing || (int) $existing->value('case') !== $case->id)) {
            $errors['data.case'] = $case->title.' has been discharged.';
        }
        if ($payload['status'] === 'attended') {
            $score = $data['progress_score'] ?? null;
            if ($score === null || $score === '') {
                $errors['data.progress_score'] = 'Score the patient\'s progress from 0 to 10.';
            } elseif ($score < 0 || $score > 10) {
                $errors['data.progress_score'] = 'The progress score runs from 0 to 10.';
            }
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
            $errors['occurs_on'] = 'Record sessions on or after the day they happen.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'cases' || ! $record->exists) {
            return;
        }
        $sessions = $this->linked('sessions', 'case', $record)->orderBy('occurs_on')->orderBy('id')->get();
        $scored = $sessions->where('status', 'attended')->filter(fn (Record $session) => $session->value('progress_score') !== null)->values();
        $first = $scored->first()?->value('progress_score');
        $latest = $scored->last()?->value('progress_score');
        $attendance = $sessions->isEmpty() ? null : (int) round($sessions->where('status', 'attended')->count() / $sessions->count() * 100);
        $this->put($record, [
            '_sessions' => $sessions->count(),
            '_attended' => $sessions->where('status', 'attended')->count(),
            '_attendance' => $attendance,
            '_low_attendance' => $attendance !== null && $sessions->count() >= 3 && $attendance < self::LOW_ATTENDANCE,
            '_first_score' => $first,
            '_latest_score' => $latest,
            '_improvement' => $first !== null && $latest !== null ? round((float) $latest - (float) $first, 1) : null,
        ]);
        if ($record->status === 'assessment' && $sessions->where('status', 'attended')->isNotEmpty() && filled($record->value('goals'))) {
            $record->status = 'active';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'sessions') {
            $this->recalculate($this->parent($record, 'case'));
            $this->recalculate($this->previousParent($record, 'case'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'sessions') {
            $this->recalculate($this->parent($record, 'case'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'cases' || $record->status === 'discharged') {
            return [];
        }

        return [
            'session' => ['label' => 'Record session', 'icon' => 'activity', 'fields' => [
                ['name' => 'activities', 'label' => 'Activities', 'type' => 'text'],
                ['name' => 'progress_score', 'label' => 'Progress (0–10)', 'type' => 'number'],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ]],
            'discharge' => ['label' => 'Discharge', 'icon' => 'log-out'],
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'discharge') {
            if (blank($record->value('goals'))) {
                throw ValidationException::withMessages(['status' => 'Set the rehab goals before discharging.']);
            }
            $record->update(['status' => 'discharged', 'data' => [...$record->data, '_discharged_on' => today()->toDateString()]]);
            $improvement = $record->fresh()->value('_improvement');

            return $record->title.' discharged'.($improvement !== null ? ' with progress '.$this->signed($improvement).' points.' : '.');
        }

        $input = $request->validate(['activities' => ['required', 'string', 'max:190'], 'progress_score' => ['required', 'numeric', 'min:0', 'max:10'], 'notes' => ['nullable', 'string']]);
        Record::create([
            'workspace_id' => $record->workspace_id,
            'blueprint' => $record->blueprint,
            'entity' => 'sessions',
            'title' => $input['activities'],
            'status' => 'attended',
            'occurs_on' => today(),
            'assignee_id' => $request->user()?->id,
            'data' => ['case' => $record->id, 'progress_score' => (float) $input['progress_score'], 'notes' => $input['notes'] ?? null],
        ]);

        return 'Session recorded for '.$record->title.'; progress '.$this->score($input['progress_score']).'/10.';
    }

    /**
     * A score without a needless ".0".
     */
    protected function score(mixed $score): string
    {
        return rtrim(rtrim(number_format((float) $score, 1, '.', ''), '0'), '.');
    }

    /**
     * A change in score with its sign, such as "+3" or "-0.5".
     */
    protected function signed(mixed $change): string
    {
        return ((float) $change > 0 ? '+' : '').$this->score($change);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'cases') {
            return [];
        }
        $sessions = $this->linked('sessions', 'case', $record)->orderByDesc('occurs_on')->orderByDesc('id')->limit(10)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Progress', 'icon' => 'activity', 'stats' => [
                ['label' => 'Sessions', 'value' => (int) $record->value('_attended').' of '.(int) $record->value('_sessions')],
                ['label' => 'Attendance', 'value' => $record->value('_attendance') === null ? '—' : $record->value('_attendance').'%', 'tone' => $record->value('_low_attendance') ? 'danger' : null],
                ['label' => 'First score', 'value' => $record->value('_first_score') === null ? '—' : $this->score($record->value('_first_score'))],
                ['label' => 'Latest score', 'value' => $record->value('_latest_score') === null ? '—' : $this->score($record->value('_latest_score'))],
                ['label' => 'Improvement', 'value' => $record->value('_improvement') === null ? '—' : $this->signed($record->value('_improvement')), 'tone' => $record->value('_improvement') !== null && $record->value('_improvement') < 0 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Sessions', 'icon' => 'activity', 'empty' => 'No sessions yet.',
                'rows' => $sessions->map(fn (Record $session) => [
                    'label' => $session->title, 'sub' => $session->occurs_on?->format('d M Y'), 'value' => $session->status === 'missed' ? 'Missed' : $this->score($session->value('progress_score')).'/10', 'href' => $session->url(), 'tone' => $session->status === 'missed' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $cases = $this->records('cases')->whereIn('status', ['assessment', 'active'])->get();
        $low = $cases->filter(fn (Record $case) => $case->value('_low_attendance'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Caseload', 'icon' => 'accessibility', 'stats' => [
                ['label' => 'In assessment', 'value' => (string) $cases->where('status', 'assessment')->count()],
                ['label' => 'Active', 'value' => (string) $cases->where('status', 'active')->count()],
                ['label' => 'Sessions this week', 'value' => (string) $this->records('sessions')->whereBetween('occurs_on', [today()->startOfWeek(), today()->endOfWeek()])->count()],
                ['label' => 'Low attendance', 'value' => (string) $low->count(), 'tone' => $low->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Low attendance', 'icon' => 'user-x', 'empty' => 'Every patient is attending well.',
                'rows' => $low->map(fn (Record $case) => ['label' => $case->title, 'sub' => (string) $case->value('condition'), 'value' => $case->value('_attendance').'%', 'href' => $case->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sessions = $this->dated('sessions', $from, $to)->get();
        $cases = $this->records('cases')->get()->keyBy('id');

        $byFunder = $sessions->groupBy(fn (Record $session) => (string) ($cases[$session->value('case')]?->value('funder') ?: 'Private'))->sortKeys()
            ->map(fn (Collection $group, string $funder) => [$funder, $group->pluck('data.case')->unique()->count(), $group->where('status', 'attended')->count(), $group->where('status', 'missed')->count(), $this->money($group->where('status', 'attended')->sum('amount'))])->values()->all();

        $therapists = User::query()->whereIn('id', $sessions->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $byTherapist = $sessions->groupBy(fn (Record $session) => $therapists[$session->assignee_id] ?? 'Unassigned')->sortKeys()
            ->map(fn (Collection $group, string $name) => [$name, $group->where('status', 'attended')->count(), $group->pluck('data.case')->unique()->count()])->values()->all();

        $outcomes = $cases->filter(fn (Record $case) => $case->value('_improvement') !== null)->groupBy(fn (Record $case) => (string) $case->value('condition'))->sortKeys()
            ->map(fn (Collection $group, string $condition) => [$condition, $group->count(), $this->signed($group->avg(fn (Record $case) => (float) $case->value('_improvement'))), $group->where('status', 'discharged')->count()])->values()->all();

        return [
            ['title' => 'Sessions by funder', 'columns' => ['Funder', 'Patients', 'Attended', 'Missed', 'Fees'], 'rows' => $byFunder],
            ['title' => 'Sessions by therapist', 'columns' => ['Therapist', 'Sessions', 'Patients'], 'rows' => $byTherapist],
            ['title' => 'Outcomes by condition', 'columns' => ['Condition', 'Cases', 'Average improvement', 'Discharged'], 'rows' => $outcomes],
        ];
    }
}
