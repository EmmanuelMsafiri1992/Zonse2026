<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\Item;

/**
 * Inventory valuation: a landed cost adds freight, duty and clearing to the supplier cost and
 * shows the uplift; a valuation snapshots every stocked product from Invoicing at its cost
 * price and totals the closing stock value. A final valuation is frozen.
 */
class InventoryValuationLogic extends AppLogic
{
    public const COSTS = ['supplier_cost', 'freight', 'duty', 'clearing'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'valuations' && $existing?->status === 'final' && $payload['status'] === 'final' && (float) ($payload['amount'] ?? 0) !== (float) $existing->amount) {
            return ['amount' => 'This valuation is final. Set it back to draft to change the value.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'landed_costs') {
            return;
        }

        $supplier = $this->number($record, 'supplier_cost');
        $record->amount = round(array_sum(array_map(fn (string $cost) => $this->number($record, $cost), self::COSTS)), 2);
        $this->put($record, ['_uplift' => $supplier > 0 ? round(((float) $record->amount - $supplier) / $supplier * 100, 2) : null]);
    }

    /**
     * Snapshot every active stocked product at its cost price.
     *
     * @return list<array{item: string, sku: string|null, quantity: float, cost: float, value: float}>
     */
    public function stockLines(): array
    {
        return Item::query()->active()->where('type', 'product')->whereNotNull('stock_qty')->where('stock_qty', '>', 0)->orderBy('name')->get()
            ->map(fn (Item $item) => [
                'item' => $item->name, 'sku' => $item->sku, 'quantity' => (float) $item->stock_qty,
                'cost' => (float) $item->cost, 'value' => round((float) $item->stock_qty * (float) $item->cost, 2),
            ])->values()->all();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'valuations' && $record->status === 'draft') {
            return ['calculate' => ['label' => 'Calculate from stock', 'icon' => 'calculator', 'confirm' => 'Value the stock on hand now at each product\'s cost price?']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if (! $this->billing()->available()) {
            throw ValidationException::withMessages(['action' => 'Switch on Invoicing to value stock from your products.']);
        }

        $lines = $this->stockLines();
        $record->amount = round(array_sum(array_column($lines, 'value')), 2);
        $this->put($record, ['_lines' => $lines, '_calculated_at' => now()->toDateTimeString()]);
        $record->save();

        return count($lines).' product(s) valued at '.$this->money($record->amount).'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'landed_costs') {
            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Landed cost', 'icon' => 'ship', 'stats' => [
                ['label' => 'Supplier cost', 'value' => $this->money($record->value('supplier_cost'))],
                ['label' => 'Add-on costs', 'value' => $this->money((float) $record->amount - $this->number($record, 'supplier_cost'))],
                ['label' => 'Total landed', 'value' => $this->money($record->amount)],
                ['label' => 'Uplift on cost', 'value' => $record->value('_uplift') !== null ? $record->value('_uplift').'%' : '—'],
            ], 'note' => 'Raise each product\'s cost price by the uplift to carry these costs into stock.']]];
        }

        if ($record->entity === 'valuations' && $record->value('_lines')) {
            $lines = collect($record->value('_lines'));

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Stock valued', 'icon' => 'calculator', 'stats' => [
                ['label' => 'Products', 'value' => (string) $lines->count()],
                ['label' => 'Units', 'value' => (string) $lines->sum('quantity')],
                ['label' => 'Closing value', 'value' => $this->money($record->amount)],
            ], 'note' => 'Calculated '.Carbon::parse($record->value('_calculated_at'))->format('d M Y H:i').' at each product\'s current cost price.']]];
        }

        return [];
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'valuations' && $record->value('_lines') ? ['stock_sheet' => 'Stock sheet'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'stock_sheet' || ! $record->value('_lines')) {
            return null;
        }

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Stock valuation',
            'meta' => array_filter(['Valuation' => $record->number, 'Date' => ($record->occurs_on ?? $record->created_at)->format('d M Y'), 'Location' => $record->value('location'), 'Status' => ucfirst($record->status)]),
            'columns' => ['Item', 'SKU', 'Quantity', 'Unit cost', 'Value'],
            'rows' => array_map(fn (array $line) => [$line['item'], $line['sku'] ?? '', $line['quantity'], $this->money($line['cost']), $this->money($line['value'])], $record->value('_lines')),
            'totals' => ['Closing stock value' => $this->money($record->amount)],
            'notes' => 'Valued at each product\'s cost price when calculated.',
        ]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $valuations = $this->dated('valuations', $from, $to)->orderBy('occurs_on')->get()
            ->map(fn (Record $valuation) => [($valuation->occurs_on ?? $valuation->created_at)->format('d M Y'), $valuation->number, $valuation->title, str_replace('_', ' ', (string) $valuation->value('method')), ucfirst($valuation->status), $this->money($valuation->amount)])->all();
        $landed = $this->dated('landed_costs', $from, $to)->get()
            ->map(fn (Record $line) => [$line->title, ...array_map(fn (string $cost) => $this->money($line->value($cost)), self::COSTS), $this->money($line->amount), $line->value('_uplift') !== null ? $line->value('_uplift').'%' : '—'])->all();

        return [
            ['title' => 'Stock value over time', 'columns' => ['Date', 'Valuation', 'Period', 'Method', 'Status', 'Closing value'], 'rows' => $valuations],
            ['title' => 'Landed costs', 'columns' => ['Shipment', 'Supplier cost', 'Freight', 'Duty', 'Clearing', 'Total', 'Uplift'], 'rows' => $landed],
        ];
    }
}
