<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Small landlord: a let unit names its tenant and a monthly rent. Rent received is checked against the
 * unit's rent: nothing is missed, less is part paid, and full rent after the 7th of the month is late.
 * An open repair marks the unit as being repaired, and fixing it puts the unit back to let or vacant.
 * The home page shows this month's rent against what is due and leases ending soon; the report nets
 * repair costs off rent per unit.
 */
class SmallLandlordLogic extends AppLogic
{
    public const DUE_DAY = 7;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'units') {
            if ((float) ($data['rent'] ?? 0) <= 0) {
                $errors['data.rent'] = 'Set the monthly rent.';
            }
            if ($payload['status'] === 'let' && blank($data['tenant'] ?? null)) {
                $errors['data.tenant'] = 'Name the tenant of a let unit.';
            }
        }
        if (in_array($entity->key, ['payments', 'repairs'], true) && (float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The amount cannot be negative.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity !== 'payments' || ! ($unit = $this->parent($record, 'unit'))) {
            return;
        }
        $rent = $this->number($unit, 'rent');
        $paid = (float) $record->amount;
        $record->status = match (true) {
            $paid <= 0 => 'missed',
            $paid < $rent => 'part_paid',
            $record->occurs_on->day > self::DUE_DAY => 'late',
            default => 'paid',
        };
        $this->put($record, ['_short' => round(max(0, $rent - $paid), 2)]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'repairs' || ! ($unit = $this->parent($record, 'unit'))) {
            return;
        }
        $open = $this->linked('repairs', 'unit', $unit)->where('status', '!=', 'fixed')->exists();
        $status = $open ? 'being_repaired' : (filled($unit->value('tenant')) ? 'let' : 'vacant');
        if ($unit->status !== $status) {
            $unit->update(['status' => $status]);
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'units') {
            return [];
        }
        $payments = $this->linked('payments', 'unit', $record)->whereYear('occurs_on', today()->year)->get();
        $repairs = $this->linked('repairs', 'unit', $record)->whereYear('occurs_on', today()->year)->get();
        $rent = (float) $payments->sum(fn (Record $payment) => (float) $payment->amount);
        $costs = (float) $repairs->sum(fn (Record $repair) => (float) $repair->amount);

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => today()->year.' so far', 'icon' => 'house', 'stats' => [
            ['label' => 'Rent received', 'value' => $this->money($rent)],
            ['label' => 'Repairs', 'value' => $this->money($costs)],
            ['label' => 'Net', 'value' => $this->money($rent - $costs), 'tone' => $rent - $costs < 0 ? 'danger' : null],
            ['label' => 'Late or short', 'value' => $payments->whereIn('status', ['late', 'part_paid', 'missed'])->count()],
        ]]]];
    }

    public function homeCards(): array
    {
        $units = $this->records('units')->orderBy('title')->get();
        $thisMonth = $this->dated('payments', today()->startOfMonth(), today()->endOfMonth())->get()->groupBy(fn (Record $payment) => (int) $payment->value('unit'));
        $let = $units->filter(fn (Record $unit) => filled($unit->value('tenant')));
        $unpaid = $let->filter(fn (Record $unit) => ($thisMonth[$unit->id] ?? collect())->sum(fn (Record $payment) => (float) $payment->amount) < $this->number($unit, 'rent'));
        $leases = $let->filter(fn (Record $unit) => filled($unit->value('lease_end')) && Carbon::parse($unit->value('lease_end'))->lte(today()->addDays(60)));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => today()->format('F').' rent', 'icon' => 'coins', 'stats' => [
                ['label' => 'Due', 'value' => $this->money($let->sum(fn (Record $unit) => $this->number($unit, 'rent')))],
                ['label' => 'Received', 'value' => $this->money($thisMonth->flatten()->sum(fn (Record $payment) => (float) $payment->amount))],
                ['label' => 'Vacant units', 'value' => $units->where('status', 'vacant')->count(), 'tone' => $units->where('status', 'vacant')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Rent not in yet', 'icon' => 'coins', 'empty' => 'Everyone has paid this month.',
                'rows' => $unpaid->map(fn (Record $unit) => ['label' => $unit->title, 'sub' => $unit->value('tenant'), 'value' => $this->money($this->number($unit, 'rent') - ($thisMonth[$unit->id] ?? collect())->sum(fn (Record $payment) => (float) $payment->amount)).' to come', 'href' => $unit->url(), 'tone' => today()->day > self::DUE_DAY ? 'danger' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Leases ending', 'icon' => 'calendar-clock', 'empty' => 'No leases end in the next 60 days.',
                'rows' => $leases->map(fn (Record $unit) => ['label' => $unit->title, 'sub' => $unit->value('tenant'), 'value' => Carbon::parse($unit->value('lease_end'))->format('d M Y'), 'href' => $unit->url(), 'tone' => Carbon::parse($unit->value('lease_end'))->lt(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $payments = $this->dated('payments', $from, $to)->get()->groupBy(fn (Record $payment) => (int) $payment->value('unit'));
        $repairs = $this->dated('repairs', $from, $to)->get()->groupBy(fn (Record $repair) => (int) $repair->value('unit'));

        return [['title' => 'Income by unit', 'columns' => ['Unit', 'Rent received', 'Late or short', 'Repairs', 'Net'], 'rows' => $this->records('units')->orderBy('title')->get()
            ->map(function (Record $unit) use ($payments, $repairs) {
                $rent = (float) ($payments[$unit->id] ?? collect())->sum(fn (Record $payment) => (float) $payment->amount);
                $costs = (float) ($repairs[$unit->id] ?? collect())->sum(fn (Record $repair) => (float) $repair->amount);

                return [$unit->title, $this->money($rent), ($payments[$unit->id] ?? collect())->whereIn('status', ['late', 'part_paid', 'missed'])->count(), $this->money($costs), $this->money($rent - $costs)];
            })->values()->all()]];
    }
}
