<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Payables: a supplier bill is approved before anyone pays it, each payment counts against the
 * bill (which becomes part paid, then paid), a payment can never be more than what is still
 * owed, and a supplier's invoice can only be captured once.
 */
class PayablesLogic extends AppLogic
{
    /** Bill statuses that may be paid. */
    public const PAYABLE = ['approved', 'part_paid'];

    /** Bill statuses the payments decide; received and disputed are set by people. */
    public const AUTOMATIC = ['approved', 'part_paid', 'paid'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'bills' && filled($data['supplier_invoice_number'] ?? null) && ! empty($payload['contact_id'])) {
            $duplicate = $this->records('bills')->where('contact_id', $payload['contact_id'])->where('data->supplier_invoice_number', $data['supplier_invoice_number'])
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();

            return $duplicate ? ['data.supplier_invoice_number' => 'This supplier invoice is already captured as '.$duplicate->number.'.'] : [];
        }

        if ($entity->key !== 'payments' || $payload['status'] === 'cancelled') {
            return [];
        }

        $errors = [];
        if (($data['method'] ?? null) === 'cheque' && blank($data['cheque_number'] ?? null)) {
            $errors['data.cheque_number'] = 'Enter the cheque number.';
        }

        $bill = ! empty($data['bill']) ? $this->records('bills')->find($data['bill']) : null;
        if ($bill) {
            $alreadyCounted = $existing && $existing->status === 'paid' && (int) $existing->value('bill') === $bill->id ? (float) $existing->amount : 0.0;
            $outstanding = round($this->outstanding($bill) + $alreadyCounted, 2);
            if (! in_array($bill->status, [...self::PAYABLE, 'paid'], true)) {
                $errors['data.bill'] = 'Bill '.$bill->number.' is '.str_replace('_', ' ', $bill->status).'. Approve it before paying.';
            } elseif ((float) ($payload['amount'] ?? 0) - $outstanding > 0.004) {
                $errors['amount'] = 'Only '.$this->money($outstanding).' is still owed on '.$bill->number.'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'bills' || ! $record->exists) {
            return;
        }

        $paid = round((float) $this->linked('payments', 'bill', $record)->where('status', 'paid')->sum('amount'), 2);
        $paidBefore = (float) (((array) $record->getOriginal('data'))['paid_amount'] ?? 0);
        $this->put($record, ['paid_amount' => $paid]);

        // A bill marked paid by hand with no payments recorded stays paid; once payments exist, they decide.
        if (in_array($record->status, self::AUTOMATIC, true) && ($paid > 0 || $paidBefore > 0 || $record->status === 'part_paid')) {
            $record->status = match (true) {
                $paid > 0 && $paid >= (float) $record->amount - 0.004 => 'paid',
                $paid > 0 => 'part_paid',
                default => 'approved',
            };
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'payments') {
            $this->recalculate($this->parent($record, 'bill'));
            $this->recalculate($this->previousParent($record, 'bill'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'payments') {
            $this->recalculate($this->parent($record, 'bill'));
        }
    }

    public function outstanding(Record $bill): float
    {
        return round((float) $bill->amount - (float) $this->linked('payments', 'bill', $bill)->where('status', 'paid')->sum('amount'), 2);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'bills' && in_array($record->status, self::PAYABLE, true) && $this->outstanding($record) > 0) {
            return ['pay_in_full' => [
                'label' => 'Pay in full', 'icon' => 'send',
                'fields' => [
                    ['name' => 'method', 'label' => 'Paid by', 'type' => 'select', 'options' => ['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'mobile_money' => 'Mobile money'], 'value' => 'bank_transfer'],
                    ['name' => 'reference', 'label' => 'Payment reference', 'type' => 'text'],
                ],
            ]];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $validated = $request->validate(['method' => ['required', 'in:bank_transfer,cash,mobile_money'], 'reference' => ['nullable', 'string', 'max:120']]);
        $payment = $this->pay($record, $validated['method'], $validated['reference'] ?? null);

        return 'Paid '.$this->money($payment->amount).' ('.$payment->number.'). '.$record->fresh()->number.' is settled.';
    }

    /** Record a payment of whatever is still owed on the bill. */
    public function pay(Record $bill, string $method, ?string $reference = null): Record
    {
        return Record::create([
            'workspace_id' => $bill->workspace_id, 'blueprint' => $this->app->key, 'entity' => 'payments',
            'title' => $reference ?: 'Payment of '.$bill->number, 'status' => 'paid', 'amount' => $this->outstanding($bill), 'currency' => $bill->currency,
            'occurs_on' => today(), 'data' => ['bill' => $bill->id, 'method' => $method],
        ]);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'bills') {
            return [];
        }

        $payments = $this->linked('payments', 'bill', $record)->latest('occurs_on')->get();
        $outstanding = $this->outstanding($record);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Payment', 'icon' => 'send', 'stats' => [
                ['label' => 'Bill total', 'value' => $this->money($record->amount)],
                ['label' => 'Paid', 'value' => $this->money($record->value('paid_amount'))],
                ['label' => 'Still owed', 'value' => $this->money($outstanding), 'tone' => $outstanding > 0 && $record->due_on?->isPast() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Payments', 'icon' => 'send', 'empty' => 'No payments yet.',
                'rows' => $payments->map(fn (Record $payment) => [
                    'label' => $payment->title, 'sub' => $payment->number.' · '.($payment->occurs_on ?? $payment->created_at)->format('d M Y').' · '.str_replace('_', ' ', $payment->status),
                    'value' => $this->money($payment->amount), 'href' => $payment->url(), 'tone' => $payment->status === 'cancelled' ? 'muted' : null,
                ])->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $bills = $this->records('bills')->whereIn('status', ['received', ...self::PAYABLE])->whereDate('due_on', '<=', today()->addDays(14))->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Bills due in the next 14 days', 'icon' => 'file-minus', 'empty' => 'Nothing due soon.',
            'rows' => $bills->map(fn (Record $bill) => [
                'label' => $bill->title, 'sub' => ($bill->due_on->isPast() ? 'Overdue since ' : 'Due ').$bill->due_on->format('d M Y').($bill->status === 'received' ? ' · not approved' : ''),
                'value' => $this->money($this->outstanding($bill)), 'href' => $bill->url(), 'tone' => $bill->due_on->isPast() ? 'danger' : 'warning',
            ])->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $open = $this->records('bills')->whereIn('status', ['received', 'disputed', ...self::PAYABLE])->with('contact')->get();
        $ages = ['Not yet due' => 0, '1–30 days' => 30, '31–60 days' => 60, 'Over 60 days' => PHP_INT_MAX];
        $creditors = $open->groupBy(fn (Record $bill) => $bill->contact?->name ?? 'No supplier named')->map(function ($bills, $supplier) use ($ages) {
            $buckets = array_fill_keys(array_keys($ages), 0.0);
            foreach ($bills as $bill) {
                $days = $bill->due_on && $bill->due_on->lt(today()) ? (int) $bill->due_on->diffInDays(today()) : 0;
                $label = collect($ages)->search(fn (int $max) => $days <= $max);
                $buckets[$label] += $this->outstanding($bill);
            }

            return [$supplier, ...array_map(fn (float $amount) => $this->money($amount), array_values($buckets)), $this->money(array_sum($buckets))];
        })->sortKeys()->values()->all();

        $bills = $this->dated('bills', $from, $to)->whereNot('status', 'disputed')->get();
        $categories = $this->app->entity('bills')?->field('category')?->options ?? [];
        $byCategory = $bills->groupBy(fn (Record $bill) => (string) $bill->value('category'))->sortKeys()
            ->map(fn ($group, $key) => [$categories[$key] ?? 'Uncategorised', $group->count(), $this->money($group->sum(fn (Record $bill) => $this->number($bill, 'vat'))), $this->money($group->sum('amount'))])->values()->all();

        return [
            ['title' => 'Aged creditors today', 'columns' => ['Supplier', ...array_keys($ages), 'Total'], 'rows' => $creditors],
            ['title' => 'Bills by category', 'columns' => ['Category', 'Bills', 'VAT', 'Total'], 'rows' => $byCategory, 'note' => 'Bills dated in the period, except disputed ones.'],
        ];
    }
}
