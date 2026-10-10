<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Minibus daily collections: each vehicle cashes up once a day. What the driver owes is the day's
 * target less the fuel they paid for, and the cash handed in is set against it — anything less marks
 * the collection short. A shortfall paid later is added to the cash and clears the collection once
 * it is covered, and the shortfalls still owed are added up per driver.
 */
class KombiCollectionsLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $plate = $this->plate((string) ($data['vehicle'] ?? ''));
        $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        $twin = $plate === '' ? null : $this->records('collections')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->whereDate('occurs_on', $date->toDateString())->get()
            ->first(fn (Record $collection) => $this->plate((string) $collection->value('vehicle')) === $plate);
        if ($twin) {
            $errors['data.vehicle'] = $twin->value('vehicle').' has already cashed up on '.$date->format('d M Y').' ('.$twin->title.').';
        }
        foreach (['expected' => 'expected amount', 'fuel' => 'fuel'] as $field => $label) {
            if ((float) ($data[$field] ?? 0) < 0) {
                $errors['data.'.$field] = 'The '.$label.' cannot be negative.';
            }
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The cash cannot be negative.';
        }
        if ($payload['status'] !== 'pending' && (float) ($data['expected'] ?? 0) <= 0) {
            $errors['data.expected'] = 'Set the day\'s target before cashing up.';
        }

        return $errors;
    }

    /**
     * A registration with spaces and dashes removed, upper-cased, for comparing.
     */
    protected function plate(string $registration): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($registration));
    }

    /**
     * What the driver owes for the day: the target less the fuel they paid for.
     */
    public function owed(Record $collection): float
    {
        return max(0, round($this->number($collection, 'expected') - $this->number($collection, 'fuel'), 2));
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $this->put($record, ['vehicle' => mb_strtoupper(trim((string) $record->value('vehicle')))]);
        if ($record->status === 'pending') {
            $this->put($record, ['_owed' => $this->owed($record), '_short' => 0]);

            return;
        }
        $short = max(0, round($this->owed($record) - (float) $record->amount, 2));
        $record->status = $short > 0 ? 'short' : 'received';
        $this->put($record, ['_owed' => $this->owed($record), '_short' => $short, '_over' => max(0, round((float) $record->amount - $this->owed($record), 2))]);
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'pending' => ['cash_up' => ['label' => 'Cash up', 'icon' => 'banknote', 'fields' => [
                ['name' => 'cash', 'label' => 'Cash handed in', 'type' => 'number'],
                ['name' => 'fuel', 'label' => 'Fuel spent', 'type' => 'number', 'value' => $record->value('fuel') ?? 0],
            ]]],
            'short' => ['repay' => ['label' => 'Shortfall paid', 'icon' => 'hand-coins', 'fields' => [['name' => 'cash', 'label' => 'Amount paid', 'type' => 'number', 'value' => $record->value('_short')]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'cash_up') {
            $values = $request->validate(['cash' => ['required', 'numeric', 'min:0'], 'fuel' => ['nullable', 'numeric', 'min:0']]);
            $record->update(['status' => 'received', 'amount' => (float) $values['cash'], 'data' => [...$record->data, 'fuel' => (float) ($values['fuel'] ?? 0)]]);
        } else {
            $paid = (float) $request->validate(['cash' => ['required', 'numeric', 'gt:0', 'max:'.$this->number($record, '_short')]])['cash'];
            $record->update(['amount' => round((float) $record->amount + $paid, 2), 'data' => [...$record->data, '_repaid' => round($this->number($record, '_repaid') + $paid, 2)]]);
        }
        $vehicle = $record->value('vehicle');

        return $record->status === 'short'
            ? $vehicle.': '.$this->money($record->amount).' handed in, '.$this->money($record->value('_short')).' short.'
            : $vehicle.': '.$this->money($record->amount).' handed in, all '.$this->money($record->value('_owed')).' collected.';
    }

    public function homeCards(): array
    {
        $today = $this->records('collections')->whereDate('occurs_on', today()->toDateString())->get();
        $owing = $this->records('collections')->where('status', 'short')->get()->groupBy(fn (Record $collection) => trim((string) $collection->title))
            ->map(fn (Collection $group) => $group->sum(fn (Record $collection) => $this->number($collection, '_short')))->sortDesc();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'bus-front', 'stats' => [
                ['label' => 'Target after fuel', 'value' => $this->money($today->sum(fn (Record $collection) => $this->owed($collection)))],
                ['label' => 'Handed in', 'value' => $this->money($today->where('status', '!=', 'pending')->sum('amount'))],
                ['label' => 'Short', 'value' => $this->money($today->sum(fn (Record $collection) => $this->number($collection, '_short'))), 'tone' => $today->contains('status', 'short') ? 'danger' : null],
                ['label' => 'Still to cash up', 'value' => $today->where('status', 'pending')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Shortfalls owed', 'icon' => 'hand-coins', 'empty' => 'No driver owes anything.',
                'rows' => $owing->map(fn (float $short, string $driver) => ['label' => $driver, 'value' => $this->money($short), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $collections = $this->dated('collections', $from, $to)->where('status', '!=', 'pending')->get();
        $row = fn (Collection $group, string $label) => [$label, $group->count(), $this->money($group->sum(fn (Record $collection) => $this->number($collection, 'expected'))),
            $this->money($group->sum(fn (Record $collection) => $this->number($collection, 'fuel'))), $this->money($group->sum('amount')),
            $this->money($group->sum(fn (Record $collection) => $this->number($collection, '_short'))), $group->where('status', 'short')->count()];
        $columns = ['Days', 'Target', 'Fuel', 'Handed in', 'Still short', 'Short days'];

        return [
            ['title' => 'Collections by vehicle', 'columns' => ['Vehicle', ...$columns], 'rows' => $collections->groupBy(fn (Record $collection) => (string) $collection->value('vehicle'))->sortKeys()->map($row)->values()->all()],
            ['title' => 'Collections by driver', 'columns' => ['Driver', ...$columns], 'rows' => $collections->groupBy(fn (Record $collection) => trim((string) $collection->title))->sortKeys()->map($row)->values()->all()],
        ];
    }
}
