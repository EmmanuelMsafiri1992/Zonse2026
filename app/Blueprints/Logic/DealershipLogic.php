<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle dealership: a vehicle's year is believable, a VIN is used once, and each vehicle shows its
 * margin. A deal defaults to the vehicle's price and shows the balance after deposit and trade-in. Once a
 * deal reaches an offer it reserves the vehicle, so no other deal can, and selling it marks the vehicle
 * sold. A lost deal puts its vehicle back in stock. Finance deals need the bank.
 */
class DealershipLogic extends AppLogic
{
    /**
     * Where a deal can go from each stage.
     *
     * @var array<string, list<string>>
     */
    public const MOVES = ['enquiry' => ['test_drive', 'offer'], 'test_drive' => ['offer'], 'offer' => ['finance', 'sold'], 'finance' => ['sold'], 'sold' => ['delivered']];

    /**
     * Deal stages that hold the vehicle.
     *
     * @var list<string>
     */
    public const HOLDING = ['offer', 'finance', 'sold', 'delivered'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'vehicles') {
            $year = (int) ($data['year'] ?? 0);
            if ($year < 1950 || $year > (int) today()->year + 1) {
                $errors['data.year'] = 'Give a year between 1950 and '.(today()->year + 1).'.';
            }
            $vin = strtoupper(trim((string) ($data['vin'] ?? '')));
            if ($vin !== '' && strlen($vin) !== 17) {
                $errors['data.vin'] = 'A VIN has 17 characters.';
            } elseif ($vin !== '' && ($twin = $this->records('vehicles')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $vehicle) => strtoupper(trim((string) $vehicle->value('vin'))) === $vin))) {
                $errors['data.vin'] = 'VIN '.$vin.' is already on '.$twin->title.'.';
            }

            return $errors;
        }
        if ($payload['status'] === 'finance' && blank($data['finance_bank'] ?? null)) {
            $errors['data.finance_bank'] = 'Say which bank is financing the deal.';
        }
        $vehicle = filled($data['vehicle'] ?? null) ? $this->records('vehicles')->find($data['vehicle']) : null;
        $value = (float) ($payload['amount'] ?? 0) ?: ($vehicle ? $this->number($vehicle, 'price') : 0);
        if ((float) ($data['deposit'] ?? 0) + (float) ($data['trade_in_value'] ?? 0) > $value) {
            $errors['data.deposit'] = 'The deposit and trade-in come to more than the deal.';
        }
        if ($vehicle && $payload['status'] !== 'lost' && ($holder = $this->holder($vehicle)) && $holder->id !== $existing?->id) {
            $errors['data.vehicle'] = $vehicle->title.' is '.($vehicle->status === 'sold' ? 'sold to ' : 'reserved for ').$holder->title.'.';
        }

        return $errors;
    }

    /**
     * The deal holding this vehicle, if any.
     */
    protected function holder(Record $vehicle): ?Record
    {
        return $this->linked('deals', 'vehicle', $vehicle)->whereIn('status', self::HOLDING)->first();
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'vehicles') {
            $cost = $this->number($record, 'cost_price');
            $this->put($record, ['_margin' => $cost > 0 ? round($this->number($record, 'price') - $cost, 2) : null]);

            return;
        }
        $record->occurs_on ??= today();
        $vehicle = $this->parent($record, 'vehicle');
        if ((float) $record->amount <= 0 && $vehicle) {
            $record->amount = $this->number($vehicle, 'price');
        }
        $this->put($record, ['_balance' => round(max(0, (float) $record->amount - $this->number($record, 'deposit') - $this->number($record, 'trade_in_value')), 2)]);
        if (in_array($record->status, ['sold', 'delivered'], true)) {
            $this->put($record, ['_sold_on' => $record->value('_sold_on') ?? today()->toDateString()]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'deals') {
            return;
        }
        foreach (array_filter([$this->parent($record, 'vehicle'), $this->previousParent($record, 'vehicle')]) as $vehicle) {
            $holder = $this->holder($vehicle);
            $status = match (true) {
                ! $holder => in_array($vehicle->status, ['reserved', 'sold'], true) ? 'in_stock' : $vehicle->status,
                in_array($holder->status, ['sold', 'delivered'], true) => 'sold',
                default => 'reserved',
            };
            if ($vehicle->status !== $status) {
                $vehicle->update(['status' => $status]);
            }
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'deals') {
            return [];
        }
        $labels = ['test_drive' => 'Test drive', 'offer' => 'Offer made', 'finance' => 'Finance application', 'sold' => 'Sold', 'delivered' => 'Delivered'];
        $actions = collect(self::MOVES[$record->status] ?? [])->mapWithKeys(fn (string $stage) => [$stage => ['label' => $labels[$stage], 'icon' => $stage === 'sold' ? 'handshake' : 'arrow-right']])->all();

        return in_array($record->status, ['enquiry', 'test_drive', 'offer', 'finance'], true) ? [...$actions, 'lost' => ['label' => 'Lost', 'icon' => 'x']] : $actions;
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $errors = $this->validate($this->app()->entity('deals'), ['status' => $action, 'amount' => $record->amount, 'data' => (array) $record->data], $record);
        if ($errors) {
            throw ValidationException::withMessages(['status' => reset($errors)]);
        }
        $record->update(['status' => $action]);
        $vehicle = $this->parent($record, 'vehicle');

        return match ($action) {
            'lost' => $record->title.'\'s deal is lost'.($vehicle ? '; '.$vehicle->title.' is '.str_replace('_', ' ', $vehicle->fresh()->status) : '').'.',
            'sold' => ($vehicle?->title ?? 'Vehicle').' sold to '.$record->title.' for '.$this->money($record->amount).'; '.$this->money($this->number($record, '_balance')).' to settle.',
            default => $record->title.'\'s deal moved to '.str_replace('_', ' ', $action).'.',
        };
    }

    public function homeCards(): array
    {
        $aged = $this->records('vehicles')->whereIn('status', ['in_stock', 'in_prep'])->where('created_at', '<', now()->subDays(90))->orderBy('created_at')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Showroom', 'icon' => 'car-front', 'stats' => [
                ['label' => 'In stock', 'value' => $this->records('vehicles')->where('status', 'in_stock')->count()],
                ['label' => 'Reserved', 'value' => $this->records('vehicles')->where('status', 'reserved')->count()],
                ['label' => 'Open deals', 'value' => $this->records('deals')->whereIn('status', ['enquiry', 'test_drive', 'offer', 'finance'])->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'In stock over 90 days', 'icon' => 'hourglass', 'empty' => 'No vehicle has been in stock for 90 days.',
                'rows' => $aged->map(fn (Record $vehicle) => ['label' => $vehicle->title, 'sub' => $vehicle->value('year').' · '.$this->money($this->number($vehicle, 'price')), 'value' => (int) $vehicle->created_at->diffInDays(now()).' days', 'href' => $vehicle->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $vehicles = $this->records('vehicles')->get()->keyBy('id');
        $sold = $this->records('deals')->whereIn('status', ['sold', 'delivered'])->get()
            ->filter(fn (Record $deal) => filled($deal->value('_sold_on')) && Carbon::parse($deal->value('_sold_on'))->between($from->copy()->startOfDay(), $to->copy()->endOfDay()));

        return [['title' => 'Vehicles sold by month', 'columns' => ['Month', 'Sold', 'Sales', 'Gross profit'], 'rows' => collect($this->months($from, $to))
            ->map(function (string $label, string $month) use ($sold, $vehicles) {
                $inMonth = $sold->filter(fn (Record $deal) => Carbon::parse($deal->value('_sold_on'))->format('Y-m') === $month);
                $profit = $inMonth->sum(fn (Record $deal) => (float) $deal->amount - $this->number($vehicles->get((int) $deal->value('vehicle')) ?? $deal, 'cost_price'));

                return [$label, $inMonth->count(), $this->money($inMonth->sum('amount')), $this->money($profit)];
            })->values()->all()]];
    }
}
