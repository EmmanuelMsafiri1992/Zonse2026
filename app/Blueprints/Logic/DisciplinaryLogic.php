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
 * Disciplinary & grievance case management: a hearing needs a date on or after the case was
 * reported, and a case can't reach its outcome, appeal or close without one being recorded. A
 * grievance is upheld or not upheld; a disciplinary case ends in a sanction. Verbal, written and final warnings stay on
 * file for 3, 6 and 12 months, and each case shows the employee's live warnings.
 */
class DisciplinaryLogic extends AppLogic
{
    /**
     * Months each warning stays live.
     *
     * @var array<string, int>
     */
    public const WARNING_MONTHS = ['verbal_warning' => 3, 'written_warning' => 6, 'final_warning' => 12];

    public const GRIEVANCE_OUTCOMES = ['upheld', 'not_upheld'];

    /**
     * Where each action moves a case.
     *
     * @var array<string, string>
     */
    public const MOVES = ['investigate' => 'investigating', 'schedule' => 'hearing_scheduled', 'decide' => 'outcome', 'appeal' => 'appeal', 'close' => 'closed'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($payload['status'] === 'hearing_scheduled' && blank($data['hearing_at'] ?? null)) {
            $errors['data.hearing_at'] = 'Set the hearing date.';
        }
        if (filled($data['hearing_at'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($data['hearing_at'])->lt(Carbon::parse($payload['occurs_on'])->startOfDay())) {
            $errors['data.hearing_at'] = 'The hearing cannot be before the case was reported.';
        }
        if (in_array($payload['status'], ['outcome', 'appeal', 'closed'], true) && blank($data['outcome'] ?? null)) {
            $errors['data.outcome'] = 'Record the outcome first.';
        }
        if ($message = $this->outcomeProblem((string) ($data['type'] ?? ''), $data['outcome'] ?? null)) {
            $errors['data.outcome'] = $message;
        }

        return $errors;
    }

    /**
     * Why an outcome doesn't fit the case type, if it doesn't.
     */
    protected function outcomeProblem(string $type, ?string $outcome): ?string
    {
        if (blank($outcome)) {
            return null;
        }
        $grievanceOutcome = in_array($outcome, self::GRIEVANCE_OUTCOMES, true);

        return match (true) {
            $type === 'grievance' && ! $grievanceOutcome => 'A grievance is either upheld or not upheld.',
            $type === 'disciplinary' && $grievanceOutcome => 'A disciplinary case ends in no action or a sanction.',
            default => null,
        };
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $months = self::WARNING_MONTHS[$record->value('outcome')] ?? null;
        $this->put($record, ['_warning_expires' => $months ? ($record->value('_decided_on') ? Carbon::parse($record->value('_decided_on')) : today())->addMonthsNoOverflow($months)->toDateString() : null]);
        if ($record->isDirty('status') && $record->status === 'outcome' && ! $record->value('_decided_on')) {
            $this->put($record, ['_decided_on' => today()->toDateString()]);
        }
    }

    /**
     * Warnings still live for an employee, newest first.
     *
     * @return Collection<int, Record>
     */
    protected function liveWarnings(mixed $employee, ?int $ignore = null)
    {
        return $this->records('cases')->where('data->employee', (int) $employee)->when($ignore, fn ($query) => $query->whereKeyNot($ignore))->get()
            ->filter(fn (Record $case) => $case->value('_warning_expires') && Carbon::parse($case->value('_warning_expires'))->gte(today()))
            ->sortByDesc('occurs_on')->values();
    }

    public function actions(Record $record): array
    {
        $hearing = ['label' => 'Schedule hearing', 'icon' => 'calendar', 'fields' => [['name' => 'hearing_at', 'label' => 'Hearing date and time', 'type' => 'datetime-local', 'value' => $record->value('hearing_at')]]];
        $outcomes = $record->value('type') === 'grievance' ? self::GRIEVANCE_OUTCOMES : ['no_action', ...array_keys(self::WARNING_MONTHS), 'suspension', 'dismissal'];
        $decide = ['label' => 'Record outcome', 'icon' => 'gavel', 'fields' => [['name' => 'outcome', 'label' => 'Outcome', 'type' => 'select', 'value' => $record->value('outcome'),
            'options' => collect($outcomes)->mapWithKeys(fn (string $outcome) => [$outcome => ucfirst(str_replace('_', ' ', $outcome))])->all()]]];

        return match ($record->status) {
            'reported' => ['investigate' => ['label' => 'Investigate', 'icon' => 'search'], 'schedule' => $hearing],
            'investigating' => ['schedule' => $hearing, 'decide' => $decide],
            'hearing_scheduled' => ['decide' => $decide],
            'outcome' => ['appeal' => ['label' => 'Appealed', 'icon' => 'undo-2'], 'close' => ['label' => 'Close', 'icon' => 'check']],
            'appeal' => ['decide' => $decide, 'close' => ['label' => 'Close', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $data = $record->data;
        if ($action === 'schedule') {
            $at = Carbon::parse($request->validate(['hearing_at' => ['required', 'date']])['hearing_at']);
            if ($record->occurs_on && $at->lt($record->occurs_on->copy()->startOfDay())) {
                throw ValidationException::withMessages(['hearing_at' => 'The hearing cannot be before the case was reported.']);
            }
            $data['hearing_at'] = $at->format('Y-m-d H:i');
        }
        if ($action === 'decide') {
            $outcome = str((string) $request->validate(['outcome' => ['required', 'string']])['outcome'])->trim()->lower()->replace(' ', '_')->toString();
            if (! in_array($outcome, [...array_keys(self::WARNING_MONTHS), 'no_action', 'suspension', 'dismissal', ...self::GRIEVANCE_OUTCOMES], true)) {
                throw ValidationException::withMessages(['outcome' => 'Pick one of the listed outcomes.']);
            }
            if ($message = $this->outcomeProblem((string) $record->value('type'), $outcome)) {
                throw ValidationException::withMessages(['outcome' => $message]);
            }
            $data['outcome'] = $outcome;
            $data['_decided_on'] = today()->toDateString();
        }
        $record->update(['status' => self::MOVES[$action], 'data' => $data]);
        $employee = (string) User::query()->whereKey($record->value('employee'))->value('name');

        return match ($action) {
            'investigate' => 'Investigation opened into '.$employee.'\'s case.',
            'schedule' => 'Hearing for '.$employee.' set for '.Carbon::parse($record->value('hearing_at'))->format('d M Y H:i').'.',
            'decide' => 'Outcome for '.$employee.': '.str_replace('_', ' ', (string) $record->value('outcome')).($record->value('_warning_expires') ? ', on file until '.Carbon::parse($record->value('_warning_expires'))->format('d M Y') : '').'.',
            'appeal' => $employee.' has appealed.',
            default => 'Case closed.',
        };
    }

    public function recordCards(Record $record): array
    {
        $warnings = $this->liveWarnings($record->value('employee'), $record->id);
        $earlier = $this->records('cases')->where('data->employee', (int) $record->value('employee'))->whereKeyNot($record->id)->count();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Employee history', 'icon' => 'gavel', 'stats' => array_values(array_filter([
            ['label' => 'Other cases', 'value' => $earlier],
            ['label' => 'Live warnings', 'value' => $warnings->isEmpty() ? 'None' : $warnings->map(fn (Record $case) => str_replace('_', ' ', (string) $case->value('outcome')))->join(', '), 'tone' => $warnings->contains(fn (Record $case) => $case->value('outcome') === 'final_warning') ? 'danger' : ($warnings->isNotEmpty() ? 'warning' : null)],
            $record->value('_warning_expires') ? ['label' => 'This warning on file until', 'value' => Carbon::parse($record->value('_warning_expires'))->format('d M Y')] : null,
        ]))]]];
    }

    public function homeCards(): array
    {
        $hearings = $this->records('cases')->where('status', 'hearing_scheduled')->get()->sortBy(fn (Record $case) => (string) $case->value('hearing_at'));
        $names = User::query()->whereIn('id', $hearings->map(fn (Record $case) => $case->value('employee'))->filter()->unique())->pluck('name', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Hearings coming up', 'icon' => 'calendar', 'empty' => 'No hearings scheduled.',
            'rows' => $hearings->map(fn (Record $case) => [
                'label' => $names[$case->value('employee')] ?? '—', 'sub' => ucfirst((string) $case->value('type')).' · '.$case->title,
                'value' => Carbon::parse($case->value('hearing_at'))->format('d M H:i'), 'href' => $case->url(),
                'tone' => Carbon::parse($case->value('hearing_at'))->lt(now()) ? 'danger' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $cases = $this->dated('cases', $from, $to)->get();

        return [['title' => 'Cases by category', 'columns' => ['Category', 'Disciplinary', 'Grievances', 'Still open', 'Warnings given', 'Dismissals', 'Grievances upheld'], 'rows' => $cases
            ->groupBy(fn (Record $case) => ucfirst(str_replace('_', ' ', (string) ($case->value('category') ?: 'other'))))->sortKeys()
            ->map(fn ($group, string $category) => [
                $category, $group->filter(fn (Record $case) => $case->value('type') === 'disciplinary')->count(), $group->filter(fn (Record $case) => $case->value('type') === 'grievance')->count(),
                $group->where('status', '!=', 'closed')->count(), $group->filter(fn (Record $case) => isset(self::WARNING_MONTHS[$case->value('outcome')]))->count(),
                $group->filter(fn (Record $case) => $case->value('outcome') === 'dismissal')->count(), $group->filter(fn (Record $case) => $case->value('outcome') === 'upheld')->count(),
            ])->values()->all()]];
    }
}
