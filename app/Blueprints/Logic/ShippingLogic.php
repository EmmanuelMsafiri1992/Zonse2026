<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Shipping & courier labels: once a parcel leaves it needs a waybill (unless it goes in our own vehicle),
 * and a waybill is used once per courier. Delivery is expected three days after shipping unless a date is
 * given. Shipments move ready → collected → in transit → out for delivery → delivered, the delivery date
 * is kept and a late delivery is flagged, and the report shows each courier's on-time rate.
 */
class ShippingLogic extends AppLogic
{
    /**
     * The next stop for each status.
     *
     * @var array<string, string>
     */
    public const NEXT = ['ready' => 'collected', 'collected' => 'in_transit', 'in_transit' => 'out_for_delivery', 'out_for_delivery' => 'delivered'];

    /**
     * Statuses after the parcel has left.
     *
     * @var list<string>
     */
    public const GONE = ['collected', 'in_transit', 'out_for_delivery', 'delivered', 'returned', 'lost'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $courier = $data['courier'] ?? null;
        $waybill = mb_strtolower(trim((string) ($data['waybill'] ?? '')));
        if ($courier !== 'own' && $waybill === '' && in_array($payload['status'], self::GONE, true)) {
            $errors['data.waybill'] = 'Give the waybill number once the parcel has left.';
        }
        if ($waybill !== '' && ($twin = $this->records('shipments')->where('data->courier', $courier)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->first(fn (Record $shipment) => mb_strtolower(trim((string) $shipment->value('waybill'))) === $waybill))) {
            $errors['data.waybill'] = 'Waybill '.$data['waybill'].' is already on '.$twin->title.'\'s shipment.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'Delivery cannot be expected before the parcel ships.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if (in_array($record->status, self::GONE, true)) {
            $record->occurs_on ??= today();
        }
        if ($record->occurs_on) {
            $record->due_on ??= $record->occurs_on->copy()->addDays(3);
        }
        if ($record->status === 'delivered') {
            $delivered = $record->value('_delivered_on') ?? today()->toDateString();
            $this->put($record, ['_delivered_on' => $delivered, '_late' => $record->due_on && Carbon::parse($delivered)->gt($record->due_on)]);
        } else {
            $this->put($record, ['_delivered_on' => null, '_late' => null]);
        }
    }

    /**
     * Whether a shipment still on the road is past its expected delivery date.
     */
    public function isOverdue(Record $shipment): bool
    {
        return in_array($shipment->status, ['ready', 'collected', 'in_transit', 'out_for_delivery'], true) && $shipment->due_on && $shipment->due_on->lt(today());
    }

    public function actions(Record $record): array
    {
        $next = self::NEXT[$record->status] ?? null;
        if (! $next) {
            return [];
        }
        $actions = ['advance' => ['label' => ['collected' => 'Collected by courier', 'in_transit' => 'In transit', 'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered'][$next], 'icon' => $next === 'delivered' ? 'check' : 'truck',
            'fields' => $next === 'collected' && $record->value('courier') !== 'own' ? [['name' => 'waybill', 'label' => 'Waybill', 'type' => 'text', 'value' => $record->value('waybill')]] : []]];

        return $record->status === 'ready' ? $actions : [...$actions, 'returned' => ['label' => 'Returned', 'icon' => 'undo-2'], 'lost' => ['label' => 'Lost', 'icon' => 'circle-alert']];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action !== 'advance') {
            $record->update(['status' => $action]);

            return $record->title.'\'s parcel marked '.$action.'.';
        }
        $next = self::NEXT[$record->status];
        $data = $record->data;
        if ($next === 'collected' && $record->value('courier') !== 'own') {
            $waybill = trim((string) ($request->validate(['waybill' => ['nullable', 'string', 'max:100']])['waybill'] ?? '')) ?: $record->value('waybill');
            if (blank($waybill)) {
                throw ValidationException::withMessages(['waybill' => 'Give the waybill number.']);
            }
            $errors = $this->validate($this->app()->entity('shipments'), ['status' => $next, 'occurs_on' => null, 'due_on' => null, 'data' => [...$data, 'waybill' => $waybill]], $record);
            if ($errors) {
                throw ValidationException::withMessages(['waybill' => $errors['data.waybill']]);
            }
            $data['waybill'] = $waybill;
        }
        $record->update(['status' => $next, 'data' => $data]);

        return $record->title.'\'s parcel is '.str_replace('_', ' ', $next).($record->value('_late') ? ', '.(int) $record->due_on->diffInDays(today()).' days late' : '').'.';
    }

    public function homeCards(): array
    {
        $late = $this->records('shipments')->whereIn('status', ['ready', 'collected', 'in_transit', 'out_for_delivery'])->whereDate('due_on', '<', today()->toDateString())->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Overdue deliveries', 'icon' => 'clock-alert', 'empty' => 'Every parcel on the road is on time.',
            'rows' => $late->map(fn (Record $shipment) => ['label' => $shipment->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $shipment->value('courier'))).($shipment->value('waybill') ? ' · '.$shipment->value('waybill') : ''), 'value' => 'due '.$shipment->due_on->format('d M'), 'href' => $shipment->url(), 'tone' => 'danger'])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Courier performance', 'columns' => ['Courier', 'Shipments', 'Delivered', 'On time', 'Returned or lost', 'Shipping cost'], 'rows' => $this->dated('shipments', $from, $to)->get()
            ->groupBy(fn (Record $shipment) => ucfirst(str_replace('_', ' ', (string) $shipment->value('courier'))))->sortKeys()
            ->map(function ($group, string $courier) {
                $delivered = $group->where('status', 'delivered');
                $onTime = $delivered->filter(fn (Record $shipment) => ! $shipment->value('_late'))->count();

                return [$courier, $group->count(), $delivered->count(), $delivered->isNotEmpty() ? round($onTime / $delivered->count() * 100).'%' : '—', $group->whereIn('status', ['returned', 'lost'])->count(), $this->money($group->sum('amount'))];
            })->values()->all()]];
    }
}
