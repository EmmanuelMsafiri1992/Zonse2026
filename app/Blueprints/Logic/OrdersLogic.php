<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Order management: an order lists one item per line ("2 x Widget @ 10") and its total follows the
 * priced lines. Orders move forward from new to delivered and can be cancelled until they ship.
 * Items that can't be supplied yet go onto back-orders; while any wait, the order can only be part
 * shipped, and it becomes shipped when the last back-order is fulfilled. Cancelling an order cancels
 * its open back-orders.
 */
class OrdersLogic extends AppLogic
{
    /**
     * Order steps in order.
     */
    protected const STEPS = ['new', 'confirmed', 'picking', 'part_shipped', 'shipped', 'delivered'];

    /**
     * Guards against re-entering order updates from back-orders.
     */
    protected static bool $syncing = false;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($entity->key === 'backorders') {
            if ((float) ($data['quantity'] ?? 0) <= 0) {
                $errors['data.quantity'] = 'Enter a quantity above zero.';
            }
            $order = filled($data['order'] ?? null) ? $this->records('orders')->find($data['order']) : null;
            if (! $existing && $order && in_array($order->status, ['shipped', 'delivered', 'cancelled'], true)) {
                $errors['data.order'] = $order->title.' is '.str_replace('_', ' ', $order->status).'.';
            }
            if ($existing && in_array($existing->status, ['fulfilled', 'cancelled'], true) && $status !== $existing->status) {
                $errors['status'] = 'This back-order is '.$existing->status.'.';
            }

            return $errors;
        }

        [, $problems] = $this->lines((string) ($data['items'] ?? ''));
        if ($problems) {
            $errors['data.items'] = implode(' ', $problems);
        }
        if ($existing?->status === 'cancelled' && $status !== 'cancelled') {
            $errors['status'] = 'This order was cancelled.';
        } elseif ($status === 'cancelled' && in_array($existing?->status, ['shipped', 'delivered'], true)) {
            $errors['status'] = 'A '.$existing->status.' order cannot be cancelled.';
        } elseif ($existing && in_array($status, self::STEPS, true) && in_array($existing->status, self::STEPS, true) && array_search($status, self::STEPS, true) < array_search($existing->status, self::STEPS, true)) {
            $errors['status'] = 'An order cannot go back to '.str_replace('_', ' ', $status).'.';
        }
        if ($existing && in_array($status, ['shipped', 'delivered'], true) && ! in_array($existing->status, ['shipped', 'delivered'], true) && ($waiting = $this->waiting($existing)->count())) {
            $errors['status'] = $waiting.' '.str('back-order')->plural($waiting).' still waiting; mark it part shipped.';
        }
        if (filled($payload['due_on'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The promised date cannot be before the order date.';
        }

        return $errors;
    }

    /**
     * Read item lines like "2 x Widget @ 10" (the price is optional).
     *
     * @return array{0: list<array{item: string, quantity: float, price: float|null}>, 1: list<string>}
     */
    public function lines(string $items): array
    {
        $lines = [];
        $problems = [];
        foreach (preg_split('/\R/', $items) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*[x×*]?\s+(.+?)(?:\s*@\s*(\d+(?:\.\d+)?))?\s*$/iu', $line, $match)) {
                $problems[] = 'Start "'.trim($line).'" with a quantity, like "1 x '.trim($line).'".';

                continue;
            }
            $lines[] = ['item' => trim($match[2]), 'quantity' => (float) $match[1], 'price' => isset($match[3]) && $match[3] !== '' ? (float) $match[3] : null];
        }

        return [$lines, $problems];
    }

    /**
     * The order's back-orders that are still open.
     */
    protected function waiting(Record $order)
    {
        return $this->linked('backorders', 'order', $order)->whereIn('status', ['waiting', 'ready']);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity !== 'orders') {
            return;
        }
        [$lines] = $this->lines((string) $record->value('items'));
        $priced = collect($lines)->filter(fn (array $line) => $line['price'] !== null);
        if ($priced->isNotEmpty()) {
            $record->amount = round($priced->sum(fn (array $line) => $line['quantity'] * $line['price']), 2);
        }
        $this->put($record, ['_lines' => $lines]);
        if ($record->isDirty('status') && in_array($record->status, ['shipped', 'delivered'], true) && ! $record->value('_shipped_on')) {
            $this->put($record, ['_shipped_on' => today()->toDateString()]);
        }
        if ($record->isDirty('status') && $record->status === 'delivered') {
            $this->put($record, ['_delivered_on' => today()->toDateString()]);
        }
    }

    public function saved(Record $record): void
    {
        if (static::$syncing) {
            return;
        }
        static::$syncing = true;
        try {
            if ($record->entity === 'orders' && $record->wasChanged('status') && $record->status === 'cancelled') {
                $this->waiting($record)->get()->each(fn (Record $backorder) => $backorder->update(['status' => 'cancelled']));
            }
            if ($record->entity === 'backorders' && ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
                $order = $this->parent($record, 'order');
                if ($order && $record->status === 'fulfilled' && $order->status === 'part_shipped' && $this->waiting($order)->doesntExist()) {
                    $order->update(['status' => 'shipped']);
                }
            }
        } finally {
            static::$syncing = false;
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'backorders') {
            return match ($record->status) {
                'waiting' => ['ready' => ['label' => 'Stock arrived', 'icon' => 'package-check']],
                'ready' => ['fulfil' => ['label' => 'Fulfil', 'icon' => 'check']],
                default => [],
            };
        }
        $actions = [];
        $next = ['new' => 'confirmed', 'confirmed' => 'picking', 'picking' => 'shipped', 'part_shipped' => 'shipped', 'shipped' => 'delivered'][$record->status] ?? null;
        if ($next) {
            $actions['advance'] = ['label' => 'Mark '.str_replace('_', ' ', $next), 'icon' => 'arrow-right'];
        }
        if (in_array($record->status, ['new', 'confirmed', 'picking'], true)) {
            $actions['backorder'] = ['label' => 'Back-order an item', 'icon' => 'hourglass', 'fields' => [
                ['name' => 'item', 'label' => 'Item', 'type' => 'text'],
                ['name' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
                ['name' => 'expected_on', 'label' => 'Expected on', 'type' => 'date'],
            ]];
        }
        if (in_array($record->status, ['new', 'confirmed', 'picking', 'part_shipped'], true)) {
            $actions['cancel'] = ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]];
        }

        return $actions;
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'ready':
                $record->update(['status' => 'ready']);

                return $record->title.' is ready to send.';
            case 'fulfil':
                $record->update(['status' => 'fulfilled', 'data' => [...$record->data, '_fulfilled_on' => today()->toDateString()]]);
                $order = $this->parent($record, 'order');

                return $record->title.' fulfilled'.($order?->fresh()?->status === 'shipped' ? '; '.$order->title.' is now fully shipped.' : '.');
            case 'advance':
                $next = ['new' => 'confirmed', 'confirmed' => 'picking', 'picking' => 'shipped', 'part_shipped' => 'shipped', 'shipped' => 'delivered'][$record->status] ?? null;
                if (! $next) {
                    throw ValidationException::withMessages(['status' => 'This order cannot move on.']);
                }
                if ($next === 'shipped' && $this->waiting($record)->exists()) {
                    $next = 'part_shipped';
                    if ($record->status === 'part_shipped') {
                        throw ValidationException::withMessages(['status' => 'Back-orders are still waiting.']);
                    }
                }
                $record->update(['status' => $next]);

                return $record->title.' is '.str_replace('_', ' ', $next).'.';
            case 'backorder':
                $values = $request->validate(['item' => ['required', 'string', 'max:255'], 'quantity' => ['required', 'numeric', 'gt:0'], 'expected_on' => ['nullable', 'date']]);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'backorders',
                    'title' => trim($values['item']), 'status' => 'waiting', 'due_on' => $values['expected_on'] ?? null,
                    'data' => ['order' => $record->id, 'quantity' => (float) $values['quantity']],
                ]);
                $record->update(['data' => [...$record->data, 'fulfilment' => 'back_order']]);

                return trim($values['item']).' back-ordered for '.$record->title.'.';
            default:
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

                return $record->title.' cancelled.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'orders') {
            return [];
        }

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Items', 'icon' => 'list', 'empty' => 'No items.',
                'rows' => collect($record->value('_lines') ?? [])->map(fn (array $line) => ['label' => $line['item'], 'sub' => $line['price'] !== null ? $this->money($line['price']).' each' : null, 'value' => $line['quantity'].($line['price'] !== null ? ' · '.$this->money($line['quantity'] * $line['price']) : '')])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Back-orders', 'icon' => 'hourglass', 'empty' => 'Nothing back-ordered.',
                'rows' => $this->linked('backorders', 'order', $record)->orderBy('id')->get()->map(fn (Record $backorder) => ['label' => $backorder->title, 'sub' => $backorder->value('quantity').' · '.$backorder->status, 'value' => $backorder->due_on?->format('d M') ?? '—', 'href' => $backorder->url(), 'tone' => $backorder->status === 'fulfilled' ? 'success' : ($backorder->due_on?->lt(today()) && $backorder->status === 'waiting' ? 'danger' : null)])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $late = $this->records('orders')->whereIn('status', ['new', 'confirmed', 'picking', 'part_shipped'])->whereNotNull('due_on')->where('due_on', '<', today()->startOfDay())->orderBy('due_on')->get();
        $orders = $this->records('orders')->pluck('title', 'id');
        $waiting = $this->records('backorders')->where('status', 'waiting')->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Late orders', 'icon' => 'alarm-clock', 'empty' => 'No orders past their promised date.',
                'rows' => $late->map(fn (Record $order) => ['label' => $order->title, 'sub' => str_replace('_', ' ', $order->status), 'value' => 'Promised '.$order->due_on->format('d M'), 'href' => $order->url(), 'tone' => 'danger'])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting back-orders', 'icon' => 'hourglass', 'empty' => 'No back-orders waiting.',
                'rows' => $waiting->map(fn (Record $backorder) => ['label' => $backorder->title, 'sub' => ($orders[(int) $backorder->value('order')] ?? '—').' · '.$backorder->value('quantity'), 'value' => $backorder->due_on?->format('d M') ?? '—', 'href' => $backorder->url(), 'tone' => $backorder->due_on?->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $orders = $this->dated('orders', $from, $to)->get();

        $channels = $orders->where('status', '!=', 'cancelled')->groupBy(fn (Record $order) => $order->value('channel') ?: 'unknown')->sortKeys()
            ->map(fn (Collection $group, string $channel) => [ucfirst(str_replace('_', ' ', $channel)), $group->count(), $this->money($group->sum('amount')), $this->money($group->avg('amount'))])->values()->all();

        $shipped = $orders->filter(fn (Record $order) => $order->value('_shipped_on') && $order->due_on);
        $onTime = $shipped->filter(fn (Record $order) => Carbon::parse($order->value('_shipped_on'))->lte($order->due_on))->count();
        $delivery = [
            ['Shipped with a promised date', $shipped->count()],
            ['On time', $onTime],
            ['On-time rate', $shipped->isNotEmpty() ? round($onTime / $shipped->count() * 100, 1).'%' : '—'],
            ['Cancelled', $orders->where('status', 'cancelled')->count()],
        ];

        $backorders = $this->records('backorders')->get()->groupBy(fn (Record $backorder) => mb_strtolower($backorder->title))->sortKeys()
            ->map(fn (Collection $group) => [$group->first()->title, $group->count(), $group->sum(fn (Record $backorder) => $this->number($backorder, 'quantity')), $group->whereIn('status', ['waiting', 'ready'])->count()])->values()->all();

        return [
            ['title' => 'Orders by channel', 'columns' => ['Channel', 'Orders', 'Value', 'Average'], 'rows' => $channels],
            ['title' => 'Delivery promise', 'columns' => ['Measure', 'Value'], 'rows' => $delivery],
            ['title' => 'Back-ordered items', 'columns' => ['Item', 'Back-orders', 'Quantity', 'Still open'], 'rows' => $backorders],
        ];
    }
}
