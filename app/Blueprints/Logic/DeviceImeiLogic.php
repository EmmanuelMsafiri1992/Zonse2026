<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Mobile phones & electronics: every IMEI or serial is stocked once, and a 15-digit IMEI must pass its
 * check digit. A sold device carries its warranty end date. A repair on a device we sold that is still
 * under warranty is free; any other repair needs a price before it is ready. Repairs are promised three
 * days after booking in unless a date is set, and move booked in → diagnosing → repairing → ready → collected.
 */
class DeviceImeiLogic extends AppLogic
{
    /**
     * The next stage for each repair stage.
     *
     * @var array<string, string>
     */
    public const NEXT = ['booked_in' => 'diagnosing', 'diagnosing' => 'repairing', 'awaiting_parts' => 'repairing', 'repairing' => 'ready', 'ready' => 'collected'];

    /**
     * Strip spaces and dashes from an IMEI or serial.
     */
    public static function clean(?string $imei): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim((string) $imei)));
    }

    /**
     * Whether a 15-digit IMEI passes its Luhn check digit.
     */
    public static function validImei(string $imei): bool
    {
        $sum = 0;
        foreach (str_split(strrev($imei)) as $position => $digit) {
            $value = (int) $digit * ($position % 2 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0;
    }

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $imei = self::clean($data['imei'] ?? null);
        if ($entity->key === 'devices') {
            if (preg_match('/^\d{15}$/', $imei) && ! self::validImei($imei)) {
                $errors['data.imei'] = $imei.' is not a valid IMEI; check the number.';
            } elseif ($imei !== '' && ($twin = $this->device($imei, $existing))) {
                $errors['data.imei'] = $imei.' is already in stock as '.$twin->title.'.';
            }

            return $errors;
        }
        if (in_array($payload['status'], ['ready', 'collected'], true) && (float) ($payload['amount'] ?? 0) <= 0 && ! $this->warrantyDevice($imei, filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today())) {
            $errors['amount'] = 'Set the repair price.';
        }

        return $errors;
    }

    /**
     * The device with this IMEI or serial, if we stock it.
     */
    protected function device(string $imei, ?Record $except = null): ?Record
    {
        return $imei === '' ? null : $this->records('devices')->when($except, fn ($query) => $query->whereKeyNot($except->id))->get()->first(fn (Record $device) => self::clean($device->value('imei')) === $imei);
    }

    /**
     * The device we sold with this IMEI, if it is still under warranty on the given day.
     */
    protected function warrantyDevice(string $imei, Carbon $on): ?Record
    {
        $device = $this->device($imei);

        return $device && $device->status === 'sold' && filled($device->value('_warranty_until')) && Carbon::parse($device->value('_warranty_until'))->gte($on) ? $device : null;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'devices') {
            $this->put($record, ['imei' => self::clean($record->value('imei'))]);
            if ($record->status === 'sold') {
                $record->occurs_on ??= today();
                $months = (int) $this->number($record, 'warranty_months');
                $this->put($record, ['_warranty_until' => $months > 0 ? $record->occurs_on->copy()->addMonthsNoOverflow($months)->toDateString() : null]);
            }

            return;
        }
        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDays(3);
        $warranty = $this->warrantyDevice(self::clean($record->value('imei')), $record->occurs_on);
        $this->put($record, ['_under_warranty' => (bool) $warranty, '_device' => $warranty?->id]);
        if ($warranty) {
            $record->amount = 0;
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'devices') {
            return match ($record->status) {
                'in_stock' => ['sell' => ['label' => 'Sold', 'icon' => 'banknote']],
                'sold' => ['return' => ['label' => 'Returned', 'icon' => 'undo-2']],
                default => [],
            };
        }
        $next = self::NEXT[$record->status] ?? null;
        if (! $next) {
            return [];
        }
        $actions = ['advance' => ['label' => ucfirst($next), 'icon' => $next === 'collected' ? 'check' : 'wrench',
            'fields' => $next === 'ready' && ! $record->value('_under_warranty') ? [['name' => 'amount', 'label' => 'Repair price', 'type' => 'number', 'value' => $record->amount]] : []]];

        return in_array($record->status, ['diagnosing', 'repairing'], true) ? [...$actions, 'parts' => ['label' => 'Awaiting parts', 'icon' => 'package']] : $actions;
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'sell':
                $record->update(['status' => 'sold', 'occurs_on' => today()]);

                return $record->title.' sold'.($record->value('_warranty_until') ? '; warranty until '.Carbon::parse($record->value('_warranty_until'))->format('d M Y') : '').'.';
            case 'return':
                if (! $record->value('_warranty_until') || Carbon::parse($record->value('_warranty_until'))->lt(today())) {
                    throw ValidationException::withMessages(['status' => $record->title.' is out of warranty.']);
                }
                $record->update(['status' => 'returned']);

                return $record->title.' returned under warranty.';
            case 'parts':
                $record->update(['status' => 'awaiting_parts']);

                return $record->title.' is waiting for parts.';
            default:
                $next = self::NEXT[$record->status];
                $changes = ['status' => $next];
                if ($next === 'ready' && ! $record->value('_under_warranty')) {
                    $price = (float) ($request->validate(['amount' => ['nullable', 'numeric', 'min:0']])['amount'] ?? 0) ?: (float) $record->amount;
                    if ($price <= 0) {
                        throw ValidationException::withMessages(['amount' => 'Set the repair price.']);
                    }
                    $changes['amount'] = $price;
                }
                $record->update($changes);

                return $record->title.' is '.str_replace('_', ' ', $next).($next === 'ready' ? ($record->value('_under_warranty') ? ' (free, under warranty)' : ' ('.$this->money($record->amount).')') : '').'.';
        }
    }

    public function homeCards(): array
    {
        $open = $this->records('repairs')->whereNotIn('status', ['ready', 'collected'])->get();
        $late = $open->filter(fn (Record $repair) => $repair->due_on && $repair->due_on->lt(today()))->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Workshop', 'icon' => 'wrench', 'stats' => [
                ['label' => 'In the workshop', 'value' => $open->count()],
                ['label' => 'Ready to collect', 'value' => $this->records('repairs')->where('status', 'ready')->count()],
                ['label' => 'Devices in stock', 'value' => $this->records('devices')->where('status', 'in_stock')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Repairs past their promised date', 'icon' => 'clock-alert', 'empty' => 'Every repair is on time.',
                'rows' => $late->map(fn (Record $repair) => ['label' => $repair->title, 'sub' => ucfirst(str_replace('_', ' ', $repair->status)), 'value' => 'promised '.$repair->due_on->format('d M'), 'href' => $repair->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [
            ['title' => 'Devices sold by condition', 'columns' => ['Condition', 'Sold', 'Sales'], 'rows' => $this->records('devices')->where('status', 'sold')->whereDate('occurs_on', '>=', $from->toDateString())->whereDate('occurs_on', '<=', $to->toDateString())->get()
                ->groupBy(fn (Record $device) => ucfirst((string) ($device->value('condition') ?: 'unknown')))->sortKeys()
                ->map(fn ($group, string $condition) => [$condition, $group->count(), $this->money($group->sum(fn (Record $device) => $this->number($device, 'price')))])->values()->all()],
            ['title' => 'Repairs', 'columns' => ['Status', 'Repairs', 'Under warranty', 'Repair income'], 'rows' => $this->dated('repairs', $from, $to)->get()
                ->groupBy(fn (Record $repair) => ucfirst(str_replace('_', ' ', $repair->status)))
                ->map(fn ($group, string $status) => [$status, $group->count(), $group->filter(fn (Record $repair) => $repair->value('_under_warranty'))->count(), $this->money($group->sum('amount'))])->values()->all()],
        ];
    }
}
