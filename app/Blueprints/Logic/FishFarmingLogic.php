<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Fish farming: a pond adds up the feed and deaths logged since it was stocked to give fish
 * alive, survival, standing biomass and feed conversion; a daily log is flagged when oxygen,
 * pH or temperature leave the safe range; only stocked ponds take logs and harvests.
 */
class FishFarmingLogic extends AppLogic
{
    public const MIN_OXYGEN = 4.0;

    public const PH_RANGE = [6.5, 9.0];

    public const TEMPERATURE_RANGE = [18.0, 32.0];

    public const LIVE = ['stocked', 'harvesting'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if (! in_array($entity->key, ['logs', 'harvests'], true) || empty($payload['data']['unit']) || $existing) {
            return [];
        }

        $unit = $this->records('units')->find($payload['data']['unit']);
        if ($unit && ! in_array($unit->status, self::LIVE, true)) {
            return ['data.unit' => $unit->title.' is '.$unit->status.'. Stock it first.'];
        }

        return [];
    }

    /** @return list<string> */
    public function problems(Record $log): array
    {
        $problems = [];
        if (filled($log->value('oxygen')) && $this->number($log, 'oxygen') < self::MIN_OXYGEN) {
            $problems[] = 'Low oxygen ('.$log->value('oxygen').' mg/L)';
        }
        if (filled($log->value('ph')) && ($this->number($log, 'ph') < self::PH_RANGE[0] || $this->number($log, 'ph') > self::PH_RANGE[1])) {
            $problems[] = 'pH '.$log->value('ph');
        }
        if (filled($log->value('temperature')) && ($this->number($log, 'temperature') < self::TEMPERATURE_RANGE[0] || $this->number($log, 'temperature') > self::TEMPERATURE_RANGE[1])) {
            $problems[] = 'Water at '.$log->value('temperature').'°C';
        }

        return $problems;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'logs') {
            $this->put($record, ['_problems' => $this->problems($record)]);
        }

        if ($record->entity !== 'units' || ! $record->exists) {
            return;
        }

        $since = fn ($query) => $record->occurs_on ? $query->whereDate('occurs_on', '>=', $record->occurs_on) : $query;
        $logs = $since($this->linked('logs', 'unit', $record))->get();
        $harvested = (float) $since($this->linked('harvests', 'unit', $record))->get()->sum(fn (Record $harvest) => $this->number($harvest, 'weight'));
        $stocked = $this->number($record, 'fish_count');
        $dead = $logs->sum(fn (Record $log) => $this->number($log, 'mortalities'));
        $feed = $logs->sum(fn (Record $log) => $this->number($log, 'feed'));
        $alive = max(0, $stocked - $dead);
        $biomass = round($alive * $this->number($record, 'average_weight') / 1000, 1);
        $gained = $harvested > 0 ? $harvested : $biomass;

        $this->put($record, [
            '_dead' => (int) $dead, '_alive' => (int) $alive, '_survival' => $stocked > 0 ? round($alive / $stocked * 100, 1) : null,
            '_biomass' => $biomass, '_feed' => round($feed, 1), '_harvested' => round($harvested, 1), '_fcr' => $gained > 0 && $feed > 0 ? round($feed / $gained, 2) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if (in_array($record->entity, ['logs', 'harvests'], true)) {
            $unit = $this->parent($record, 'unit');
            if ($record->entity === 'harvests' && $unit?->status === 'stocked') {
                $unit->status = 'harvesting';
            }
            $this->recalculate($unit);
            $this->recalculate($this->previousParent($record, 'unit'));
        }
    }

    public function deleted(Record $record): void
    {
        if (in_array($record->entity, ['logs', 'harvests'], true)) {
            $this->recalculate($this->parent($record, 'unit'));
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'logs' && $record->value('_problems')) {
            return [['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'danger', 'icon' => 'triangle-alert', 'title' => 'Water quality', 'body' => implode(' · ', (array) $record->value('_problems'))]]];
        }

        if ($record->entity !== 'units') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Production', 'icon' => 'fish', 'stats' => [
            ['label' => 'Fish alive', 'value' => number_format((int) $record->value('_alive')).' ('.($record->value('_survival') ?? '—').'% survival)'],
            ['label' => 'Standing biomass', 'value' => $record->value('_biomass').' kg'],
            ['label' => 'Feed used', 'value' => $record->value('_feed').' kg'],
            ['label' => 'Feed conversion', 'value' => $record->value('_fcr') !== null ? $record->value('_fcr').' : 1' : '—', 'tone' => $this->number($record, '_fcr') > 2 ? 'warning' : null],
        ], 'note' => 'Counts the logs and harvests since the stocking date.']]];
    }

    public function homeCards(): array
    {
        $names = $this->records('units')->pluck('title', 'id');
        $cards = [];
        foreach ($this->records('logs')->whereDate('occurs_on', '>=', today()->subDay())->get()->filter(fn (Record $log) => $log->value('_problems')) as $log) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'danger', 'icon' => 'triangle-alert',
                'title' => 'Check '.($names[$log->value('unit')] ?? 'a pond'), 'body' => implode(' · ', (array) $log->value('_problems')).' on '.$log->occurs_on->format('d M').'.']];
        }

        $units = $this->records('units')->whereIn('status', self::LIVE)->orderBy('title')->get();
        $cards[] = ['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Stocked ponds & cages', 'icon' => 'waves', 'empty' => 'Nothing stocked.',
            'rows' => $units->map(fn (Record $unit) => ['label' => $unit->title, 'sub' => ucfirst((string) $unit->value('species')), 'value' => number_format((int) $unit->value('_alive')).' fish · '.$unit->value('_biomass').' kg', 'href' => $unit->url()])->values()->all(),
        ]];

        return $cards;
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $harvests = $this->dated('harvests', $from, $to)->get();
        $units = $this->records('units')->orderBy('title')->get()->map(fn (Record $unit) => [
            $unit->title, ucfirst((string) $unit->value('species')), (int) $this->number($unit, 'fish_count'), (int) $unit->value('_dead'), ($unit->value('_survival') ?? '—').'%',
            $unit->value('_feed'), $unit->value('_harvested'), $unit->value('_fcr') ?? '—', $this->money($harvests->where('data.unit', $unit->id)->sum('amount')),
        ])->all();

        $logs = $this->dated('logs', $from, $to)->get();

        return [
            ['title' => 'Production by pond', 'columns' => ['Pond / cage', 'Species', 'Stocked', 'Deaths', 'Survival', 'Feed (kg)', 'Harvested (kg)', 'FCR', 'Sales'], 'rows' => $units],
            ['title' => 'Water quality', 'columns' => ['Logs', 'With problems'], 'rows' => [[$logs->count(), $logs->filter(fn (Record $log) => $log->value('_problems'))->count()]]],
        ];
    }
}
