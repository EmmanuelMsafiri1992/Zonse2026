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
 * Council revenue: a rates bill adds up its rates, refuse, water and sewer charges, is billed only
 * to active properties, takes payments up to its balance and turns overdue by itself after its due
 * date. A property's balance is the sum of its unpaid bills; it is marked in arrears while it has
 * an overdue bill, can be handed over for collection only while in arrears, and an exempt property
 * is never billed.
 */
class CouncilRevenueLogic extends AppLogic
{
    public const CHARGES = ['rates', 'refuse', 'water', 'sewer'];

    public const UNPAID = ['billed', 'part_paid', 'overdue'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'properties') {
            if ((float) ($data['valuation'] ?? 0) < 0) {
                $errors['data.valuation'] = 'The valuation cannot be negative.';
            }
            if ($payload['status'] === 'handed_over' && (! $existing || $existing->status !== 'in_arrears')) {
                $errors['status'] = 'Only a property in arrears is handed over for collection.';
            }

            return $errors;
        }

        $property = ! empty($data['property']) ? $this->records('properties')->find($data['property']) : null;
        if ($property && in_array($property->status, ['exempt', 'handed_over'], true) && (! $existing || (int) $existing->value('property') !== $property->id)) {
            $errors['data.property'] = $property->title.' is '.str_replace('_', ' ', $property->status).' and is not billed.';
        }
        foreach (self::CHARGES as $charge) {
            if ((float) ($data[$charge] ?? 0) < 0) {
                $errors['data.'.$charge] = 'A charge cannot be negative.';
            }
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The bill is due after it is billed.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'bills') {
            $record->occurs_on ??= today();
            $record->due_on ??= $record->occurs_on->copy()->addDays(30);
            $charges = collect(self::CHARGES)->sum(fn (string $charge) => (float) $record->value($charge));
            if ($charges > 0 || (float) $record->amount <= 0) {
                $record->amount = round($charges, 2);
            }
            $paid = min((float) $record->amount, (float) $record->value('_paid'));
            $balance = round((float) $record->amount - $paid, 2);
            if ($balance <= 0) {
                $record->status = 'paid';
            } elseif ($paid > 0 && $record->status === 'billed') {
                $record->status = 'part_paid';
            } elseif ($record->status === 'paid') {
                $record->status = $paid > 0 ? 'part_paid' : 'billed';
            }
            $this->put($record, [
                '_paid' => round($paid, 2),
                '_balance' => $balance,
                '_paid_on' => $record->status === 'paid' ? ($record->value('_paid_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $bills = $record->exists ? $this->linked('bills', 'property', $record)->get() : collect();
        $unpaid = $bills->whereIn('status', self::UNPAID);
        $overdue = $bills->where('status', 'overdue');
        if (in_array($record->status, ['active', 'in_arrears'], true)) {
            $record->status = $overdue->isNotEmpty() ? 'in_arrears' : 'active';
        }
        $this->put($record, [
            'balance' => round($unpaid->sum(fn (Record $bill) => (float) $bill->value('_balance')), 2),
            '_bills' => $bills->count(),
            '_unpaid_bills' => $unpaid->count(),
            '_overdue_bills' => $overdue->count(),
            '_billed_year' => round($bills->filter(fn (Record $bill) => $bill->occurs_on?->isCurrentYear())->sum('amount'), 2),
            '_paid_year' => round($bills->filter(fn (Record $bill) => $bill->occurs_on?->isCurrentYear())->sum(fn (Record $bill) => (float) $bill->value('_paid')), 2),
            '_oldest_overdue' => $overdue->sortBy('due_on')->first()?->due_on?->toDateString(),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'bills') {
            $this->recalculate($this->parent($record, 'property'));
            $this->recalculate($this->previousParent($record, 'property'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'bills') {
            $this->recalculate($this->parent($record, 'property'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $overdue = 0;
        foreach ($this->records('bills')->whereIn('status', ['billed', 'part_paid'])->whereDate('due_on', '<', today())->get() as $bill) {
            $bill->update(['status' => 'overdue']);
            $overdue++;
        }

        return $overdue;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'bills') {
            return in_array($record->status, self::UNPAID, true)
                ? ['pay' => ['label' => 'Record payment', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'value' => $record->value('_balance')]]]]
                : [];
        }

        $bill = ['label' => 'Issue bill', 'icon' => 'receipt', 'fields' => [
            ['name' => 'title', 'label' => 'Period', 'type' => 'text', 'value' => today()->format('M Y')],
            ['name' => 'rates', 'label' => 'Rates', 'type' => 'number'],
            ['name' => 'refuse', 'label' => 'Refuse', 'type' => 'number'],
            ['name' => 'water', 'label' => 'Water', 'type' => 'number'],
            ['name' => 'sewer', 'label' => 'Sewer', 'type' => 'number'],
            ['name' => 'due_on', 'label' => 'Due', 'type' => 'date', 'value' => today()->addDays(30)->toDateString()],
        ]];

        return match ($record->status) {
            'active' => ['bill' => $bill, 'exempt' => ['label' => 'Exempt', 'icon' => 'shield-off', 'confirm' => 'Exempt '.$record->title.' from rates?']],
            'in_arrears' => ['bill' => $bill, 'hand_over' => ['label' => 'Hand over', 'icon' => 'gavel', 'confirm' => 'Hand '.$record->title.' over for collection?']],
            default => ['reinstate' => ['label' => 'Reinstate', 'icon' => 'undo']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'pay':
                $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'min:0.01']])['amount'];
                $balance = (float) $record->value('_balance');
                if ($amount > $balance + 0.005) {
                    throw ValidationException::withMessages(['amount' => 'The payment is more than the balance of '.$this->money($balance).'.']);
                }
                $record->update(['data' => [...$record->data, '_paid' => (float) $record->value('_paid') + $amount]]);
                $record = $record->fresh();

                return $record->status === 'paid' ? 'Payment received; the bill for '.$record->title.' is paid in full.' : 'Payment received; the bill for '.$record->title.' still has a balance.';
            case 'bill':
                $input = $request->validate(['title' => ['required', 'string'], 'rates' => ['nullable', 'numeric', 'min:0'], 'refuse' => ['nullable', 'numeric', 'min:0'], 'water' => ['nullable', 'numeric', 'min:0'], 'sewer' => ['nullable', 'numeric', 'min:0'], 'due_on' => ['required', 'date', 'after_or_equal:today']]);
                $charges = collect(self::CHARGES)->mapWithKeys(fn (string $charge) => [$charge => (float) ($input[$charge] ?? 0)]);
                if ($charges->sum() <= 0) {
                    throw ValidationException::withMessages(['rates' => 'Enter at least one charge.']);
                }
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'bills', 'title' => $input['title'], 'status' => 'billed',
                    'occurs_on' => today(), 'due_on' => Carbon::parse($input['due_on']), 'amount' => round($charges->sum(), 2), 'currency' => $record->currency,
                    'data' => ['property' => $record->id, ...$charges->all()],
                ]);

                return 'Rates bill for '.$input['title'].' issued to '.$record->title.'.';
            case 'exempt':
                $record->update(['status' => 'exempt']);

                return $record->title.' is exempt from rates.';
            case 'hand_over':
                $record->update(['status' => 'handed_over']);

                return $record->title.' handed over for collection.';
        }

        $record->update(['status' => 'active']);

        return $record->title.' is rateable again.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'bills') {
            return [
                ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Bill', 'icon' => 'receipt', 'stats' => [
                    ...collect(self::CHARGES)->map(fn (string $charge) => ['label' => ucfirst($charge), 'value' => $this->money($this->number($record, $charge))])->all(),
                    ['label' => 'Total', 'value' => $this->money($record->amount)],
                    ['label' => 'Paid', 'value' => $this->money($this->number($record, '_paid'))],
                    ['label' => 'Balance', 'value' => $this->money($this->number($record, '_balance')), 'tone' => $record->status === 'overdue' ? 'danger' : ($this->number($record, '_balance') > 0 ? 'warning' : 'success')],
                ]]],
            ];
        }

        $bills = $this->linked('bills', 'property', $record)->orderByDesc('occurs_on')->get();
        $categories = $this->app->entities['properties']->field('category')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Property', 'icon' => 'house', 'stats' => [
                ['label' => 'Owner', 'value' => (string) ($record->value('owner') ?: '—')],
                ['label' => 'Category', 'value' => $categories[$record->value('category')] ?? ucfirst(str_replace('_', ' ', (string) ($record->value('category') ?: '—')))],
                ['label' => 'Valuation', 'value' => $this->money($this->number($record, 'valuation'))],
                ['label' => 'Balance', 'value' => $this->money($this->number($record, 'balance')), 'tone' => (int) $record->value('_overdue_bills') > 0 ? 'danger' : ($this->number($record, 'balance') > 0 ? 'warning' : 'success')],
                ['label' => 'Overdue bills', 'value' => (string) (int) $record->value('_overdue_bills'), 'tone' => (int) $record->value('_overdue_bills') > 0 ? 'danger' : null],
                ['label' => 'Billed this year', 'value' => $this->money($this->number($record, '_billed_year'))],
                ['label' => 'Paid this year', 'value' => $this->money($this->number($record, '_paid_year'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Bills', 'icon' => 'receipt', 'empty' => 'No bills issued.',
                'rows' => $bills->take(12)->map(fn (Record $bill) => [
                    'label' => $bill->title, 'sub' => 'Due '.$bill->due_on?->format('d M Y').' · '.ucfirst(str_replace('_', ' ', $bill->status)), 'value' => $this->money($bill->amount).' · owes '.$this->money($this->number($bill, '_balance')), 'href' => $bill->url(), 'tone' => $bill->status === 'paid' ? 'success' : ($bill->status === 'overdue' ? 'danger' : 'warning'),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $properties = $this->records('properties')->get();
        $bills = $this->records('bills')->get();
        $rateable = $properties->whereIn('status', ['active', 'in_arrears']);
        $arrears = $properties->where('status', 'in_arrears')->sortByDesc(fn (Record $property) => (float) $property->value('balance'));
        $thisMonth = $bills->filter(fn (Record $bill) => $bill->occurs_on?->isCurrentMonth());

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Council revenue', 'icon' => 'landmark', 'stats' => [
                ['label' => 'Rateable properties', 'value' => (string) $rateable->count()],
                ['label' => 'In arrears', 'value' => (string) $arrears->count(), 'tone' => $arrears->isNotEmpty() ? 'danger' : null],
                ['label' => 'Handed over', 'value' => (string) $properties->where('status', 'handed_over')->count()],
                ['label' => 'Billed this month', 'value' => $this->money($thisMonth->sum('amount'))],
                ['label' => 'Collected this month', 'value' => $this->money($bills->filter(fn (Record $bill) => filled($bill->value('_paid_on')) && Carbon::parse($bill->value('_paid_on'))->isCurrentMonth())->sum(fn (Record $bill) => (float) $bill->value('_paid')))],
                ['label' => 'Outstanding', 'value' => $this->money($bills->whereIn('status', self::UNPAID)->sum(fn (Record $bill) => (float) $bill->value('_balance'))), 'tone' => 'warning'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Largest arrears', 'icon' => 'alert-triangle', 'empty' => 'No property is in arrears.',
                'rows' => $arrears->take(10)->map(fn (Record $property) => [
                    'label' => $property->title, 'sub' => ($property->value('owner') ?: '').' · '.(int) $property->value('_overdue_bills').' overdue bills', 'value' => $this->money($this->number($property, 'balance')), 'href' => $property->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $properties = $this->records('properties')->get();
        $categories = $this->app->entities['properties']->field('category')?->options ?? [];
        $byCategory = collect($categories)->map(fn (string $label, string $category) => [
            $label, $properties->where('data.category', $category)->count(), $properties->where('data.category', $category)->where('status', 'in_arrears')->count(), $this->money($properties->where('data.category', $category)->sum(fn (Record $property) => (float) $property->value('valuation'))), $this->money($properties->where('data.category', $category)->sum(fn (Record $property) => (float) $property->value('balance'))),
        ])->values()->all();

        $bills = $this->dated('bills', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($bills) {
            $group = $bills->filter(fn (Record $bill) => $bill->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $bill) => (float) $bill->value('_paid'))), $this->money($group->sum(fn (Record $bill) => (float) $bill->value('_balance'))), $group->where('status', 'overdue')->count()];
        })->values()->all();

        $arrears = $properties->filter(fn (Record $property) => (float) $property->value('balance') > 0)->sortByDesc(fn (Record $property) => (float) $property->value('balance'))->take(20)->map(fn (Record $property) => [
            $property->title, $property->value('owner') ?: '—', ucfirst(str_replace('_', ' ', $property->status)), (int) $property->value('_overdue_bills'), $property->value('_oldest_overdue') ? Carbon::parse($property->value('_oldest_overdue'))->format('d M Y') : '—', $this->money((float) $property->value('balance')),
        ])->values()->all();

        return [
            ['title' => 'Properties by category', 'columns' => ['Category', 'Properties', 'In arrears', 'Valuation', 'Outstanding'], 'rows' => $byCategory],
            ['title' => 'Billing by month', 'columns' => ['Month', 'Bills', 'Billed', 'Collected', 'Outstanding', 'Overdue'], 'rows' => $byMonth],
            ['title' => 'Arrears', 'columns' => ['Property', 'Owner', 'Status', 'Overdue bills', 'Oldest due', 'Balance'], 'rows' => $arrears],
        ];
    }
}
