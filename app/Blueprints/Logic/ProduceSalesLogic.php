<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Produce sales: a sale is priced at its quantity times the price per kg, cannot sell more of
 * a lot than is left or anything from a spoiled or rejected lot, and a lot marks itself sold
 * once every kilogram has gone; reports compare prices by market and grade.
 */
class ProduceSalesLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'sales' || empty($payload['data']['lot'])) {
            return [];
        }

        $lot = $this->records('lots')->find($payload['data']['lot']);
        if (! $lot) {
            return [];
        }

        $counted = $existing && (int) $existing->value('lot') === $lot->id ? $this->number($existing, 'quantity') : 0;
        if (! $counted && ($lot->status === 'spoiled' || $lot->value('grade') === 'reject')) {
            return ['data.lot' => $lot->title.' is '.($lot->status === 'spoiled' ? 'spoiled' : 'graded reject').' and cannot be sold.'];
        }

        $left = round($this->available($lot) + $counted, 2);
        if ((float) ($payload['data']['quantity'] ?? 0) - $left > 0.004) {
            return ['data.quantity' => 'Only '.$left.' kg of '.$lot->title.' is left.'];
        }

        return [];
    }

    public function available(Record $lot): float
    {
        return max(0, round($this->number($lot, 'quantity') - $this->sold($lot), 2));
    }

    public function sold(Record $lot): float
    {
        return (float) $this->linked('sales', 'lot', $lot)->get()->sum(fn (Record $sale) => $this->number($sale, 'quantity'));
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'sales' && $this->number($record, 'price_per_kg') > 0) {
            $record->amount = round($this->number($record, 'quantity') * $this->number($record, 'price_per_kg'), 2);
        }

        if ($record->entity === 'lots' && $record->exists) {
            $sales = $this->linked('sales', 'lot', $record)->get();
            $sold = $sales->sum(fn (Record $sale) => $this->number($sale, 'quantity'));
            $this->put($record, ['_sold' => round($sold, 2), '_available' => max(0, round($this->number($record, 'quantity') - $sold, 2)), '_revenue' => round((float) $sales->sum('amount'), 2)]);

            if ($sales->isNotEmpty() && $this->number($record, '_available') <= 0 && ! in_array($record->status, ['sold', 'spoiled'], true)) {
                $record->status = 'sold';
            } elseif ($record->status === 'sold' && $this->number($record, '_available') > 0) {
                $record->status = 'in_store';
            }
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'sales') {
            $this->recalculate($this->parent($record, 'lot'));
            $this->recalculate($this->previousParent($record, 'lot'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'sales') {
            $this->recalculate($this->parent($record, 'lot'));
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'lots') {
            return [];
        }

        $sold = $this->number($record, '_sold');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Lot', 'icon' => 'apple', 'stats' => [
            ['label' => 'Harvested', 'value' => $this->number($record, 'quantity').' kg'],
            ['label' => 'Sold', 'value' => $sold.' kg'],
            ['label' => 'Left', 'value' => $this->number($record, '_available').' kg', 'tone' => $this->number($record, '_available') > 0 ? 'warning' : 'success'],
            ['label' => 'Average price', 'value' => $sold > 0 ? $this->money($this->number($record, '_revenue') / $sold).'/kg' : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $lots = $this->records('lots')->whereIn('status', ['harvested', 'graded', 'in_store'])->orderBy('occurs_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Produce to sell', 'icon' => 'apple', 'empty' => 'Nothing in store.',
            'rows' => $lots->take(15)->map(fn (Record $lot) => [
                'label' => $lot->title, 'sub' => ucfirst(str_replace('_', ' ', (string) ($lot->value('grade') ?? 'ungraded'))).($lot->occurs_on ? ' · '.(int) $lot->occurs_on->diffInDays(today()).' days old' : ''),
                'value' => ($lot->value('_available') ?? $lot->value('quantity')).' kg', 'href' => $lot->url(),
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sales = $this->dated('sales', $from, $to)->get();
        $kg = fn ($group) => $group->sum(fn (Record $sale) => $this->number($sale, 'quantity'));

        $markets = $sales->groupBy(fn (Record $sale) => (string) ($sale->value('market') ?: 'not given'))->sortKeys()->map(fn ($group, $market) => [
            ucfirst(str_replace('_', ' ', $market)), number_format($kg($group), 1), $this->money($group->sum('amount')), $kg($group) > 0 ? $this->money($group->sum('amount') / $kg($group)) : '—',
        ])->values()->all();

        $lots = $this->records('lots')->get()->keyBy('id');
        $grades = $sales->groupBy(function (Record $sale) use ($lots) {
            $lot = $lots[$sale->value('lot')] ?? null;

            return ($lot?->value('crop') ?? '—').' · '.ucfirst(str_replace('_', ' ', (string) ($lot?->value('grade') ?? 'ungraded')));
        })->sortKeys()->map(fn ($group, $key) => [$key, number_format($kg($group), 1), $kg($group) > 0 ? $this->money($group->sum('amount') / $kg($group)) : '—'])->values()->all();

        return [
            ['title' => 'Sales by market', 'columns' => ['Market', 'Kg', 'Revenue', 'Price per kg'], 'rows' => $markets],
            ['title' => 'Prices by crop and grade', 'columns' => ['Crop · grade', 'Kg', 'Price per kg'], 'rows' => $grades],
        ];
    }
}
