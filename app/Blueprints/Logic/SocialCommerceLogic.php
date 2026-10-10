<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Social commerce (WhatsApp catalogue): a product with no stock shows as sold out, and comes back when
 * stocked. A chat order lists its items as lines like "2 x Product"; lines that name catalogue products
 * price the order when no total is given, and come off stock once the order is confirmed (going back if it
 * is cancelled or deleted). A paid order needs proof of payment, and dispatching needs a delivery address.
 */
class SocialCommerceLogic extends AppLogic
{
    /**
     * Order statuses that hold stock.
     *
     * @var list<string>
     */
    public const HOLDING = ['confirmed', 'paid', 'dispatched', 'delivered'];

    /**
     * The next stage for each order stage.
     *
     * @var array<string, string>
     */
    public const NEXT = ['enquiry' => 'confirmed', 'confirmed' => 'paid', 'paid' => 'dispatched', 'dispatched' => 'delivered'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'products') {
            if ((float) ($data['stock'] ?? 0) < 0) {
                $errors['data.stock'] = 'Stock cannot be negative.';
            }

            return $errors;
        }
        $lines = $this->lines((string) ($data['items'] ?? ''));
        if ($lines === [] && (float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Give the order total, or list items as "2 x Product".';
        }
        if (in_array($payload['status'], self::HOLDING, true)) {
            $held = $existing && in_array($existing->status, self::HOLDING, true) ? (array) $existing->value('_taken') : [];
            foreach ($lines as $id => $line) {
                $available = $this->number($line['product'], 'stock') + (float) ($held[$id] ?? 0);
                if ($line['quantity'] > $available) {
                    $errors['data.items'] = 'Only '.(int) $available.' of '.$line['product']->title.' left.';
                    break;
                }
            }
        }
        if (in_array($payload['status'], ['paid', 'dispatched', 'delivered'], true) && blank($data['proof_of_payment'] ?? null)) {
            $errors['data.proof_of_payment'] = 'Attach the proof of payment.';
        }
        if (in_array($payload['status'], ['dispatched', 'delivered'], true) && blank($data['delivery_address'] ?? null)) {
            $errors['data.delivery_address'] = 'Give the delivery address.';
        }

        return $errors;
    }

    /**
     * The catalogue products an order's "2 x Product" lines name, keyed by product id.
     *
     * @return array<int, array{product: Record, quantity: float}>
     */
    protected function lines(string $items): array
    {
        preg_match_all('/^\s*(\d+)\s*[x×*]\s*(.+?)\s*$/mu', $items, $matches, PREG_SET_ORDER);
        $products = $this->records('products')->get()->keyBy(fn (Record $product) => mb_strtolower(trim($product->title)));
        $lines = [];
        foreach ($matches as [, $quantity, $name]) {
            if ($product = $products->get(mb_strtolower($name))) {
                $lines[$product->id] = ['product' => $product, 'quantity' => ($lines[$product->id]['quantity'] ?? 0) + (float) $quantity];
            }
        }

        return $lines;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'products') {
            $stock = $this->number($record, 'stock');
            $record->status = match (true) {
                $record->status === 'hidden' => 'hidden',
                $stock <= 0 => 'sold_out',
                default => 'active',
            };

            return;
        }
        $record->occurs_on ??= today();
        $lines = $this->lines((string) $record->value('items'));
        if ((float) $record->amount <= 0) {
            $record->amount = collect($lines)->sum(fn (array $line) => $line['quantity'] * $this->number($line['product'], 'price'));
        }
        $this->moveStock($record, in_array($record->status, self::HOLDING, true) ? array_map(fn (array $line) => $line['quantity'], $lines) : []);
    }

    /**
     * Take the wanted quantities off stock, putting back whatever the order held before.
     *
     * @param  array<int, float>  $wanted
     */
    protected function moveStock(Record $order, array $wanted): void
    {
        $taken = (array) $order->value('_taken');
        foreach (array_keys($taken + $wanted) as $id) {
            $change = (float) ($wanted[$id] ?? 0) - (float) ($taken[$id] ?? 0);
            if ($change != 0 && ($product = $this->records('products')->find($id))) {
                $product->update(['data' => [...$product->data, 'stock' => max(0, $this->number($product, 'stock') - $change)]]);
            }
        }
        $this->put($order, ['_taken' => $wanted]);
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'orders') {
            $this->moveStock($record, []);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'products') {
            return [];
        }
        $next = self::NEXT[$record->status] ?? null;
        if (! $next) {
            return [];
        }
        $fields = match ($next) {
            'paid' => [['name' => 'proof_of_payment', 'label' => 'Proof of payment link', 'type' => 'url', 'value' => $record->value('proof_of_payment')]],
            'dispatched' => [['name' => 'delivery_address', 'label' => 'Delivery address', 'type' => 'textarea', 'value' => $record->value('delivery_address')]],
            default => [],
        };
        $actions = ['advance' => ['label' => ucfirst($next), 'icon' => $next === 'delivered' ? 'check' : 'arrow-right', 'fields' => $fields]];

        return $record->status === 'delivered' ? $actions : [...$actions, 'cancel' => ['label' => 'Cancel', 'icon' => 'x']];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'cancel') {
            $record->update(['status' => 'cancelled']);

            return 'Order for '.$record->title.' cancelled; stock put back.';
        }
        $next = self::NEXT[$record->status];
        $data = $record->data;
        foreach (['paid' => 'proof_of_payment', 'dispatched' => 'delivery_address'] as $stage => $field) {
            if ($next === $stage) {
                $data[$field] = trim((string) ($request->validate([$field => ['nullable', 'string', 'max:2000']])[$field] ?? '')) ?: $record->value($field);
                if (blank($data[$field])) {
                    throw ValidationException::withMessages([$field => $field === 'proof_of_payment' ? 'Attach the proof of payment.' : 'Give the delivery address.']);
                }
            }
        }
        $errors = $this->validate($record->definition(), ['title' => $record->title, 'status' => $next, 'amount' => $record->amount, 'data' => $data], $record);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $record->update(['status' => $next, 'data' => $data]);

        return 'Order for '.$record->title.' is '.$next.($next === 'confirmed' ? ' ('.$this->money($record->amount).')' : '').'.';
    }

    public function homeCards(): array
    {
        $orders = $this->records('orders')->whereIn('status', ['confirmed', 'paid'])->get();
        $soldOut = $this->records('products')->where('status', 'sold_out')->orderBy('title')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Chat orders', 'icon' => 'message-circle', 'stats' => [
                ['label' => 'Awaiting payment', 'value' => $orders->where('status', 'confirmed')->count()],
                ['label' => 'To dispatch', 'value' => $orders->where('status', 'paid')->count()],
                ['label' => 'Sales this month', 'value' => $this->money($this->records('orders')->whereIn('status', ['paid', 'dispatched', 'delivered'])->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Sold out', 'icon' => 'package-x', 'empty' => 'Everything in the catalogue is in stock.',
                'rows' => $soldOut->map(fn (Record $product) => ['label' => $product->title, 'value' => $this->money($this->number($product, 'price')), 'href' => $product->url(), 'tone' => 'warning'])->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Orders by channel', 'columns' => ['Channel', 'Orders', 'Paid', 'Cancelled', 'Sales'], 'rows' => $this->dated('orders', $from, $to)->get()
            ->groupBy(fn (Record $order) => ucfirst((string) $order->value('channel')))->sortKeys()
            ->map(function ($group, string $channel) {
                $paid = $group->whereIn('status', ['paid', 'dispatched', 'delivered']);

                return [$channel, $group->count(), $paid->count(), $group->where('status', 'cancelled')->count(), $this->money($paid->sum('amount'))];
            })->values()->all()]];
    }
}
