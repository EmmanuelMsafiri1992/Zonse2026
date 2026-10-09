<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Recruitment: new candidates only join an open vacancy, the same email cannot apply twice for
 * one vacancy, and no more people are hired than there are positions. A vacancy counts its
 * applicants and hires and is marked filled once every position is taken.
 */
class RecruitmentLogic extends AppLogic
{
    public const STAGES = ['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'vacancies') {
            if (filled($data['positions'] ?? null) && (float) $data['positions'] < 1) {
                $errors['data.positions'] = 'A vacancy has at least one position.';
            }

            return $errors;
        }

        $vacancy = ! empty($data['vacancy']) ? $this->records('vacancies')->find($data['vacancy']) : null;
        if (! $vacancy) {
            return $errors;
        }

        $joining = ! $existing || (int) $existing->value('vacancy') !== $vacancy->id;
        if ($joining && $vacancy->status !== 'open') {
            $errors['data.vacancy'] = $vacancy->title.' is '.str_replace('_', ' ', $vacancy->status).' and is not taking applications.';
        }
        if (filled($data['email'] ?? null) && $this->linked('candidates', 'vacancy', $vacancy)->where('data->email', $data['email'])
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            $errors['data.email'] = $data['email'].' has already applied for '.$vacancy->title.'.';
        }

        $positions = (int) $this->number($vacancy, 'positions');
        if ($payload['status'] === 'hired' && $positions > 0 && ($existing?->status !== 'hired' || $joining)) {
            $hired = $this->linked('candidates', 'vacancy', $vacancy)->where('status', 'hired')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->count();
            if ($hired >= $positions) {
                $errors['status'] = 'All '.$positions.' position'.($positions === 1 ? ' is' : 's are').' already filled for '.$vacancy->title.'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'candidates') {
            if ($record->status === 'hired' && ! $record->value('_hired_on')) {
                $this->put($record, ['_hired_on' => today()->toDateString()]);
            } elseif ($record->status !== 'hired') {
                $this->put($record, ['_hired_on' => null]);
            }

            return;
        }

        $candidates = $record->exists ? $this->linked('candidates', 'vacancy', $record)->get() : collect();
        $hired = $candidates->where('status', 'hired')->count();
        $this->put($record, ['_applicants' => $candidates->count(), '_hired' => $hired]);

        $positions = (int) $this->number($record, 'positions');
        if ($record->status === 'open' && $positions > 0 && $hired >= $positions) {
            $record->status = 'filled';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'candidates') {
            $this->recalculate($this->parent($record, 'vacancy'));
            $this->recalculate($this->previousParent($record, 'vacancy'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'candidates') {
            $this->recalculate($this->parent($record, 'vacancy'));
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'vacancies') {
            return [];
        }

        $candidates = $this->linked('candidates', 'vacancy', $record)->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Pipeline', 'icon' => 'users', 'stats' => collect(self::STAGES)
            ->map(fn (string $stage) => ['label' => ucfirst($stage), 'value' => (string) $candidates->where('status', $stage)->count()])->all()]]];
    }

    public function homeCards(): array
    {
        $open = $this->records('vacancies')->where('status', 'open')->orderBy('due_on')->get();
        $active = $this->records('candidates')->whereIn('status', ['screening', 'interview', 'offer'])->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Hiring', 'icon' => 'user-search', 'stats' => [
                ['label' => 'Open vacancies', 'value' => (string) $open->count()],
                ['label' => 'In interview', 'value' => (string) $active->where('status', 'interview')->count()],
                ['label' => 'Offers out', 'value' => (string) $active->where('status', 'offer')->count()],
                ['label' => 'Hired this month', 'value' => (string) $this->records('candidates')->where('status', 'hired')->get()
                    ->filter(fn (Record $candidate) => $candidate->value('_hired_on') && Carbon::parse($candidate->value('_hired_on'))->isSameMonth(today()))->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open vacancies', 'icon' => 'briefcase', 'empty' => 'No vacancies are open.',
                'rows' => $open->map(fn (Record $vacancy) => [
                    'label' => $vacancy->title, 'sub' => (int) $vacancy->value('_applicants').' applicants', 'value' => $vacancy->due_on ? 'Closes '.$vacancy->due_on->format('d M') : '',
                    'href' => $vacancy->url(), 'tone' => $vacancy->due_on?->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $candidates = $this->records('candidates')->get();
        $funnel = $this->records('vacancies')->orderBy('title')->get()->map(function (Record $vacancy) use ($candidates) {
            $mine = $candidates->where('data.vacancy', $vacancy->id);

            return [$vacancy->title, ...collect(self::STAGES)->map(fn (string $stage) => $mine->where('status', $stage)->count())->all()];
        })->all();

        $hires = $candidates->where('status', 'hired')->filter(fn (Record $candidate) => $candidate->value('_hired_on')
            && Carbon::parse($candidate->value('_hired_on'))->betweenIncluded($from->copy()->startOfDay(), $to->copy()->endOfDay()))
            ->map(fn (Record $candidate) => [
                $candidate->title, (string) ($this->parent($candidate, 'vacancy')?->title ?? '—'), $candidate->occurs_on?->format('d M Y') ?? '—', Carbon::parse($candidate->value('_hired_on'))->format('d M Y'),
                $candidate->occurs_on ? (int) $candidate->occurs_on->diffInDays(Carbon::parse($candidate->value('_hired_on'))).' days' : '—',
            ])->values()->all();

        return [
            ['title' => 'Funnel by vacancy', 'columns' => ['Vacancy', ...array_map('ucfirst', self::STAGES)], 'rows' => $funnel],
            ['title' => 'Time to hire', 'columns' => ['Candidate', 'Vacancy', 'Applied', 'Hired', 'Took'], 'rows' => $hires],
        ];
    }
}
