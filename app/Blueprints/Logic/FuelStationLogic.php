<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Fuel station: a tank's stock is its last dip plus the deliveries since, and a delivery that would
 * overfill the tank is refused. Dips can't read more than the tank holds and record how far the tank
 * drew down since the last dip. An attendant runs one pump shift at a time: closing it reads the
 * meter, works out litres sold and the takings expected at the pump price, and marks the shift
 * short when the cash and card takings don't cover it.
 */
class FuelStationLogic extends AppLogic
{
    /**
     * Share of capacity below which a tank needs ordering.
     */
    protected const LOW_LEVEL = 0.2;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($entity->key === 'tanks') {
            if ((float) ($data['capacity'] ?? 0) <= 0) {
                $errors['data.capacity'] = 'Enter the tank capacity.';
            }

            return $errors;
        }

        if ($entity->key === 'shifts') {
            if ($existing && in_array($existing->status, ['closed', 'short'], true)) {
                foreach (['opening_meter', 'closing_meter', 'cash', 'card_and_mobile'] as $field) {
                    if ((float) ($data[$field] ?? 0) !== $this->number($existing, $field)) {
                        $errors['data.'.$field] = 'This shift is closed and its takings are locked.';
                    }
                }
                if ($status !== $existing->status) {
                    $errors['status'] = 'This shift is closed.';
                }

                return $errors;
            }
            if ($status !== 'open') {
                $errors['status'] = 'Use "Close shift" so the meter and takings are checked.';
            }
            if ((float) ($data['opening_meter'] ?? 0) < 0) {
                $errors['data.opening_meter'] = 'Enter the opening meter reading.';
            }
            $busy = $this->records('shifts')->where('status', 'open')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $shift) => filled($data['attendant'] ?? null) && (string) $shift->value('attendant') === (string) $data['attendant']);
            if ($busy) {
                $errors['data.attendant'] = 'This attendant is still on '.$busy->title.'.';
            }

            return $errors;
        }

        $tank = filled($data['tank'] ?? null) ? $this->records('tanks')->find($data['tank']) : null;
        if (! $tank) {
            return $errors;
        }
        $litres = (float) ($data['litres'] ?? 0);
        if ($entity->key === 'dips') {
            if ($litres < 0) {
                $errors['data.litres'] = 'Litres in the tank cannot be negative.';
            } elseif ($litres > $this->number($tank, 'capacity')) {
                $errors['data.litres'] = $tank->title.' only holds '.number_format($this->number($tank, 'capacity')).' litres.';
            }

            return $errors;
        }

        if ($litres <= 0) {
            $errors['data.litres'] = 'Enter the litres delivered.';
        }
        if (! $existing && $tank->status !== 'active') {
            $errors['data.tank'] = $tank->title.' is out of service.';
        }
        $ullage = $this->number($tank, 'capacity') - $this->stock($tank, $existing);
        if (! $existing && $litres > $ullage) {
            $errors['data.litres'] = 'That would overfill '.$tank->title.'; it has room for '.number_format(max(0, $ullage)).' litres.';
        }

        return $errors;
    }

    /**
     * Litres in the tank: the last dip plus deliveries received since, leaving out one delivery if given.
     */
    public function stock(Record $tank, ?Record $except = null): float
    {
        $dip = $this->lastDip($tank);
        $since = $this->linked('deliveries', 'tank', $tank)->where('status', 'received')
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->when($dip, fn ($query) => $query->where(fn ($query) => $query->whereDate('occurs_on', '>', $dip->occurs_on->toDateString())->orWhere(fn ($query) => $query->whereDate('occurs_on', $dip->occurs_on->toDateString())->where('id', '>', $dip->id))))
            ->get();

        return ($dip ? $this->number($dip, 'litres') : 0) + $since->sum(fn (Record $delivery) => $this->number($delivery, 'litres'));
    }

    /**
     * The latest dip of the tank.
     */
    protected function lastDip(Record $tank): ?Record
    {
        return $this->linked('dips', 'tank', $tank)->orderByDesc('occurs_on')->orderByDesc('id')->first();
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= now();
        if ($record->entity === 'dips' && ! $record->exists && ($tank = $this->parent($record, 'tank'))) {
            $previous = $this->lastDip($tank) ? $this->stock($tank) : null;
            $this->put($record, ['_expected' => $previous, '_drawdown' => $previous === null ? null : round($previous - $this->number($record, 'litres'), 2)]);
        }
        if ($record->entity === 'shifts' && filled($record->value('closing_meter'))) {
            $this->put($record, ['litres_sold' => round($this->number($record, 'closing_meter') - $this->number($record, 'opening_meter'), 2)]);
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->entity.'.'.$record->status) {
            'shifts.open' => ['close' => ['label' => 'Close shift', 'icon' => 'lock', 'fields' => [
                ['name' => 'closing_meter', 'label' => 'Closing meter', 'type' => 'number'],
                ['name' => 'price', 'label' => 'Pump price per litre', 'type' => 'number', 'value' => $record->value('_price')],
                ['name' => 'cash', 'label' => 'Cash', 'type' => 'number'],
                ['name' => 'card_and_mobile', 'label' => 'Card & mobile money', 'type' => 'number', 'value' => 0],
            ]]],
            'deliveries.received' => ['dispute' => ['label' => 'Dispute', 'icon' => 'flag', 'fields' => [
                ['name' => 'litres_found', 'label' => 'Litres actually received', 'type' => 'number'],
                ['name' => 'reason', 'label' => 'Reason', 'type' => 'text'],
            ]]],
            'tanks.active' => ['out_of_service' => ['label' => 'Take out of service', 'icon' => 'ban']],
            'tanks.out_of_service' => ['in_service' => ['label' => 'Back in service', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'close':
                $values = $request->validate([
                    'closing_meter' => ['required', 'numeric'], 'price' => ['required', 'numeric', 'gt:0'],
                    'cash' => ['required', 'numeric', 'min:0'], 'card_and_mobile' => ['nullable', 'numeric', 'min:0'],
                ]);
                $litres = (float) $values['closing_meter'] - $this->number($record, 'opening_meter');
                if ($litres < 0) {
                    throw ValidationException::withMessages(['closing_meter' => 'The closing meter cannot be below the opening meter ('.number_format($this->number($record, 'opening_meter'), 2).').']);
                }
                $expected = round($litres * (float) $values['price'], 2);
                $taken = round((float) $values['cash'] + (float) ($values['card_and_mobile'] ?? 0), 2);
                $short = round($expected - $taken, 2);
                $record->update(['status' => $short > 0 ? 'short' : 'closed', 'amount' => $expected, 'data' => [
                    ...$record->data, 'closing_meter' => (float) $values['closing_meter'], 'cash' => (float) $values['cash'], 'card_and_mobile' => (float) ($values['card_and_mobile'] ?? 0),
                    '_price' => (float) $values['price'], '_short' => max(0, $short), '_over' => max(0, -$short),
                ]]);

                return $record->title.' closed: '.number_format($litres, 2).' litres, '.$this->money($expected).' expected'.($short > 0 ? ', '.$this->money($short).' short.' : ($short < 0 ? ', '.$this->money(-$short).' over.' : ', takings balance.'));
            case 'dispute':
                $values = $request->validate(['litres_found' => ['required', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'max:255']]);
                $record->update(['status' => 'disputed', 'data' => [...$record->data, '_litres_found' => (float) $values['litres_found'], '_dispute' => trim($values['reason'])]]);

                return $record->title.' disputed: '.number_format($this->number($record, 'litres') - (float) $values['litres_found']).' litres short.';
            case 'out_of_service':
                $record->update(['status' => 'out_of_service']);

                return $record->title.' is out of service.';
            default:
                $record->update(['status' => 'active']);

                return $record->title.' is back in service.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'tanks') {
            return [];
        }
        $stock = $this->stock($record);
        $capacity = $this->number($record, 'capacity');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Stock', 'icon' => 'gauge', 'stats' => [
            ['label' => 'Litres in tank', 'value' => number_format($stock)],
            ['label' => 'Room for', 'value' => number_format(max(0, $capacity - $stock)).' L'],
            ['label' => 'Level', 'value' => $capacity > 0 ? round($stock / $capacity * 100).'%' : '—', 'tone' => $capacity > 0 && $stock / $capacity < self::LOW_LEVEL ? 'danger' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $tanks = $this->records('tanks')->where('status', 'active')->orderBy('title')->get();
        $open = $this->records('shifts')->where('status', 'open')->get();
        $names = User::query()->whereIn('id', $open->pluck('data.attendant')->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Tank levels', 'icon' => 'gauge', 'empty' => 'No tanks in service.',
                'rows' => $tanks->map(function (Record $tank) {
                    $capacity = $this->number($tank, 'capacity');
                    $level = $capacity > 0 ? $this->stock($tank) / $capacity : 0;

                    return ['label' => $tank->title, 'sub' => ucfirst((string) $tank->value('product')), 'value' => round($level * 100).'%', 'href' => $tank->url(), 'tone' => $level < self::LOW_LEVEL ? 'danger' : null];
                })->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open shifts', 'icon' => 'fuel', 'empty' => 'No shifts open.',
                'rows' => $open->map(fn (Record $shift) => ['label' => $shift->title, 'sub' => $names[$shift->value('attendant')] ?? null, 'value' => 'From '.number_format($this->number($shift, 'opening_meter'), 2), 'href' => $shift->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $shifts = $this->dated('shifts', $from, $to)->whereIn('status', ['closed', 'short'])->get();
        $names = User::query()->whereIn('id', $shifts->pluck('data.attendant')->filter()->unique())->pluck('name', 'id');

        $byAttendant = $shifts->groupBy(fn (Record $shift) => $names[$shift->value('attendant')] ?? 'Unknown')->sortKeys()
            ->map(fn (Collection $group, string $attendant) => [
                $attendant, $group->count(), number_format($group->sum(fn (Record $shift) => $this->number($shift, 'litres_sold')), 2),
                $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $shift) => $this->number($shift, 'cash') + $this->number($shift, 'card_and_mobile'))),
                $this->money($group->sum(fn (Record $shift) => $this->number($shift, '_short'))), $group->where('status', 'short')->count(),
            ])->values()->all();

        $dips = $this->dated('dips', $from, $to)->orderBy('occurs_on')->orderBy('id')->get();
        $deliveries = $this->dated('deliveries', $from, $to)->where('status', 'received')->get();
        $drawn = $dips->sum(fn (Record $dip) => $this->number($dip, '_drawdown'));
        $sold = $shifts->sum(fn (Record $shift) => $this->number($shift, 'litres_sold'));

        return [
            ['title' => 'Shift takings by attendant', 'columns' => ['Attendant', 'Shifts', 'Litres', 'Expected', 'Taken', 'Short', 'Short shifts'], 'rows' => $byAttendant],
            ['title' => 'Wet stock', 'columns' => ['Measure', 'Litres'], 'rows' => [
                ['Delivered', number_format($deliveries->sum(fn (Record $delivery) => $this->number($delivery, 'litres')))],
                ['Drawn from tanks (by dips)', number_format($drawn, 2)],
                ['Sold at the pumps', number_format($sold, 2)],
                ['Unaccounted', number_format($drawn - $sold, 2)],
            ]],
        ];
    }
}
