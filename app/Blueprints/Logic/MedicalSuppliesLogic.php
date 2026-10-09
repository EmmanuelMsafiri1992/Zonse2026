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
 * Medical supplies distribution: facility orders move from received to approved, picked and
 * delivered. The delivery deadline follows from the urgency (two weeks routine, three days urgent,
 * next day for emergencies) unless one is set. Cold-chain orders can only be picked with the
 * cool box between 2 and 8 °C, deliveries need a delivery note, and the lead time and whether
 * the order arrived late are kept for the on-time report. Delivered and cancelled orders are closed.
 */
class MedicalSuppliesLogic extends AppLogic
{
    /**
     * Days allowed for delivery by urgency.
     *
     * @var array<string, int>
     */
    protected const DELIVERY_DAYS = ['routine' => 14, 'urgent' => 3, 'emergency' => 1];

    /**
     * The safe temperature range for vaccines and other cold-chain items, in °C.
     */
    protected const COLD_CHAIN = [2, 8];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($existing && in_array($existing->status, ['delivered', 'cancelled'], true) && $payload['status'] !== $existing->status) {
            $errors['status'] = 'This order is '.$existing->status.' and closed.';
        }
        if ($payload['status'] === 'delivered' && blank($data['delivery_note'] ?? null)) {
            $errors['data.delivery_note'] = 'Enter the signed delivery note number.';
        }
        if (in_array($payload['status'], ['picked', 'delivered'], true) && ! empty($data['cold_chain']) && blank($existing?->value('_temperature'))) {
            $errors['status'] = 'Record the cool-box temperature by picking the order first.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The delivery deadline cannot be before the order date.';
        }
        if (filled($payload['amount'] ?? null) && $payload['amount'] < 0) {
            $errors['amount'] = 'The order value cannot be negative.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $urgency = $record->value('urgency') ?: 'routine';
        $this->put($record, ['urgency' => $urgency]);
        $record->occurs_on ??= today();
        if (! $record->due_on) {
            $record->due_on = $record->occurs_on->copy()->addDays(self::DELIVERY_DAYS[$urgency] ?? 14);
        }
        if ($record->status === 'delivered' && blank($record->value('_delivered_on'))) {
            $this->put($record, ['_delivered_on' => today()->toDateString()]);
        }
        $delivered = $record->value('_delivered_on') ? Carbon::parse($record->value('_delivered_on')) : null;
        $this->put($record, [
            '_lead_days' => $delivered ? (int) $record->occurs_on->diffInDays($delivered) : null,
            '_late' => $delivered ? $delivered->gt($record->due_on) : ($record->status !== 'cancelled' && $record->due_on->lt(today())),
        ]);
    }

    public function actions(Record $record): array
    {
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]];

        return match ($record->status) {
            'received' => ['approve' => ['label' => 'Approve', 'icon' => 'check'], ...$cancel],
            'approved' => ['pick' => ['label' => 'Picked & packed', 'icon' => 'package', 'fields' => $record->value('cold_chain') ? [['name' => 'temperature', 'label' => 'Cool-box temperature (°C)', 'type' => 'number']] : []], ...$cancel],
            'picked' => ['deliver' => ['label' => 'Delivered', 'icon' => 'truck', 'fields' => [['name' => 'delivery_note', 'label' => 'Delivery note', 'type' => 'text']]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'approve') {
            $record->update(['status' => 'approved', 'assignee_id' => $record->assignee_id ?? $request->user()?->id]);

            return 'Order for '.$record->title.' approved; deliver by '.$record->due_on->format('d M Y').'.';
        }
        if ($action === 'cancel') {
            $reason = $request->validate(['reason' => ['required', 'string', 'max:190']])['reason'];
            $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

            return 'Order for '.$record->title.' cancelled.';
        }
        if ($action === 'pick') {
            $temperature = null;
            if ($record->value('cold_chain')) {
                $temperature = (float) $request->validate(['temperature' => ['required', 'numeric']])['temperature'];
                if ($temperature < self::COLD_CHAIN[0] || $temperature > self::COLD_CHAIN[1]) {
                    throw ValidationException::withMessages(['temperature' => 'The cool box is at '.$temperature.' °C; cold-chain items must travel between '.self::COLD_CHAIN[0].' and '.self::COLD_CHAIN[1].' °C.']);
                }
            }
            $record->update(['status' => 'picked', 'data' => [...$record->data, '_temperature' => $temperature, '_picked_on' => today()->toDateString()]]);

            return 'Order for '.$record->title.' picked'.($temperature !== null ? '; cold chain at '.$temperature.' °C.' : '.');
        }

        $note = $request->validate(['delivery_note' => ['required', 'string', 'max:60']])['delivery_note'];
        $record->update(['status' => 'delivered', 'data' => [...$record->data, 'delivery_note' => $note]]);
        $record = $record->fresh();
        $late = $record->value('_late') ? ' — '.(int) $record->due_on->diffInDays(today()).' day(s) late.' : ' on time.';

        return 'Delivered to '.$record->title.' in '.$record->value('_lead_days').' day(s)'.$late;
    }

    public function recordCards(Record $record): array
    {
        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Fulfilment', 'icon' => 'truck', 'stats' => [
                ['label' => 'Urgency', 'value' => ucfirst((string) $record->value('urgency')), 'tone' => $record->value('urgency') === 'emergency' ? 'danger' : ($record->value('urgency') === 'urgent' ? 'warning' : null)],
                ['label' => 'Deliver by', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->value('_late') ? 'danger' : null],
                ['label' => 'Cold chain', 'value' => $record->value('cold_chain') ? ($record->value('_temperature') !== null ? $record->value('_temperature').' °C at picking' : 'Required') : 'No'],
                ['label' => 'Lead time', 'value' => $record->value('_lead_days') === null ? '—' : $record->value('_lead_days').' day(s)'],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $open = $this->records('orders')->whereIn('status', ['received', 'approved', 'picked'])->orderBy('due_on')->get();
        $late = $open->filter(fn (Record $order) => $order->due_on?->lt(today()));
        $rank = ['emergency' => 0, 'urgent' => 1, 'routine' => 2];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Orders', 'icon' => 'briefcase-medical', 'stats' => [
                ['label' => 'To approve', 'value' => (string) $open->where('status', 'received')->count()],
                ['label' => 'To pick', 'value' => (string) $open->where('status', 'approved')->count()],
                ['label' => 'Out for delivery', 'value' => (string) $open->where('status', 'picked')->count()],
                ['label' => 'Late', 'value' => (string) $late->count(), 'tone' => $late->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Dispatch queue', 'icon' => 'list-ordered', 'empty' => 'No open orders.',
                'rows' => $open->sortBy(fn (Record $order) => [$rank[$order->value('urgency')] ?? 2, $order->due_on?->timestamp])->map(fn (Record $order) => [
                    'label' => $order->title, 'sub' => ucfirst((string) $order->value('urgency')).($order->value('cold_chain') ? ' · cold chain' : '').' · '.str_replace('_', ' ', $order->status),
                    'value' => $order->due_on?->format('d M'), 'href' => $order->url(), 'tone' => $order->due_on?->lt(today()) ? 'danger' : ($order->value('urgency') === 'emergency' ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $orders = $this->dated('orders', $from, $to)->get();
        $onTime = function (Collection $group): string {
            $delivered = $group->where('status', 'delivered');

            return $delivered->isEmpty() ? '—' : (int) round($delivered->reject(fn (Record $order) => $order->value('_late'))->count() / $delivered->count() * 100).'%';
        };
        $lead = function (Collection $group): string {
            $delivered = $group->where('status', 'delivered');

            return $delivered->isEmpty() ? '—' : round($delivered->avg(fn (Record $order) => (int) $order->value('_lead_days')), 1).' days';
        };

        $byFacility = $orders->groupBy('title')->sortKeys()
            ->map(fn (Collection $group, string $facility) => [$facility, $group->count(), $group->where('status', 'delivered')->count(), $this->money($group->where('status', '!=', 'cancelled')->sum('amount')), $onTime($group)])->values()->all();

        $byUrgency = collect(array_keys(self::DELIVERY_DAYS))->map(function (string $urgency) use ($orders, $onTime, $lead) {
            $group = $orders->filter(fn (Record $order) => $order->value('urgency') === $urgency);

            return [ucfirst($urgency), $group->count(), $lead($group), $onTime($group)];
        })->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($orders, $onTime) {
            $group = $orders->filter(fn (Record $order) => $order->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'delivered')->count(), $group->where('status', 'cancelled')->count(), $onTime($group)];
        })->values()->all();

        return [
            ['title' => 'Orders by facility', 'columns' => ['Facility', 'Orders', 'Delivered', 'Value', 'On time'], 'rows' => $byFacility],
            ['title' => 'Lead time by urgency', 'columns' => ['Urgency', 'Orders', 'Average lead time', 'On time'], 'rows' => $byUrgency],
            ['title' => 'Orders by month', 'columns' => ['Month', 'Orders', 'Delivered', 'Cancelled', 'On time'], 'rows' => $byMonth],
        ];
    }
}
