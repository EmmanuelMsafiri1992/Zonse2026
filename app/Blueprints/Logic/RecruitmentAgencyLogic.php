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
 * Recruitment agency: candidates are only put forward for vacancies that are still open, and a placement
 * needs the agreed salary. The placement fee is the vacancy's fee percentage of that salary. Placing a
 * candidate fills the vacancy and turns down the others still in the running. Vacancies show their pipeline,
 * and the report shows placements and fees by consultant.
 */
class RecruitmentAgencyLogic extends AppLogic
{
    /**
     * Candidate stages that are still in the running.
     *
     * @var list<string>
     */
    public const IN_PLAY = ['new', 'screened', 'submitted', 'interview', 'offer'];

    /**
     * The next stage for a candidate.
     *
     * @var array<string, string>
     */
    public const NEXT = ['new' => 'screened', 'screened' => 'submitted', 'submitted' => 'interview', 'interview' => 'offer'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'candidates') {
            return $errors;
        }
        $vacancy = filled($data['vacancy'] ?? null) ? $this->records('vacancies')->find($data['vacancy']) : null;
        $vacancyChanged = ! $existing || (string) $existing->value('vacancy') !== (string) ($data['vacancy'] ?? '');
        if ($vacancy && $vacancyChanged && in_array($vacancy->status, ['filled', 'cancelled'], true)) {
            $errors['data.vacancy'] = $vacancy->title.' is '.$vacancy->status.'.';
        }
        if ($payload['status'] === 'placed') {
            if (! $vacancy) {
                $errors['data.vacancy'] = 'Give the vacancy the candidate was placed in.';
            }
            if ((float) ($data['expected_salary'] ?? 0) <= 0) {
                $errors['data.expected_salary'] = 'Give the agreed salary.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'candidates') {
            return;
        }
        $record->occurs_on ??= today();
        if ($record->status === 'placed' && ($vacancy = $this->parent($record, 'vacancy')) && $this->number($vacancy, 'fee_percent') > 0) {
            $record->amount = round($this->number($record, 'expected_salary') * $this->number($vacancy, 'fee_percent') / 100, 2);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'candidates' || $record->status !== 'placed' || ! ($vacancy = $this->parent($record, 'vacancy'))) {
            return;
        }
        if ($vacancy->status !== 'filled') {
            $vacancy->update(['status' => 'filled']);
        }
        $this->linked('candidates', 'vacancy', $vacancy)->whereKeyNot($record->id)->whereIn('status', self::IN_PLAY)->get()
            ->each(fn (Record $other) => $other->update(['status' => 'rejected']));
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'vacancies') {
            return in_array($record->status, ['filled', 'cancelled'], true) ? [] : ['cancel' => ['label' => 'Cancel vacancy', 'icon' => 'x']];
        }

        return match (true) {
            isset(self::NEXT[$record->status]) => [
                'advance' => ['label' => 'Move to '.self::NEXT[$record->status], 'icon' => 'arrow-right'],
                'reject' => ['label' => 'Reject', 'icon' => 'x'],
            ],
            $record->status === 'offer' => [
                'place' => ['label' => 'Placed', 'icon' => 'party-popper', 'fields' => [['name' => 'salary', 'label' => 'Agreed salary', 'type' => 'number', 'value' => $record->value('expected_salary')]]],
                'reject' => ['label' => 'Offer declined', 'icon' => 'x'],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'cancel':
                $record->update(['status' => 'cancelled']);
                $this->linked('candidates', 'vacancy', $record)->whereIn('status', self::IN_PLAY)->get()
                    ->each(fn (Record $candidate) => $candidate->update(['status' => 'rejected']));

                return $record->title.' cancelled.';
            case 'advance':
                $record->update(['status' => self::NEXT[$record->status]]);

                return $record->title.' moved to '.$record->status.'.';
            case 'reject':
                $record->update(['status' => 'rejected']);

                return $record->title.' rejected.';
            default:
                $salary = (float) $request->validate(['salary' => ['required', 'numeric', 'gt:0']])['salary'];
                if (! $this->parent($record, 'vacancy')) {
                    throw ValidationException::withMessages(['vacancy' => 'Give the vacancy the candidate was placed in.']);
                }
                $record->update(['status' => 'placed', 'data' => [...$record->data, 'expected_salary' => $salary]]);

                return $record->title.' placed'.($record->amount > 0 ? '; fee '.$this->money($record->amount) : '').'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'vacancies') {
            return [];
        }
        $candidates = $this->linked('candidates', 'vacancy', $record)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Pipeline', 'icon' => 'users', 'stats' => [
                ['label' => 'Candidates', 'value' => $candidates->count()],
                ['label' => 'Submitted or later', 'value' => $candidates->whereIn('status', ['submitted', 'interview', 'offer', 'placed'])->count()],
                ['label' => 'Interviewing', 'value' => $candidates->where('status', 'interview')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Candidates', 'icon' => 'user-search', 'empty' => 'No candidates yet.',
                'rows' => $candidates->sortBy(fn (Record $candidate) => array_search($candidate->status, [...self::IN_PLAY, 'placed', 'rejected'], true))
                    ->map(fn (Record $candidate) => ['label' => $candidate->title, 'sub' => (string) $candidate->value('current_role'), 'value' => $candidate->status, 'href' => $candidate->url(), 'tone' => $candidate->status === 'placed' ? 'success' : null])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $candidates = $this->records('candidates')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Desk', 'icon' => 'briefcase', 'stats' => [
                ['label' => 'Open vacancies', 'value' => $this->records('vacancies')->whereIn('status', ['open', 'shortlisting', 'interviewing'])->count()],
                ['label' => 'Candidates in play', 'value' => $candidates->whereIn('status', self::IN_PLAY)->count()],
                ['label' => 'Fees this month', 'value' => $this->money($candidates->where('status', 'placed')->filter(fn (Record $candidate) => $candidate->updated_at->isSameMonth(today()))->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Vacancies closing in 7 days', 'icon' => 'alarm-clock', 'empty' => 'No vacancies closing soon.',
                'rows' => $this->records('vacancies')->whereIn('status', ['open', 'shortlisting', 'interviewing'])->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(7)->toDateString())->orderBy('due_on')->get()
                    ->map(fn (Record $vacancy) => ['label' => $vacancy->title, 'sub' => (string) $vacancy->value('location'), 'value' => $vacancy->due_on->format('d M'), 'href' => $vacancy->url(), 'tone' => $vacancy->due_on->lt(today()) ? 'danger' : 'warning'])->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $placed = $this->records('candidates')->where('status', 'placed')->whereDate('updated_at', '>=', $from->toDateString())->whereDate('updated_at', '<=', $to->toDateString())->get();
        $names = User::query()->whereIn('id', $placed->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        return [['title' => 'Placements by consultant', 'columns' => ['Consultant', 'Placements', 'Fees'], 'rows' => $placed
            ->groupBy(fn (Record $candidate) => $names[$candidate->assignee_id] ?? 'Unassigned')->sortKeys()
            ->map(fn ($group, string $consultant) => [$consultant, $group->count(), $this->money($group->sum('amount'))])
            ->values()->all()]];
    }
}
