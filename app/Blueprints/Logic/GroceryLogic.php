<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Supermarket / grocery: each barcode belongs to one item, and an item's shelf status follows its stock
 * (low at or below its reorder level, out at zero) unless it is delisted. A batch nearing expiry can be
 * marked down below the shelf price, then sold or written off; both take it off stock, and the money lost
 * is kept. Batches still on the shelf after their expiry date are written off each night.
 */
class GroceryLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'products') {
            $barcode = trim((string) ($data['barcode'] ?? ''));
            if ($barcode !== '' && ($twin = $this->records('products')->where('data->barcode', $barcode)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first())) {
                $errors['data.barcode'] = $twin->title.' already uses barcode '.$barcode.'.';
            }
            if ((float) ($data['stock'] ?? 0) < 0) {
                $errors['data.stock'] = 'Stock cannot be negative.';
            }

            return $errors;
        }
        $product = filled($data['product'] ?? null) ? $this->records('products')->find($data['product']) : null;
        $markdown = (float) ($data['markdown_price'] ?? 0);
        if ($payload['status'] === 'marked_down' && $markdown <= 0) {
            $errors['data.markdown_price'] = 'Give the markdown price.';
        }
        if ($product && $markdown > 0 && $markdown >= $this->number($product, 'price')) {
            $errors['data.markdown_price'] = 'The markdown price must be below the shelf price of '.$this->money($this->number($product, 'price')).'.';
        }
        if ((float) ($data['quantity'] ?? 0) <= 0) {
            $errors['data.quantity'] = 'Give the quantity in this batch.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'products') {
            if ($record->status === 'delisted') {
                return;
            }
            $stock = $this->number($record, 'stock');
            $record->status = match (true) {
                $stock <= 0 => 'out_of_stock',
                $stock <= $this->number($record, 'reorder_level') => 'low_stock',
                default => 'on_shelf',
            };

            return;
        }
        $product = $this->parent($record, 'product');
        $price = $product ? $this->number($product, 'price') : 0;
        $quantity = $this->number($record, 'quantity');
        $this->put($record, ['_loss' => match ($record->status) {
            'written_off' => round($quantity * $price, 2),
            'sold' => round($quantity * max(0, $price - ($this->number($record, 'markdown_price') ?: $price)), 2),
            default => null,
        }]);
    }

    /**
     * Close a batch as sold or written off, taking it off the item's stock.
     */
    public function close(Record $batch, string $status): void
    {
        if ($product = $this->parent($batch, 'product')) {
            $product->update(['data' => [...$product->data, 'stock' => max(0, $this->number($product, 'stock') - $this->number($batch, 'quantity'))]]);
        }
        $batch->update(['status' => $status]);
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('expiries')->whereIn('status', ['tracked', 'marked_down'])->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $batch) => $this->close($batch, 'written_off'))->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'expiries' || ! in_array($record->status, ['tracked', 'marked_down'], true)) {
            return [];
        }
        $close = ['sold' => ['label' => 'Sold', 'icon' => 'shopping-cart'], 'write_off' => ['label' => 'Write off', 'icon' => 'trash-2']];

        return $record->status === 'tracked'
            ? ['markdown' => ['label' => 'Mark down', 'icon' => 'tag', 'fields' => [['name' => 'markdown_price', 'label' => 'Markdown price', 'type' => 'number', 'value' => $record->value('markdown_price')]]], ...$close]
            : $close;
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'markdown') {
            $price = (float) $request->validate(['markdown_price' => ['required', 'numeric', 'gt:0']])['markdown_price'];
            $product = $this->parent($record, 'product');
            if ($product && $price >= $this->number($product, 'price')) {
                throw ValidationException::withMessages(['markdown_price' => 'The markdown price must be below the shelf price of '.$this->money($this->number($product, 'price')).'.']);
            }
            $record->update(['status' => 'marked_down', 'data' => [...$record->data, 'markdown_price' => $price]]);

            return $record->title.' marked down to '.$this->money($price).'.';
        }
        $this->close($record, $action === 'sold' ? 'sold' : 'written_off');

        return $record->title.($record->status === 'sold' ? ' sold' : ' written off').($record->value('_loss') > 0 ? ', losing '.$this->money($record->value('_loss')) : '').'.';
    }

    public function homeCards(): array
    {
        $soon = $this->records('expiries')->whereIn('status', ['tracked', 'marked_down'])->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(3)->toDateString())->orderBy('due_on')->get();
        $names = $this->records('products')->pluck('title', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Expiring within 3 days', 'icon' => 'calendar-x', 'empty' => 'Nothing on the shelf expires in the next 3 days.',
            'rows' => $soon->map(fn (Record $batch) => ['label' => $names[(int) $batch->value('product')] ?? $batch->title, 'sub' => (int) $this->number($batch, 'quantity').' × '.($batch->status === 'marked_down' ? 'marked down to '.$this->money($this->number($batch, 'markdown_price')) : 'full price'),
                'value' => $batch->due_on->isToday() ? 'today' : $batch->due_on->format('d M'), 'href' => $batch->url(), 'tone' => $batch->status === 'marked_down' ? 'warning' : 'danger'])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $products = $this->records('products')->get()->keyBy('id');
        $closed = $this->records('expiries')->whereIn('status', ['sold', 'written_off'])->whereBetween('updated_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();

        return [['title' => 'Expiry losses by department', 'columns' => ['Department', 'Batches sold marked down', 'Batches written off', 'Money lost'], 'rows' => $closed
            ->groupBy(fn (Record $batch) => ucfirst(str_replace('_', ' ', (string) $products->get((int) $batch->value('product'))?->value('department'))) ?: 'Unknown')->sortKeys()
            ->map(fn ($group, string $department) => [$department, $group->where('status', 'sold')->count(), $group->where('status', 'written_off')->count(), $this->money($group->sum(fn (Record $batch) => (float) $batch->value('_loss')))])
            ->values()->all()]];
    }
}
