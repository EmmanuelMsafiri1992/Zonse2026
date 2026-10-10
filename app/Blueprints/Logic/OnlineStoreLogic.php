<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Online store: each SKU belongs to one product, a compare-at price is above the selling price, and a
 * product with no stock shows as out of stock (and comes back when restocked). An order needs a
 * shipping address unless it is collected, and it ships with a tracking number when it goes by courier
 * or post. Orders move paid → packed → shipped → delivered, and only paid orders can be refunded.
 */
class OnlineStoreLogic extends AppLogic
{
    /**
     * Shipping methods that leave with a tracking number.
     *
     * @var list<string>
     */
    public const TRACKED = ['courier', 'post'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'products') {
            $sku = mb_strtolower(trim((string) ($data['sku'] ?? '')));
            if ($sku !== '' && ($twin = $this->records('products')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $product) => mb_strtolower(trim((string) $product->value('sku'))) === $sku))) {
                $errors['data.sku'] = $twin->title.' already uses SKU '.$data['sku'].'.';
            }
            if ((float) ($data['compare_at_price'] ?? 0) > 0 && (float) $data['compare_at_price'] <= (float) ($data['price'] ?? 0)) {
                $errors['data.compare_at_price'] = 'The compare-at price must be above the selling price.';
            }
            if ((float) ($data['stock'] ?? 0) < 0) {
                $errors['data.stock'] = 'Stock cannot be negative.';
            }
        }
        if ($entity->key === 'orders') {
            $method = $data['shipping_method'] ?? null;
            if ($method !== 'collection' && in_array($payload['status'], ['packed', 'shipped', 'delivered'], true) && blank($data['shipping_address'] ?? null)) {
                $errors['data.shipping_address'] = 'Give the shipping address.';
            }
            if (in_array($method, self::TRACKED, true) && in_array($payload['status'], ['shipped', 'delivered'], true) && blank($data['tracking_number'] ?? null)) {
                $errors['data.tracking_number'] = 'Give the tracking number.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'orders') {
            $record->occurs_on ??= today();

            return;
        }
        $stock = $this->number($record, 'stock');
        if ($record->status === 'active' && $stock <= 0) {
            $record->status = 'out_of_stock';
        } elseif ($record->status === 'out_of_stock' && $stock > 0) {
            $record->status = 'active';
        }
        $compare = $this->number($record, 'compare_at_price');
        $this->put($record, ['_discount_percent' => $compare > 0 && $this->number($record, 'price') > 0 ? round((1 - $this->number($record, 'price') / $compare) * 100) : null]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'orders') {
            return [];
        }
        $refund = ['refund' => ['label' => 'Refund', 'icon' => 'undo-2']];

        return match ($record->status) {
            'pending_payment' => ['paid' => ['label' => 'Payment received', 'icon' => 'banknote'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'paid' => ['pack' => ['label' => 'Packed', 'icon' => 'package'], ...$refund],
            'packed' => ['ship' => ['label' => $record->value('shipping_method') === 'collection' ? 'Collected' : 'Shipped', 'icon' => 'truck', 'fields' => in_array($record->value('shipping_method'), self::TRACKED, true) ? [['name' => 'tracking_number', 'label' => 'Tracking number', 'type' => 'text', 'value' => $record->value('tracking_number')]] : []], ...$refund],
            'shipped' => ['deliver' => ['label' => 'Delivered', 'icon' => 'check'], ...$refund],
            'delivered' => $refund,
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'ship') {
            $tracking = trim((string) ($request->validate(['tracking_number' => ['nullable', 'string', 'max:100']])['tracking_number'] ?? '')) ?: $record->value('tracking_number');
            if (in_array($record->value('shipping_method'), self::TRACKED, true) && blank($tracking)) {
                throw ValidationException::withMessages(['tracking_number' => 'Give the tracking number.']);
            }
            if ($record->value('shipping_method') === 'collection') {
                $record->update(['status' => 'delivered']);

                return $record->title.' collected the order.';
            }
            $record->update(['status' => 'shipped', 'data' => [...$record->data, 'tracking_number' => $tracking]]);

            return $record->title.'\'s order shipped'.($tracking ? ', tracking '.$tracking : '').'.';
        }
        $status = ['paid' => 'paid', 'pack' => 'packed', 'deliver' => 'delivered', 'cancel' => 'cancelled', 'refund' => 'refunded'][$action];
        if ($status === 'packed' && $record->value('shipping_method') !== 'collection' && blank($record->value('shipping_address'))) {
            throw ValidationException::withMessages(['shipping_address' => 'Give the shipping address.']);
        }
        $record->update(['status' => $status]);

        return $record->title.'\'s order is '.$status.($status === 'refunded' ? ' ('.$this->money($record->amount).')' : '').'.';
    }

    public function homeCards(): array
    {
        $orders = $this->records('orders')->whereIn('status', ['pending_payment', 'paid', 'packed'])->get();
        $low = $this->records('products')->whereIn('status', ['active', 'out_of_stock'])->get()->filter(fn (Record $product) => $this->number($product, 'stock') <= 5)->sortBy(fn (Record $product) => $this->number($product, 'stock'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Orders to handle', 'icon' => 'shopping-cart', 'stats' => [
                ['label' => 'Awaiting payment', 'value' => $orders->where('status', 'pending_payment')->count()],
                ['label' => 'To pack', 'value' => $orders->where('status', 'paid')->count(), 'tone' => $orders->where('status', 'paid')->isNotEmpty() ? 'warning' : null],
                ['label' => 'To ship', 'value' => $orders->where('status', 'packed')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Low stock', 'icon' => 'package', 'empty' => 'Every product has more than 5 in stock.',
                'rows' => $low->map(fn (Record $product) => ['label' => $product->title, 'sub' => $product->value('sku'), 'value' => (int) $this->number($product, 'stock').' left', 'href' => $product->url(), 'tone' => $product->status === 'out_of_stock' ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $orders = $this->dated('orders', $from, $to)->get();
        $sold = $orders->whereIn('status', ['paid', 'packed', 'shipped', 'delivered']);
        $refunded = $orders->where('status', 'refunded');

        return [['title' => 'Sales by month', 'columns' => ['Month', 'Orders', 'Sales', 'Refunds', 'Average order'], 'rows' => collect($this->months($from, $to))
            ->map(function (string $label, string $month) use ($sold, $refunded) {
                $inMonth = $sold->filter(fn (Record $order) => $order->occurs_on->format('Y-m') === $month);
                $refunds = $refunded->filter(fn (Record $order) => $order->occurs_on->format('Y-m') === $month);

                return [$label, $inMonth->count(), $this->money($inMonth->sum('amount')), $this->money($refunds->sum('amount')), $inMonth->isNotEmpty() ? $this->money($inMonth->avg('amount')) : '—'];
            })->values()->all()]];
    }
}
