<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Models\Record;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Clinic: visits are billed to the patient's account, allergies are flagged on every
 * screen that touches the patient, and follow-ups due come up on the clinic home page.
 */
class ClinicLogic extends AppLogic
{
    public function recordCards(Record $record): array
    {
        $patient = match ($record->entity) {
            'patients' => $record,
            'visits', 'prescriptions' => $record->related('patient'),
            default => null,
        };
        if (! $patient) {
            return [];
        }

        $cards = [];
        if (filled($patient->value('allergies'))) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['title' => 'Allergies', 'body' => $patient->value('allergies')]];
        }
        if (filled($patient->value('chronic_conditions'))) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['title' => 'Chronic conditions', 'body' => $patient->value('chronic_conditions'), 'tone' => 'warning', 'icon' => 'heart-pulse']];
        }

        if ($record->entity === 'patients') {
            $visits = $this->linked('visits', 'patient', $patient)->get(['id', 'amount', 'occurs_on', 'created_at', 'data']);
            $lastVisit = $visits->sortByDesc(fn (Record $visit) => ($visit->occurs_on ?? $visit->created_at)->timestamp)->first();
            $owing = $this->owingFor($visits->pluck('id')->all());
            $nextFollowUp = $visits->map(fn (Record $visit) => $visit->value('follow_up'))->filter(fn ($date) => $date && $date >= today()->toDateString())->sort()->first();

            $cards[] = ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Patient summary', 'icon' => 'activity', 'stats' => [
                ['label' => 'Visits', 'value' => (string) $visits->count()],
                ['label' => 'Last visit', 'value' => $lastVisit ? ($lastVisit->occurs_on ?? $lastVisit->created_at)->format('d M Y') : '—'],
                ['label' => 'Next follow-up', 'value' => $nextFollowUp ? Carbon::parse($nextFollowUp)->format('d M Y') : '—'],
                ['label' => 'Owing', 'value' => $this->money($owing), 'tone' => $owing > 0 ? 'danger' : null],
            ]]];
        }

        return $cards;
    }

    public function homeCards(): array
    {
        $today = today()->toDateString();

        $waiting = $this->records('visits')->whereIn('status', ['waiting', 'in_consultation'])->orderBy('id')->limit(10)->get();
        $followUps = $this->records('visits')->where('data->follow_up', '>=', $today)->where('data->follow_up', '<=', today()->addDays(7)->toDateString())
            ->orderBy('data->follow_up')->limit(10)->get();
        $patients = $this->patientNames([...$waiting->all(), ...$followUps->all()]);

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting room', 'icon' => 'armchair', 'empty' => 'Nobody is waiting.',
                'rows' => $waiting->map(fn (Record $visit) => [
                    'label' => $patients[$visit->value('patient')] ?? $visit->title, 'sub' => $visit->number.' · '.$visit->title,
                    'value' => $visit->statusLabel(), 'tone' => $visit->statusTone(), 'href' => $visit->url(),
                ])->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Follow-ups in the next 7 days', 'icon' => 'calendar-clock', 'empty' => 'No follow-ups due.',
                'rows' => $followUps->map(fn (Record $visit) => [
                    'label' => $patients[$visit->value('patient')] ?? $visit->title, 'sub' => $visit->number.' · '.Str::limit((string) ($visit->value('diagnosis') ?: $visit->title), 60),
                    'value' => Carbon::parse($visit->value('follow_up'))->format('D d M'), 'href' => $visit->url(),
                ])->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $visits = $this->dated('visits', $from, $to)->whereNot('status', 'cancelled')->with('assignee')->get();
        $collected = $this->collectedFor($visits->pluck('id')->all());

        $byMonth = [];
        foreach ($visits as $visit) {
            $month = ($visit->occurs_on ?? $visit->created_at)->format('Y-m');
            $byMonth[$month]['visits'] = ($byMonth[$month]['visits'] ?? 0) + 1;
            $byMonth[$month]['fees'] = ($byMonth[$month]['fees'] ?? 0) + (float) $visit->amount;
            $byMonth[$month]['collected'] = ($byMonth[$month]['collected'] ?? 0) + ($collected[$visit->id] ?? 0);
        }
        $monthRows = [];
        foreach ($this->months($from, $to) as $key => $label) {
            if (isset($byMonth[$key])) {
                $monthRows[] = [$label, $byMonth[$key]['visits'], $this->money($byMonth[$key]['fees']), $this->money($byMonth[$key]['collected'])];
            }
        }

        $diagnoses = $visits->map(fn (Record $visit) => Str::of((string) $visit->value('diagnosis'))->before("\n")->trim()->lower()->ucfirst()->toString())
            ->filter()->countBy()->sortDesc()->take(10);

        $doctors = $visits->groupBy(fn (Record $visit) => $visit->assignee?->name ?? 'Unassigned')
            ->map(fn ($group, $name) => [$name, $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn ($visit) => $collected[$visit->id] ?? 0))])
            ->sortByDesc(1)->values()->all();

        return [
            ['title' => 'Visits and fees by month', 'columns' => ['Month', 'Visits', 'Fees', 'Collected'], 'rows' => $monthRows],
            ['title' => 'Top diagnoses', 'columns' => ['Diagnosis', 'Visits'], 'rows' => $diagnoses->map(fn ($count, $name) => [$name, $count])->values()->all()],
            ['title' => 'Visits by doctor', 'columns' => ['Doctor', 'Visits', 'Fees', 'Collected'], 'rows' => $doctors],
        ];
    }

    /** @param  iterable<Record>  $visits  @return array<int, string> patient id => name */
    protected function patientNames(iterable $visits): array
    {
        $ids = collect($visits)->map(fn (Record $visit) => $visit->value('patient'))->filter()->unique()->all();

        return $ids ? $this->records('patients')->whereKey($ids)->pluck('title', 'id')->all() : [];
    }
}
