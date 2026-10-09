<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Poultry: each flock keeps one daily record per day, deaths cannot exceed the birds still
 * alive, and the flock adds up its mortality, eggs and feed; a day's laying rate is eggs over
 * birds alive, and a day losing more than one percent of the flock is flagged.
 */
class PoultryLogic extends AppLogic
{
    /** Daily mortality, as a share of birds alive, that raises an alarm. */
    public const ALARM_MORTALITY = 0.01;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'daily_records' || empty($payload['data']['flock'])) {
            return [];
        }

        $flock = $this->records('flocks')->find($payload['data']['flock']);
        if (! $flock) {
            return [];
        }
        if (! $existing && $flock->status !== 'active') {
            return ['data.flock' => $flock->title.' is '.$flock->status.'.'];
        }

        if (filled($payload['occurs_on'] ?? null) && $this->linked('daily_records', 'flock', $flock)->whereDate('occurs_on', $payload['occurs_on'])
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            return ['occurs_on' => $flock->title.' already has a record for '.Carbon::parse($payload['occurs_on'])->format('d M Y').'.'];
        }

        $alive = $this->alive($flock, $existing?->id);
        if ((float) ($payload['data']['mortality'] ?? 0) > $alive) {
            return ['data.mortality' => 'Only '.$alive.' birds are alive in '.$flock->title.'.'];
        }

        return [];
    }

    public function alive(Record $flock, ?int $except = null): int
    {
        $dead = $this->linked('daily_records', 'flock', $flock)->when($except, fn ($query) => $query->whereKeyNot($except))->get()->sum(fn (Record $day) => $this->number($day, 'mortality'));

        return max(0, (int) ($this->number($flock, 'birds_placed') - $dead));
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'daily_records' && ($flock = $this->parent($record, 'flock'))) {
            $alive = $this->alive($flock, $record->id);
            $this->put($record, [
                '_alive' => $alive - (int) $this->number($record, 'mortality'),
                '_laying_rate' => $flock->value('type') === 'layers' && $alive > 0 ? round($this->number($record, 'eggs_collected') / $alive * 100, 1) : null,
                '_alarm' => $alive > 0 && $this->number($record, 'mortality') / $alive > self::ALARM_MORTALITY,
            ]);
        }

        if ($record->entity === 'flocks' && $record->exists) {
            $days = $this->linked('daily_records', 'flock', $record)->get();
            $placed = $this->number($record, 'birds_placed');
            $dead = $days->sum(fn (Record $day) => $this->number($day, 'mortality'));
            $this->put($record, [
                '_mortality' => (int) $dead, '_alive' => (int) max(0, $placed - $dead), '_mortality_percent' => $placed > 0 ? round($dead / $placed * 100, 2) : null,
                '_eggs' => (int) $days->sum(fn (Record $day) => $this->number($day, 'eggs_collected')), '_feed' => round($days->sum(fn (Record $day) => $this->number($day, 'feed_kg')), 1),
            ]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'daily_records') {
            $this->recalculate($this->parent($record, 'flock'));
            $this->recalculate($this->previousParent($record, 'flock'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'daily_records') {
            $this->recalculate($this->parent($record, 'flock'));
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'flocks') {
            return [];
        }

        $latest = $this->linked('daily_records', 'flock', $record)->orderByDesc('occurs_on')->first();
        $age = $record->occurs_on ? (int) $record->occurs_on->diffInDays(today()) : null;

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Flock', 'icon' => 'bird', 'stats' => array_values(array_filter([
            ['label' => 'Birds alive', 'value' => (string) $record->value('_alive')],
            ['label' => 'Mortality', 'value' => (int) $record->value('_mortality').' ('.($record->value('_mortality_percent') ?? 0).'%)', 'tone' => $this->number($record, '_mortality_percent') > 5 ? 'danger' : null],
            $record->value('type') === 'layers' ? ['label' => 'Laying rate', 'value' => $latest?->value('_laying_rate') !== null ? $latest->value('_laying_rate').'%' : '—'] : null,
            ['label' => 'Eggs collected', 'value' => number_format((int) $record->value('_eggs'))],
            ['label' => 'Feed used', 'value' => $record->value('_feed').' kg'],
            ['label' => 'Age', 'value' => $age !== null ? $age.' days' : '—'],
        ]))]]];
    }

    public function homeCards(): array
    {
        $flocks = $this->records('flocks')->where('status', 'active')->orderBy('title')->get();
        $cards = [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Active flocks', 'icon' => 'bird', 'empty' => 'No active flocks.',
            'rows' => $flocks->map(fn (Record $flock) => ['label' => $flock->title, 'sub' => ucfirst((string) $flock->value('type')), 'value' => number_format((int) $flock->value('_alive')).' birds', 'href' => $flock->url()])->values()->all(),
        ]]];

        $names = $flocks->pluck('title', 'id');
        foreach ($this->records('daily_records')->whereDate('occurs_on', '>=', today()->subDay())->get()->filter(fn (Record $day) => $day->value('_alarm')) as $day) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'danger', 'icon' => 'triangle-alert', 'title' => 'High mortality in '.($names[$day->value('flock')] ?? 'a flock'),
                'body' => (int) $day->value('mortality').' birds died on '.$day->occurs_on->format('d M').'. Check for disease, water and heat.']];
        }

        return $cards;
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $days = $this->dated('daily_records', $from, $to)->get();
        $monthly = collect($this->months($from, $to))->map(function ($label, $month) use ($days) {
            $inMonth = $days->filter(fn (Record $day) => ($day->occurs_on ?? $day->created_at)->format('Y-m') === $month);

            return [$label, number_format($inMonth->sum(fn (Record $day) => $this->number($day, 'eggs_collected'))), round($inMonth->sum(fn (Record $day) => $this->number($day, 'feed_kg')), 1), (int) $inMonth->sum(fn (Record $day) => $this->number($day, 'mortality'))];
        })->values()->all();

        $flocks = $this->records('flocks')->orderBy('title')->get()->map(fn (Record $flock) => [
            $flock->title, ucfirst((string) $flock->value('type')), (int) $this->number($flock, 'birds_placed'), (int) $flock->value('_alive'), ($flock->value('_mortality_percent') ?? 0).'%',
            number_format((int) $flock->value('_eggs')), $flock->value('_feed').' kg',
        ])->all();

        return [
            ['title' => 'Production by month', 'columns' => ['Month', 'Eggs', 'Feed (kg)', 'Deaths'], 'rows' => $monthly],
            ['title' => 'Flocks', 'columns' => ['Flock', 'Type', 'Placed', 'Alive', 'Mortality', 'Eggs', 'Feed'], 'rows' => $flocks],
        ];
    }
}
