<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Grain storage: a receipt can only be issued for grain dry enough to store, of the store's
 * commodity, into a store with room that is not being fumigated; a pledged receipt names the
 * bank and cannot be withdrawn until released; each store's stock is the grain on open receipts.
 */
class GrainStorageLogic extends AppLogic
{
    public const MAX_MOISTURE = 13.5;

    public const HELD = ['issued', 'pledged'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'receipts') {
            return [];
        }

        $data = $payload['data'];
        $errors = [];

        if ($payload['status'] === 'pledged' && blank($data['pledged_to'] ?? null)) {
            $errors['data.pledged_to'] = 'Name the bank the receipt is pledged to.';
        }
        if ($existing?->status === 'pledged' && $payload['status'] === 'withdrawn') {
            $errors['status'] = 'Release the pledge with '.($existing->value('pledged_to') ?: 'the bank').' before the grain is withdrawn.';
        }

        $opening = ! $existing || ! in_array($existing->status, self::HELD, true);
        if ($opening && in_array($payload['status'], self::HELD, true) && filled($data['moisture'] ?? null) && (float) $data['moisture'] > self::MAX_MOISTURE) {
            $errors['data.moisture'] = 'Grain above '.self::MAX_MOISTURE.'% moisture must be dried before it is stored.';
        }

        $store = ! empty($data['store']) ? $this->records('stores')->find($data['store']) : null;
        if ($store && in_array($payload['status'], self::HELD, true)) {
            $counted = $existing && in_array($existing->status, self::HELD, true) && (int) $existing->value('store') === $store->id;
            if (! $counted && $store->status === 'fumigating') {
                $errors['data.store'] = $store->title.' is being fumigated.';
            } elseif (filled($store->value('commodity')) && filled($data['commodity'] ?? null) && $store->value('commodity') !== $data['commodity']) {
                $errors['data.commodity'] = $store->title.' holds '.$store->value('commodity').'.';
            } else {
                $room = round($this->number($store, 'capacity') - $this->stock($store) + ($counted ? $this->number($existing, 'weight') : 0), 3);
                if ((float) ($data['weight'] ?? 0) - $room > 0.0005) {
                    $errors['data.weight'] = $store->title.' only has room for '.max(0, $room).' t.';
                }
            }
        }

        return $errors;
    }

    public function stock(Record $store): float
    {
        return round((float) $this->linked('receipts', 'store', $store)->whereIn('status', self::HELD)->get()->sum(fn (Record $receipt) => $this->number($receipt, 'weight')), 3);
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'stores' || ! $record->exists) {
            return;
        }

        $stock = $this->stock($record);
        $this->put($record, ['current_stock' => $stock, '_fill' => $this->number($record, 'capacity') > 0 ? round($stock / $this->number($record, 'capacity') * 100, 1) : null]);
        if ($record->status !== 'fumigating') {
            $record->status = $stock > 0 ? 'active' : 'empty';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'receipts') {
            $this->recalculate($this->parent($record, 'store'));
            $this->recalculate($this->previousParent($record, 'store'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'receipts') {
            $this->recalculate($this->parent($record, 'store'));
        }
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'receipts' ? ['receipt' => 'Warehouse receipt'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'receipt' || $record->entity !== 'receipts') {
            return null;
        }

        $store = $this->parent($record, 'store');

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Warehouse receipt '.$record->number,
            'meta' => array_filter(['Depositor' => $record->contact?->name ?? $record->title, 'Store' => $store?->title, 'Deposited on' => $record->occurs_on?->format('d M Y'), 'Status' => ucfirst($record->status),
                'Pledged to' => $record->status === 'pledged' ? $record->value('pledged_to') : null]),
            'columns' => ['Commodity', 'Net weight (t)', 'Moisture %', 'Grade'],
            'rows' => [[ucfirst((string) $record->value('commodity')), $this->number($record, 'weight'), $record->value('moisture') ?? '—', (string) ($record->value('grade') ?? '—')]],
            'totals' => ['Storage fees' => $this->money($record->amount)],
            'notes' => 'This receipt is the depositor\'s title to the grain. Grain is released only against this receipt, and not while it is pledged.',
        ]];
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'stores') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Stock', 'icon' => 'warehouse', 'stats' => [
            ['label' => 'In store', 'value' => $this->number($record, 'current_stock').' t of '.$this->number($record, 'capacity').' t'],
            ['label' => 'Full', 'value' => ($record->value('_fill') ?? 0).'%', 'tone' => $this->number($record, '_fill') >= 90 ? 'warning' : null],
            ['label' => 'Room left', 'value' => max(0, round($this->number($record, 'capacity') - $this->number($record, 'current_stock'), 3)).' t'],
        ]]]];
    }

    public function homeCards(): array
    {
        $stores = $this->records('stores')->orderBy('title')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Stores', 'icon' => 'warehouse', 'empty' => 'No stores yet.',
            'rows' => $stores->map(fn (Record $store) => [
                'label' => $store->title, 'sub' => ucfirst((string) ($store->value('commodity') ?? '')).($store->status === 'fumigating' ? ' · fumigating' : ''),
                'value' => $this->number($store, 'current_stock').' / '.$this->number($store, 'capacity').' t', 'href' => $store->url(), 'tone' => $this->number($store, '_fill') >= 90 ? 'warning' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $held = $this->records('receipts')->whereIn('status', self::HELD)->with('contact')->get();
        $commodities = $held->groupBy(fn (Record $receipt) => (string) $receipt->value('commodity'))->sortKeys()->map(fn ($group, $commodity) => [
            ucfirst($commodity), round($group->sum(fn (Record $receipt) => $this->number($receipt, 'weight')), 3),
            round($group->where('status', 'pledged')->sum(fn (Record $receipt) => $this->number($receipt, 'weight')), 3), $group->count(),
        ])->values()->all();

        $depositors = $held->groupBy(fn (Record $receipt) => $receipt->contact?->name ?? $receipt->title)->sortKeys()->map(fn ($group, $depositor) => [
            $depositor, $group->count(), round($group->sum(fn (Record $receipt) => $this->number($receipt, 'weight')), 3), $this->money($group->sum('amount')),
        ])->values()->all();

        $fees = $this->dated('receipts', $from, $to)->whereNot('status', 'cancelled')->sum('amount');

        return [
            ['title' => 'Grain held', 'columns' => ['Commodity', 'Tonnes', 'Pledged (t)', 'Receipts'], 'rows' => $commodities],
            ['title' => 'Depositors', 'columns' => ['Depositor', 'Receipts', 'Tonnes', 'Storage fees'], 'rows' => $depositors],
            ['title' => 'Storage fees in period', 'columns' => ['Fees'], 'rows' => [[$this->money($fees)]]],
        ];
    }
}
