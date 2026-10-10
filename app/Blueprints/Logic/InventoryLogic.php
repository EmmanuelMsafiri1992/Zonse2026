<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Inventory & stock: an item's quantity on hand only changes through stock movements. Receipts,
 * returns and positive adjustments add; sales, damage, transfers out and negative adjustments take
 * away, and nothing can take stock below zero. A posted movement is locked and is undone by reversing
 * it. Movements are valued at cost unless a value is given. SKUs and barcodes are unique, and a
 * discontinued item takes no new stock. Batches expire on their expiry date and recalls need a reason.
 */
class InventoryLogic extends AppLogic
{
    /**
     * Movement types that add stock.
     */
    protected const INWARD = ['received', 'adjustment_in', 'returned'];

    /**
     * Days ahead that expiring batches are shown.
     */
    protected const EXPIRY_WARNING_DAYS = 30;

    /**
     * Guards against re-entering the movement bookkeeping.
     */
    protected static bool $posting = false;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'items') {
            foreach (['sku' => 'SKU', 'barcode' => 'Barcode'] as $field => $label) {
                $code = mb_strtolower(trim((string) ($data[$field] ?? '')));
                $taken = $code === '' ? null : $this->records('items')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                    ->first(fn (Record $item) => mb_strtolower(trim((string) $item->value($field))) === $code);
                if ($taken) {
                    $errors['data.'.$field] = $label.' '.trim((string) $data[$field]).' is already used by '.$taken->title.'.';
                }
            }
            if ((float) ($data['quantity'] ?? 0) < 0) {
                $errors['data.quantity'] = 'Stock on hand cannot be negative.';
            }
            if ($existing && (float) ($data['quantity'] ?? 0) !== $this->number($existing, 'quantity')) {
                $errors['data.quantity'] = 'Change stock with a movement or a stock count so there is a record of it.';
            }

            return $errors;
        }

        if ($entity->key === 'batches') {
            if ((float) ($data['quantity'] ?? 0) <= 0) {
                $errors['data.quantity'] = 'Enter how many are in the batch.';
            }
            if (filled($payload['due_on'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'A batch cannot expire before it was received.';
            }
            if ($payload['status'] === 'recalled' && $existing?->status !== 'recalled') {
                $errors['status'] = 'Use "Recall" so the reason is recorded.';
            }

            return $errors;
        }

        if ($existing) {
            if ($existing->status === 'reversed') {
                $errors['status'] = 'This movement has been reversed.';
            }
            foreach (['item', 'type', 'quantity'] as $field) {
                if ((string) ($data[$field] ?? '') !== (string) $existing->value($field)) {
                    $errors['data.'.$field] = 'A posted movement cannot be changed; reverse it and post a new one.';
                }
            }

            return $errors;
        }

        $quantity = (float) ($data['quantity'] ?? 0);
        if ($quantity <= 0) {
            $errors['data.quantity'] = 'Enter a quantity above zero.';
        }
        $item = filled($data['item'] ?? null) ? $this->records('items')->find($data['item']) : null;
        if ($item && $payload['status'] === 'posted' && $quantity > 0) {
            if ($problem = $this->cannotMove($item, (string) ($data['type'] ?? ''), $quantity)) {
                $errors['data.quantity'] = $problem;
            }
        }

        return $errors;
    }

    /**
     * Why the movement can't be posted against the item, if it can't.
     */
    protected function cannotMove(Record $item, string $type, float $quantity): ?string
    {
        if (in_array($type, self::INWARD, true)) {
            return $item->status === 'discontinued' && $type === 'received' ? $item->title.' is discontinued.' : null;
        }
        $onHand = $this->number($item, 'quantity');

        return $quantity > $onHand ? 'Only '.$this->quantity($onHand).' '.$item->title.' in stock.' : null;
    }

    /**
     * A quantity without trailing zeros.
     */
    protected function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.');
    }

    /**
     * How a movement changes the stock on hand.
     */
    protected function delta(Record $movement): float
    {
        if ($movement->status !== 'posted') {
            return 0;
        }

        return (in_array($movement->value('type'), self::INWARD, true) ? 1 : -1) * $this->number($movement, 'quantity');
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'items') {
            $cost = $this->number($record, 'cost_price');
            $price = $this->number($record, 'selling_price');
            $this->put($record, [
                '_margin' => $price > 0 ? round(($price - $cost) / $price * 100, 1) : null,
                '_value' => round($this->number($record, 'quantity') * $cost, 2),
            ]);

            return;
        }
        $record->occurs_on ??= today();
        if ($record->entity === 'movements' && $record->amount === null && ($item = $this->parent($record, 'item'))) {
            $record->amount = round($this->number($record, 'quantity') * $this->number($item, 'cost_price'), 2);
        }
        if ($record->entity === 'batches' && $record->status === 'in_stock' && $record->due_on?->lt(today())) {
            $record->status = 'expired';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'movements' || static::$posting) {
            return;
        }
        $change = $this->delta($record) - (float) ($record->value('_applied') ?? 0);
        if ($change == 0 || ! ($item = $this->parent($record, 'item'))) {
            return;
        }
        static::$posting = true;
        try {
            $item->update(['data' => [...$item->data, 'quantity' => round($this->number($item, 'quantity') + $change, 4)]]);
            $this->put($record, ['_applied' => $this->delta($record)]);
            $record->saveQuietly();
        } finally {
            static::$posting = false;
        }
    }

    public function deleted(Record $record): void
    {
        $applied = (float) ($record->value('_applied') ?? 0);
        if ($record->entity === 'movements' && $applied != 0 && ($item = $this->parent($record, 'item'))) {
            $item->update(['data' => [...$item->data, 'quantity' => round($this->number($item, 'quantity') - $applied, 4)]]);
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->entity) {
            'items' => [
                ...($record->status === 'active' ? ['receive' => ['label' => 'Receive stock', 'icon' => 'package-plus', 'fields' => [
                    ['name' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
                    ['name' => 'reference', 'label' => 'Delivery note / invoice', 'type' => 'text'],
                ]]] : []),
                'count' => ['label' => 'Stock count', 'icon' => 'clipboard-check', 'fields' => [['name' => 'counted', 'label' => 'Quantity counted', 'type' => 'number', 'value' => $record->value('quantity')]]],
            ],
            'movements' => $record->status === 'posted' ? ['reverse' => ['label' => 'Reverse', 'icon' => 'undo-2', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]] : [],
            default => $record->status === 'in_stock' ? ['recall' => ['label' => 'Recall', 'icon' => 'triangle-alert', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]] : [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'reverse') {
            $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
            $item = $this->parent($record, 'item');
            if ($item && $this->delta($record) > 0 && $this->number($record, 'quantity') > $this->number($item, 'quantity')) {
                throw ValidationException::withMessages(['reason' => 'Only '.$this->quantity($this->number($item, 'quantity')).' '.$item->title.' left; the stock has already moved on.']);
            }
            $record->update(['status' => 'reversed', 'data' => [...$record->data, '_reverse_reason' => $reason]]);

            return $record->number.' reversed; '.$item?->title.' now '.$this->quantity($this->number($item->fresh(), 'quantity')).'.';
        }

        if ($action === 'recall') {
            $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
            $record->update(['status' => 'recalled', 'data' => [...$record->data, '_recall_reason' => $reason]]);

            return 'Batch '.$record->title.' recalled: '.$reason.'.';
        }

        if ($action === 'receive') {
            $values = $request->validate(['quantity' => ['required', 'numeric', 'gt:0'], 'reference' => ['nullable', 'string', 'max:255']]);
            $this->move($record, 'received', (float) $values['quantity'], 'Received', $values['reference'] ?? null);

            return $this->quantity((float) $values['quantity']).' '.$record->title.' received; '.$this->quantity($this->number($record->fresh(), 'quantity')).' on hand.';
        }

        $counted = (float) $request->validate(['counted' => ['required', 'numeric', 'min:0']])['counted'];
        $difference = round($counted - $this->number($record, 'quantity'), 4);
        if ($difference == 0) {
            return $record->title.' count matches: '.$this->quantity($counted).' on hand.';
        }
        $this->move($record, $difference > 0 ? 'adjustment_in' : 'adjustment_out', abs($difference), 'Stock count');

        return $record->title.' counted at '.$this->quantity($counted).': '.($difference > 0 ? '+' : '−').$this->quantity(abs($difference)).' adjusted.';
    }

    /**
     * Post a stock movement for the item.
     */
    protected function move(Record $item, string $type, float $quantity, string $reason, ?string $reference = null): Record
    {
        return Record::create([
            'workspace_id' => $item->workspace_id, 'blueprint' => $item->blueprint, 'entity' => 'movements',
            'title' => $reason, 'status' => 'posted', 'occurs_on' => today(),
            'data' => ['item' => $item->id, 'type' => $type, 'quantity' => $quantity, 'reference' => $reference],
        ]);
    }

    public function daily(Workspace $workspace): int
    {
        $expired = $this->records('batches')->where('status', 'in_stock')->where('due_on', '<', today()->startOfDay())->get();
        $expired->each(fn (Record $batch) => $batch->update(['status' => 'expired']));

        return $expired->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'items') {
            return [];
        }
        $movements = $this->linked('movements', 'item', $record)->orderByDesc('occurs_on')->orderByDesc('id')->limit(10)->get();
        $batches = $this->linked('batches', 'item', $record)->where('status', 'in_stock')->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Recent movements', 'icon' => 'arrow-left-right', 'empty' => 'No movements yet.',
                'rows' => $movements->map(fn (Record $movement) => [
                    'label' => ucfirst(str_replace('_', ' ', (string) $movement->value('type'))), 'sub' => $movement->occurs_on?->format('d M Y').' · '.$movement->title,
                    'value' => ($this->delta($movement) >= 0 && $movement->status === 'posted' ? '+' : ($movement->status === 'posted' ? '−' : '')).$this->quantity($this->number($movement, 'quantity')).($movement->status === 'reversed' ? ' (reversed)' : ''),
                    'href' => $movement->url(), 'tone' => $movement->status === 'reversed' ? null : ($this->delta($movement) >= 0 ? 'success' : 'danger'),
                ])->values()->all(),
            ]],
            ...($batches->isEmpty() ? [] : [['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Batches in stock', 'icon' => 'boxes', 'empty' => '',
                'rows' => $batches->map(fn (Record $batch) => ['label' => $batch->title, 'sub' => $this->quantity($this->number($batch, 'quantity')).' units', 'value' => $batch->due_on ? 'Expires '.$batch->due_on->format('d M Y') : '—', 'href' => $batch->url()])->values()->all(),
            ]]]),
        ];
    }

    public function homeCards(): array
    {
        $low = $this->records('items')->where('status', 'active')->get()
            ->filter(fn (Record $item) => $item->value('reorder_level') !== null && $this->number($item, 'quantity') <= $this->number($item, 'reorder_level'))
            ->sortBy(fn (Record $item) => $this->number($item, 'quantity'));
        $expiring = $this->records('batches')->where('status', 'in_stock')->whereNotNull('due_on')->where('due_on', '<=', today()->addDays(self::EXPIRY_WARNING_DAYS)->endOfDay())->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'To reorder', 'icon' => 'shopping-cart', 'empty' => 'Everything is above its reorder level.',
                'rows' => $low->map(fn (Record $item) => ['label' => $item->title, 'sub' => $item->value('sku'), 'value' => $this->quantity($this->number($item, 'quantity')).' / '.$this->quantity($this->number($item, 'reorder_level')), 'href' => $item->url(), 'tone' => $this->number($item, 'quantity') <= 0 ? 'danger' : 'warning'])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Expiring soon', 'icon' => 'calendar-clock', 'empty' => 'No batches expire in the next '.self::EXPIRY_WARNING_DAYS.' days.',
                'rows' => $expiring->map(fn (Record $batch) => ['label' => $batch->title, 'sub' => $this->parent($batch, 'item')?->title, 'value' => $batch->due_on->format('d M Y'), 'href' => $batch->url(), 'tone' => $batch->due_on->lte(today()->addDays(7)) ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $items = $this->records('items')->where('status', 'active')->orderBy('title')->get();
        $movements = $this->dated('movements', $from, $to)->where('status', 'posted')->get();
        $names = $this->records('items')->pluck('title', 'id');

        $valuation = $items->map(fn (Record $item) => [
            $item->title, $item->value('sku'), $this->quantity($this->number($item, 'quantity')),
            $this->money($this->number($item, 'quantity') * $this->number($item, 'cost_price')),
            $this->money($this->number($item, 'quantity') * $this->number($item, 'selling_price')),
        ])->values()->all();
        $valuation[] = ['Total', '', '', $this->money($items->sum(fn (Record $item) => $this->number($item, 'quantity') * $this->number($item, 'cost_price'))), $this->money($items->sum(fn (Record $item) => $this->number($item, 'quantity') * $this->number($item, 'selling_price')))];

        $byType = $movements->groupBy(fn (Record $movement) => (string) $movement->value('type'))->sortKeys()
            ->map(fn (Collection $group, string $type) => [ucfirst(str_replace('_', ' ', $type)), $group->count(), $this->quantity($group->sum(fn (Record $movement) => $this->number($movement, 'quantity'))), $this->money($group->sum('amount'))])
            ->values()->all();

        $losses = $movements->whereIn('data.type', ['damaged', 'adjustment_out'])->groupBy(fn (Record $movement) => (int) $movement->value('item'))
            ->map(fn (Collection $group, int $item) => [$names[$item] ?? '—', $this->quantity($group->sum(fn (Record $movement) => $this->number($movement, 'quantity'))), $this->money($group->sum('amount'))])
            ->sortBy(fn (array $row) => $row[0])->values()->all();

        return [
            ['title' => 'Stock valuation', 'columns' => ['Item', 'SKU', 'On hand', 'At cost', 'At selling price'], 'rows' => $valuation],
            ['title' => 'Movements by type', 'columns' => ['Type', 'Movements', 'Quantity', 'Value'], 'rows' => $byType],
            ['title' => 'Shrinkage and damage', 'columns' => ['Item', 'Quantity lost', 'Value'], 'rows' => $losses],
        ];
    }
}
