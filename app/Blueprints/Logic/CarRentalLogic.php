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
 * Car rental: a car is never reserved or out twice for overlapping days and is never rented while
 * in maintenance. The rental is priced at the car's daily rate. Handing over records the mileage
 * and fuel going out; the return needs the mileage back (never less), charges any extra days and
 * a refuelling fee for each quarter tank short, updates the car's mileage and frees the car. The car's
 * status follows its rentals, and rentals still out after their return date are flagged as overdue.
 */
class CarRentalLogic extends AppLogic
{
    /**
     * Fuel levels in quarters of a tank.
     *
     * @var array<string, int>
     */
    protected const FUEL = ['full' => 4, '3_4' => 3, '1_2' => 2, '1_4' => 1, 'empty' => 0];

    /**
     * Charge for each quarter tank the car comes back short.
     */
    protected const REFUEL_PER_QUARTER = 25;

    /**
     * Rentals that hold their car.
     *
     * @var list<string>
     */
    protected const HOLDING = ['reserved', 'out'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'cars') {
            if ($existing && in_array($payload['status'], ['available', 'maintenance'], true) && $this->current($existing)) {
                $errors['status'] = 'The car is out with '.$this->current($existing)->title.'.';
            }

            return $errors;
        }

        if ($existing && in_array($existing->status, ['returned', 'cancelled'], true) && $payload['status'] !== $existing->status) {
            $errors['status'] = 'This rental is '.$existing->status.' and closed.';
        }
        $pickUp = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        $return = filled($payload['due_on'] ?? null) ? Carbon::parse($payload['due_on']) : $pickUp->copy()->addDay();
        if ($return->lte($pickUp)) {
            $errors['due_on'] = 'The return must be after the pick-up.';

            return $errors;
        }
        $car = filled($data['car'] ?? null) ? $this->records('cars')->find($data['car']) : null;
        if ($car && in_array($payload['status'], self::HOLDING, true)) {
            if ($car->status === 'maintenance') {
                $errors['data.car'] = $car->title.' is in maintenance.';
            } elseif ($clash = $this->clash($car, $pickUp, $return, $existing?->id)) {
                $errors['data.car'] = $car->title.' is booked by '.$clash->title.' from '.$clash->occurs_on->format('d M').' to '.$clash->due_on->format('d M').'.';
            }
        }
        if (filled($data['mileage_in'] ?? null) && (float) $data['mileage_in'] < (float) ($data['mileage_out'] ?? 0)) {
            $errors['data.mileage_in'] = 'The mileage in cannot be less than the mileage out.';
        }

        return $errors;
    }

    /**
     * Another rental holding the car on any of these days.
     */
    protected function clash(Record $car, Carbon $pickUp, Carbon $return, ?int $except = null): ?Record
    {
        return $this->linked('rentals', 'car', $car)->whereIn('status', self::HOLDING)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->where('occurs_on', '<', $return->copy()->startOfDay())->where('due_on', '>', $pickUp->copy()->startOfDay())
            ->first();
    }

    /**
     * The rental the car is out on, if any.
     */
    protected function current(Record $car): ?Record
    {
        return $this->linked('rentals', 'car', $car)->where('status', 'out')->first();
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'rentals') {
            return;
        }

        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDay();
        $days = max(1, (int) $record->occurs_on->diffInDays($record->due_on));
        $rate = (float) ($this->parent($record, 'car')?->value('daily_rate') ?? 0);
        if ($record->amount === null && $rate > 0) {
            $record->amount = $days * $rate;
        }
        $in = $record->value('mileage_in');
        $this->put($record, [
            '_days' => $days,
            '_rate' => $rate,
            '_km' => $in !== null && $record->value('mileage_out') !== null ? (int) ($in - $record->value('mileage_out')) : null,
            '_overdue' => $record->status === 'out' && $record->due_on->lt(today()),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'rentals') {
            return;
        }
        $this->follow($this->parent($record, 'car'));
        $this->follow($this->previousParent($record, 'car'));
    }

    /**
     * Set a car's status from its rentals: rented while one is out, reserved while one is booked.
     */
    protected function follow(?Record $car): void
    {
        if (! $car || $car->status === 'maintenance') {
            return;
        }
        $status = $this->current($car) ? 'rented'
            : ($this->linked('rentals', 'car', $car)->where('status', 'reserved')->exists() ? 'reserved' : 'available');
        if ($status !== $car->status) {
            $car->update(['status' => $status]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'cars') {
            return match ($record->status) {
                'available', 'reserved' => ['service' => ['label' => 'Send for service', 'icon' => 'wrench']],
                'maintenance' => ['serviced' => ['label' => 'Back from service', 'icon' => 'check', 'fields' => [['name' => 'mileage', 'label' => 'Mileage (km)', 'type' => 'number', 'value' => $record->value('mileage')]]]],
                default => [],
            };
        }

        $fuel = ['full' => 'Full', '3_4' => '¾', '1_2' => '½', '1_4' => '¼', 'empty' => 'Empty'];

        return match ($record->status) {
            'reserved' => [
                'hand_over' => ['label' => 'Hand over', 'icon' => 'key-round', 'fields' => [
                    ['name' => 'mileage_out', 'label' => 'Mileage out', 'type' => 'number', 'value' => $this->parent($record, 'car')?->value('mileage')],
                    ['name' => 'fuel_out', 'label' => 'Fuel out', 'type' => 'select', 'options' => $fuel, 'value' => 'full'],
                ]],
                'cancel' => ['label' => 'Cancel', 'icon' => 'x'],
            ],
            'out' => ['return' => ['label' => 'Return', 'icon' => 'undo-2', 'fields' => [
                ['name' => 'mileage_in', 'label' => 'Mileage in', 'type' => 'number'],
                ['name' => 'fuel_in', 'label' => 'Fuel in', 'type' => 'select', 'options' => $fuel],
                ['name' => 'damage_notes', 'label' => 'Damage', 'type' => 'textarea'],
            ]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'cars') {
            if ($action === 'service') {
                $record->update(['status' => 'maintenance']);

                return $record->title.' sent for service.';
            }
            $mileage = $request->validate(['mileage' => ['nullable', 'numeric', 'min:'.(float) $record->value('mileage')]])['mileage'] ?? $record->value('mileage');
            $record->update(['status' => 'available', 'data' => [...$record->data, 'mileage' => $mileage]]);
            $this->follow($record->fresh());

            return $record->title.' is back from service.';
        }

        switch ($action) {
            case 'hand_over':
                $car = $this->parent($record, 'car');
                $input = $request->validate(['mileage_out' => ['required', 'numeric', 'min:0'], 'fuel_out' => ['required', 'in:'.implode(',', array_keys(self::FUEL))]]);
                if ($car?->status === 'maintenance' || $car?->status === 'rented') {
                    throw ValidationException::withMessages(['mileage_out' => $car->title.' is '.$car->status.'.']);
                }
                if ((float) $input['mileage_out'] < (float) $car?->value('mileage')) {
                    throw ValidationException::withMessages(['mileage_out' => 'The car has already done '.$car->value('mileage').' km.']);
                }
                $record->update(['status' => 'out', 'occurs_on' => $record->occurs_on->gt(today()) ? today() : $record->occurs_on, 'data' => [...$record->data, ...$input]]);

                return $car?->title.' handed to '.$record->title.' at '.$input['mileage_out'].' km; due back '.$record->due_on->format('d M Y').'.';
            case 'return':
                $input = $request->validate([
                    'mileage_in' => ['required', 'numeric', 'min:'.(float) $record->value('mileage_out')],
                    'fuel_in' => ['required', 'in:'.implode(',', array_keys(self::FUEL))],
                    'damage_notes' => ['nullable', 'string'],
                ]);
                $lateDays = $record->due_on->lt(today()) ? (int) $record->due_on->diffInDays(today()) : 0;
                $short = max(0, (self::FUEL[$record->value('fuel_out') ?? 'full'] ?? 4) - self::FUEL[$input['fuel_in']]);
                $extra = $lateDays * (float) $record->value('_rate') + $short * self::REFUEL_PER_QUARTER;
                $record->update([
                    'status' => 'returned', 'due_on' => $lateDays ? today() : $record->due_on, 'amount' => (float) $record->amount + $extra,
                    'data' => [...$record->data, ...$input, '_late_days' => $lateDays, '_refuel' => $short * self::REFUEL_PER_QUARTER],
                ]);
                $car = $this->parent($record, 'car');
                $car?->update(['data' => [...$car->data, 'mileage' => $input['mileage_in']]]);
                $record = $record->fresh();

                return $car?->title.' returned after '.$record->value('_km').' km'.($lateDays ? ', '.$lateDays.' day(s) late' : '').($short ? ', '.$short.' quarter tank(s) short' : '').'; total '.$this->money($record->amount).'.';
            default:
                $record->update(['status' => 'cancelled']);

                return 'Rental for '.$record->title.' cancelled.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        $this->records('rentals')->where('status', 'out')->get()->each(function (Record $rental) use (&$changed) {
            $before = (bool) $rental->value('_overdue');
            $this->recalculate($rental);
            $changed += $rental->value('_overdue') !== $before ? 1 : 0;
        });

        return $changed;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'cars') {
            $rental = $this->current($record) ?? $this->linked('rentals', 'car', $record)->where('status', 'reserved')->orderBy('occurs_on')->first();

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Car', 'icon' => 'car', 'stats' => [
                ['label' => 'Mileage', 'value' => number_format((float) $record->value('mileage')).' km'],
                ['label' => $rental?->status === 'out' ? 'Out with' : 'Next rental', 'value' => $rental ? $rental->title.' · '.$rental->occurs_on->format('d M').'–'.$rental->due_on->format('d M') : '—'],
                ['label' => 'Rentals', 'value' => (string) $this->linked('rentals', 'car', $record)->where('status', 'returned')->count()],
            ]]]];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Rental', 'icon' => 'key-round', 'stats' => [
            ['label' => 'Days', 'value' => (string) $record->value('_days')],
            ['label' => 'Daily rate', 'value' => $this->money($record->value('_rate'))],
            ['label' => 'Distance', 'value' => $record->value('_km') === null ? '—' : $record->value('_km').' km'],
            ['label' => 'Return', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->value('_overdue') ? 'danger' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $cars = $this->records('cars')->get();
        $out = $this->records('rentals')->where('status', 'out')->orderBy('due_on')->get();
        $pickUps = $this->records('rentals')->where('status', 'reserved')->whereDate('occurs_on', today())->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Fleet', 'icon' => 'car', 'stats' => [
                ['label' => 'Available', 'value' => $cars->where('status', 'available')->count().' / '.$cars->count()],
                ['label' => 'Out', 'value' => (string) $out->count()],
                ['label' => 'Pick-ups today', 'value' => (string) $pickUps->count()],
                ['label' => 'Overdue', 'value' => (string) $out->filter(fn (Record $rental) => $rental->due_on->lt(today()))->count(), 'tone' => $out->contains(fn (Record $rental) => $rental->due_on->lt(today())) ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Due back', 'icon' => 'undo-2', 'empty' => 'No cars out.',
                'rows' => $out->map(fn (Record $rental) => ['label' => $rental->related('car')?->title.' · '.$rental->title, 'sub' => $rental->related('car')?->value('registration'), 'value' => $rental->due_on->format('d M'), 'href' => $rental->url(), 'tone' => $rental->due_on->lt(today()) ? 'danger' : ($rental->due_on->isToday() ? 'warning' : null)])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $rentals = $this->records('rentals')->whereIn('status', ['out', 'returned'])
            ->where('occurs_on', '<=', $to->copy()->endOfDay())->where('due_on', '>', $from->copy()->startOfDay())->get()->groupBy(fn (Record $rental) => (int) $rental->value('car'));
        $periodDays = max(1, (int) $from->copy()->startOfDay()->diffInDays($to->copy()->endOfDay()->addSecond()));

        $byCar = $this->records('cars')->orderBy('title')->get()->map(function (Record $car) use ($rentals, $from, $to, $periodDays) {
            $group = $rentals->get($car->id, collect());
            $days = $group->sum(fn (Record $rental) => max(0, (int) $rental->occurs_on->max($from->copy()->startOfDay())->diffInDays($rental->due_on->min($to->copy()->endOfDay()), false)));

            return [$car->title, (string) $car->value('registration'), $group->count(), $days, (int) round(min($days, $periodDays) / $periodDays * 100).'%', number_format($group->sum(fn (Record $rental) => (int) $rental->value('_km'))).' km', $this->money($group->sum('amount'))];
        })->values()->all();

        $extras = $this->dated('rentals', $from, $to)->where('status', 'returned')->get()
            ->filter(fn (Record $rental) => $rental->value('_late_days') || $rental->value('_refuel') || filled($rental->value('damage_notes')))
            ->map(fn (Record $rental) => [$rental->number, $rental->title, (int) $rental->value('_late_days'), $this->money($rental->value('_refuel')), (string) $rental->value('damage_notes')])->values()->all();

        return [
            ['title' => 'Utilisation by car', 'columns' => ['Car', 'Registration', 'Rentals', 'Days out', 'Utilisation', 'Distance', 'Revenue'], 'rows' => $byCar],
            ['title' => 'Late returns, refuelling and damage', 'columns' => ['Rental', 'Customer', 'Days late', 'Refuelling', 'Damage'], 'rows' => $extras],
        ];
    }
}
