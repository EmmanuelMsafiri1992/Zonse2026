<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Veterinary visits: a farm call is only done with a diagnosis, and a withdrawal date cannot
 * come before the visit; farms still under milk or meat withdrawal are listed on the home
 * screen; vaccination campaigns start and finish on their dates and need a count to complete.
 */
class VeterinaryVisitsLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'visits') {
            if (in_array($payload['status'], ['done', 'invoiced'], true) && blank($data['diagnosis'] ?? null)) {
                $errors['data.diagnosis'] = 'Record the diagnosis.';
            }
            if (filled($data['withdrawal_until'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($data['withdrawal_until'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['data.withdrawal_until'] = 'Withdrawal cannot end before the visit.';
            }
        }

        if ($entity->key === 'vaccinations') {
            if ($payload['status'] === 'completed' && (float) ($data['animals_vaccinated'] ?? 0) <= 0) {
                $errors['data.animals_vaccinated'] = 'Record how many animals were vaccinated.';
            }
            if (filled($payload['due_on'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The campaign cannot end before it starts.';
            }
        }

        return $errors;
    }

    public function daily(Workspace $workspace): int
    {
        $started = $this->records('vaccinations')->where('status', 'planned')->whereDate('occurs_on', '<=', today())->get();
        $started->each(fn (Record $campaign) => $campaign->update(['status' => 'running']));

        $ended = $this->records('vaccinations')->where('status', 'running')->whereDate('due_on', '<', today())->get()
            ->filter(fn (Record $campaign) => $this->number($campaign, 'animals_vaccinated') > 0);
        $ended->each(fn (Record $campaign) => $campaign->update(['status' => 'completed']));

        return $started->count() + $ended->count();
    }

    /** @return Collection<int, Record> */
    public function underWithdrawal()
    {
        return $this->records('visits')->whereIn('status', ['done', 'invoiced'])->with('contact')->get()
            ->filter(fn (Record $visit) => filled($visit->value('withdrawal_until')) && Carbon::parse($visit->value('withdrawal_until'))->gte(today()))
            ->sortBy(fn (Record $visit) => $visit->value('withdrawal_until'));
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'visits' || blank($record->value('withdrawal_until'))) {
            return [];
        }

        $until = Carbon::parse($record->value('withdrawal_until'));
        if ($until->lt(today())) {
            return [];
        }

        return [['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'warning', 'icon' => 'milk-off', 'title' => 'Withdrawal period',
            'body' => 'Milk and meat from the treated animals must not be sold until '.$until->format('d M Y').' ('.((int) today()->diffInDays($until) + 1).' days).']]];
    }

    public function homeCards(): array
    {
        $today = $this->records('visits')->whereIn('status', ['booked', 'on_route'])->whereDate('occurs_on', '<=', today())->with('contact')->orderBy('occurs_on')->get();
        $withdrawal = $this->underWithdrawal();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Calls to make', 'icon' => 'stethoscope', 'empty' => 'No calls waiting.',
                'rows' => $today->map(fn (Record $visit) => [
                    'label' => $visit->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $visit->value('species'))), 'value' => ucfirst(str_replace('_', ' ', $visit->status)),
                    'href' => $visit->url(), 'tone' => $visit->occurs_on?->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Under withdrawal', 'icon' => 'milk-off', 'empty' => 'No farms under withdrawal.',
                'rows' => $withdrawal->map(fn (Record $visit) => ['label' => $visit->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $visit->value('species'))),
                    'value' => 'Until '.Carbon::parse($visit->value('withdrawal_until'))->format('d M'), 'href' => $visit->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $visits = $this->dated('visits', $from, $to)->whereIn('status', ['done', 'invoiced'])->get();
        $species = $visits->groupBy(fn (Record $visit) => (string) $visit->value('species'))->sortKeys()->map(fn ($group, $species) => [
            ucfirst(str_replace('_', ' ', $species)), $group->count(), (int) $group->sum(fn (Record $visit) => $this->number($visit, 'animals')), $this->money($group->sum('amount')),
        ])->values()->all();

        $campaigns = $this->dated('vaccinations', $from, $to)->orderBy('occurs_on')->get()->map(fn (Record $campaign) => [
            $campaign->title, (string) ($campaign->value('area') ?? '—'), $campaign->occurs_on?->format('d M Y') ?? '—', ucfirst($campaign->status), (int) $this->number($campaign, 'animals_vaccinated'),
        ])->all();

        return [
            ['title' => 'Farm calls by species', 'columns' => ['Species', 'Calls', 'Animals seen', 'Fees'], 'rows' => $species],
            ['title' => 'Vaccination campaigns', 'columns' => ['Disease / vaccine', 'Area', 'Starts', 'Status', 'Animals vaccinated'], 'rows' => $campaigns],
        ];
    }
}
