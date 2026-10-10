<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Food ordering & delivery: an order moves from received to preparing, ready, out with a rider and
 * delivered, and the time of each step is kept so the kitchen and rider times can be measured. An
 * order can't leave without a rider, and a rider carries one order at a time. The amount to collect
 * at the door is the order plus the delivery fee unless it was paid online; the rider must hand in
 * exactly that on delivery, and what each rider has collected shows until it is banked.
 */
class FoodDeliveryLogic extends AppLogic
{
    /**
     * The order of the steps, so an order can only move forward.
     *
     * @var list<string>
     */
    protected const STEPS = ['received', 'preparing', 'ready', 'out_for_delivery', 'delivered'];

    /**
     * Minutes from order to door that count as on time.
     */
    protected const PROMISE_MINUTES = 45;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($existing && in_array($existing->status, ['delivered', 'cancelled'], true) && $payload['status'] !== $existing->status) {
            $errors['status'] = 'This order is '.$existing->status.' and closed.';
        }
        if ($existing && $payload['status'] !== 'cancelled' && array_search($payload['status'], self::STEPS, true) < array_search($existing->status, self::STEPS, true)) {
            $errors['status'] = 'An order cannot go back a step.';
        }
        if (in_array($payload['status'], ['out_for_delivery', 'delivered'], true) && blank($data['rider'] ?? null)) {
            $errors['data.rider'] = 'Choose the rider taking the order.';
        }
        if ($payload['status'] === 'out_for_delivery' && filled($data['rider'] ?? null) && ($busy = $this->carrying((int) $data['rider'], $existing?->id))) {
            $errors['data.rider'] = 'The rider is still out with '.$busy->number.'.';
        }
        if (filled($data['delivery_fee'] ?? null) && (float) $data['delivery_fee'] < 0) {
            $errors['data.delivery_fee'] = 'The delivery fee cannot be negative.';
        }

        return $errors;
    }

    /**
     * The order a rider is out with, if any.
     */
    protected function carrying(int $rider, ?int $except = null): ?Record
    {
        return $this->records('orders')->where('status', 'out_for_delivery')->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->first(fn (Record $order) => (int) $order->value('rider') === $rider);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $times = (array) ($record->value('_times') ?? []);
        $times[$record->status] ??= now()->toDateTimeString();
        $received = Carbon::parse($times['received'] ?? $record->created_at ?? now());
        $total = round((float) $record->amount + $this->number($record, 'delivery_fee'), 2);

        $this->put($record, [
            'payment' => $record->value('payment') ?: 'cash_on_delivery',
            '_times' => $times,
            '_total' => $total,
            '_to_collect' => $record->value('payment') === 'paid_online' ? 0 : $total,
            '_kitchen_minutes' => isset($times['ready']) ? (int) $received->diffInMinutes(Carbon::parse($times['ready'])) : null,
            '_ride_minutes' => isset($times['out_for_delivery'], $times['delivered']) ? (int) Carbon::parse($times['out_for_delivery'])->diffInMinutes(Carbon::parse($times['delivered'])) : null,
            '_total_minutes' => isset($times['delivered']) ? (int) $received->diffInMinutes(Carbon::parse($times['delivered'])) : null,
        ]);
    }

    public function actions(Record $record): array
    {
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]];

        return match ($record->status) {
            'received' => ['prepare' => ['label' => 'Start preparing', 'icon' => 'chef-hat'], ...$cancel],
            'preparing' => ['ready' => ['label' => 'Ready', 'icon' => 'package-check'], ...$cancel],
            'ready' => ['dispatch' => ['label' => 'Send out', 'icon' => 'bike', 'fields' => [['name' => 'rider', 'label' => 'Rider', 'type' => 'select', 'options' => $this->riders(), 'value' => $record->value('rider')]]], ...$cancel],
            'out_for_delivery' => ['deliver' => ['label' => 'Delivered', 'icon' => 'check', 'fields' => (float) $record->value('_to_collect') > 0 ? [['name' => 'collected', 'label' => 'Amount collected', 'type' => 'number', 'value' => $record->value('_to_collect')]] : []]],
            'delivered' => (float) $record->value('_to_collect') > 0 && ! $record->value('_banked') ? ['bank' => ['label' => 'Cash handed in', 'icon' => 'piggy-bank']] : [],
            default => [],
        };
    }

    /**
     * Workspace members who can ride.
     *
     * @return array<int, string>
     */
    protected function riders(): array
    {
        return app(WorkspaceContext::class)->get()?->members()->orderBy('users.name')->pluck('users.name', 'users.id')->all() ?? [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'prepare':
                $record->update(['status' => 'preparing']);

                return $record->number.' is being prepared.';
            case 'ready':
                $record->update(['status' => 'ready']);

                return $record->number.' is ready after '.$record->fresh()->value('_kitchen_minutes').' min.';
            case 'dispatch':
                $rider = (int) $request->validate(['rider' => ['required', 'integer']])['rider'];
                if (! array_key_exists($rider, $this->riders())) {
                    throw ValidationException::withMessages(['rider' => 'Choose a rider from the team.']);
                }
                if ($busy = $this->carrying($rider, $record->id)) {
                    throw ValidationException::withMessages(['rider' => 'The rider is still out with '.$busy->number.'.']);
                }
                $record->update(['status' => 'out_for_delivery', 'data' => [...$record->data, 'rider' => $rider]]);

                return $record->number.' is out with '.User::query()->find($rider)?->name.((float) $record->value('_to_collect') > 0 ? '; collect '.$this->money($record->value('_to_collect')).'.' : '; already paid.');
            case 'deliver':
                $due = (float) $record->value('_to_collect');
                if ($due > 0) {
                    $collected = (float) $request->validate(['collected' => ['required', 'numeric', 'min:0']])['collected'];
                    if (abs($collected - $due) > 0.009) {
                        throw ValidationException::withMessages(['collected' => 'Collect '.$this->money($due).' for this order.']);
                    }
                }
                $record->update(['status' => 'delivered']);
                $record = $record->fresh();
                $late = $record->value('_total_minutes') > self::PROMISE_MINUTES ? ' (late)' : '';

                return $record->number.' delivered in '.$record->value('_total_minutes').' min'.$late.'.';
            case 'bank':
                $record->update(['data' => [...$record->data, '_banked' => now()->toDateTimeString()]]);

                return $this->money($record->value('_to_collect')).' for '.$record->number.' handed in.';
            default:
                $reason = $request->validate(['reason' => ['required', 'string', 'max:190']])['reason'];
                $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

                return $record->number.' cancelled.';
        }
    }

    public function recordCards(Record $record): array
    {
        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Delivery', 'icon' => 'timer', 'stats' => [
            ['label' => 'Order + delivery', 'value' => $this->money($record->value('_total'))],
            ['label' => 'To collect', 'value' => $this->money($record->value('_to_collect'))],
            ['label' => 'Kitchen', 'value' => $record->value('_kitchen_minutes') === null ? '—' : $record->value('_kitchen_minutes').' min'],
            ['label' => 'Door to door', 'value' => $record->value('_total_minutes') === null ? '—' : $record->value('_total_minutes').' min', 'tone' => $record->value('_total_minutes') > self::PROMISE_MINUTES ? 'danger' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $live = $this->records('orders')->whereIn('status', ['received', 'preparing', 'ready', 'out_for_delivery'])->orderBy('id')->get();
        $unbanked = $this->records('orders')->where('status', 'delivered')->get()->filter(fn (Record $order) => (float) $order->value('_to_collect') > 0 && ! $order->value('_banked'));
        $names = User::query()->whereIn('id', $unbanked->map(fn (Record $order) => $order->value('rider'))->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Live orders', 'icon' => 'bike', 'empty' => 'No orders in progress.',
                'rows' => $live->map(function (Record $order) {
                    $age = (int) Carbon::parse($order->value('_times')['received'] ?? $order->created_at)->diffInMinutes(now());

                    return ['label' => $order->number.' · '.$order->title, 'sub' => str_replace('_', ' ', $order->status).' · '.$this->money($order->value('_total')), 'value' => $age.' min', 'href' => $order->url(), 'tone' => $age > self::PROMISE_MINUTES ? 'danger' : null];
                })->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Cash with riders', 'icon' => 'wallet', 'empty' => 'All cash handed in.',
                'rows' => $unbanked->groupBy(fn (Record $order) => (int) $order->value('rider'))
                    ->map(fn (Collection $orders, int $rider) => ['label' => $names[$rider] ?? 'Unknown rider', 'sub' => $orders->count().' order(s)', 'value' => $this->money($orders->sum(fn (Record $order) => (float) $order->value('_to_collect'))), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $orders = $this->dated('orders', $from, $to)->get();
        $delivered = $orders->where('status', 'delivered');
        $onTime = fn (Collection $group) => $group->isEmpty() ? '—' : (int) round($group->filter(fn (Record $order) => $order->value('_total_minutes') <= self::PROMISE_MINUTES)->count() / $group->count() * 100).'%';

        $byChannel = $orders->groupBy(fn (Record $order) => $order->value('channel') ?: 'phone')->sortKeys()
            ->map(fn (Collection $group, string $channel) => [ucwords(str_replace('_', ' ', $channel)), $group->count(), $group->where('status', 'cancelled')->count(), $this->money($group->where('status', 'delivered')->sum('amount'))])->values()->all();

        $names = User::query()->whereIn('id', $delivered->map(fn (Record $order) => $order->value('rider'))->filter()->unique())->pluck('name', 'id');
        $byRider = $delivered->groupBy(fn (Record $order) => (int) $order->value('rider'))
            ->map(fn (Collection $group, int $rider) => [$names[$rider] ?? '—', $group->count(), round($group->avg(fn (Record $order) => (int) $order->value('_ride_minutes')), 1).' min', $onTime($group), $this->money($group->sum(fn (Record $order) => $this->number($order, 'delivery_fee'))), $this->money($group->sum(fn (Record $order) => (float) $order->value('_to_collect')))])->values()->all();

        return [
            ['title' => 'Orders by channel', 'columns' => ['Channel', 'Orders', 'Cancelled', 'Food sales'], 'rows' => $byChannel],
            ['title' => 'Riders', 'columns' => ['Rider', 'Deliveries', 'Average ride', 'On time', 'Delivery fees', 'Cash collected'], 'rows' => $byRider],
            ['title' => 'Speed', 'columns' => ['Delivered', 'Average kitchen time', 'Average door to door', 'On time ('.self::PROMISE_MINUTES.' min)'], 'rows' => $delivered->isEmpty() ? [] : [[
                $delivered->count(), round($delivered->avg(fn (Record $order) => (int) $order->value('_kitchen_minutes')), 1).' min', round($delivered->avg(fn (Record $order) => (int) $order->value('_total_minutes')), 1).' min', $onTime($delivered),
            ]]],
        ];
    }
}
