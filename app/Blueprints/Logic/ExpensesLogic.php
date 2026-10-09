<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Expenses: a claim moves draft → submitted → approved → reimbursed; it needs an amount to be
 * submitted, only claims a person paid for themselves are reimbursed, a rejected claim stays
 * rejected, and the same supplier receipt cannot be claimed twice.
 */
class ExpensesLogic extends AppLogic
{
    public const ORDER = ['draft' => 0, 'submitted' => 1, 'approved' => 2, 'reimbursed' => 3];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($status !== 'draft' && (float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Enter the amount spent.';
        }
        if ($status === 'reimbursed' && ($data['payment_method'] ?? null) !== 'personal') {
            $errors['status'] = 'Only expenses paid with personal money are reimbursed.';
        } elseif ($status === 'reimbursed' && $existing?->status !== 'approved' && $existing?->status !== 'reimbursed') {
            $errors['status'] = 'Approve the claim before reimbursing it.';
        }
        if ($existing?->status === 'rejected' && $status !== 'rejected' && $status !== 'draft') {
            $errors['status'] = 'A rejected claim goes back to draft before it is submitted again.';
        }

        if (filled($data['receipt_number'] ?? null)) {
            $duplicate = $this->records('expenses')->where('data->receipt_number', $data['receipt_number'])->where('data->supplier', $data['supplier'] ?? null)
                ->whereNot('status', 'rejected')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();
            if ($duplicate) {
                $errors['data.receipt_number'] = 'Receipt '.$data['receipt_number'].' is already claimed on '.$duplicate->number.'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->isDirty('status') && in_array($record->status, ['approved', 'rejected'], true)) {
            $this->put($record, ['_decided_on' => today()->toDateString()]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->status === 'submitted') {
            return [
                'approve' => ['label' => 'Approve', 'icon' => 'check', 'confirm' => 'Approve '.$this->money($record->amount).' for '.$record->title.'?'],
                'reject' => ['label' => 'Reject', 'icon' => 'x', 'confirm' => 'Reject this expense?'],
            ];
        }
        if ($record->status === 'approved' && $record->value('payment_method') === 'personal') {
            return ['reimburse' => ['label' => 'Mark reimbursed', 'icon' => 'banknote', 'confirm' => 'Mark '.$this->money($record->amount).' as paid back?']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $status = ['approve' => 'approved', 'reject' => 'rejected', 'reimburse' => 'reimbursed'][$action] ?? abort(404);
        $record->update(['status' => $status]);

        return $record->title.' is '.$status.'.';
    }

    public function homeCards(): array
    {
        $waiting = $this->records('expenses')->where('status', 'submitted')->orderBy('occurs_on')->get();
        $owed = $this->records('expenses')->where('status', 'approved')->where('data->payment_method', 'personal')->sum('amount');
        $month = $this->records('expenses')->whereIn('status', ['approved', 'reimbursed'])->whereBetween('occurs_on', [today()->startOfMonth(), today()->endOfMonth()])->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Spending', 'icon' => 'receipt', 'stats' => [
                ['label' => 'Approved this month', 'value' => $this->money($month->sum('amount'))],
                ['label' => 'Waiting for approval', 'value' => $waiting->count().' · '.$this->money($waiting->sum('amount')), 'tone' => $waiting->isNotEmpty() ? 'warning' : null],
                ['label' => 'To pay back to staff', 'value' => $this->money($owed)],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting for approval', 'icon' => 'clock', 'empty' => 'Nothing waiting.',
                'rows' => $waiting->take(15)->map(fn (Record $expense) => [
                    'label' => $expense->title, 'sub' => ucfirst((string) $expense->value('category')), 'value' => $this->money($expense->amount), 'href' => $expense->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $spent = $this->dated('expenses', $from, $to)->whereIn('status', ['approved', 'reimbursed'])->get();
        $months = $this->months($from, $to);
        $categories = $spent->groupBy(fn (Record $expense) => (string) $expense->value('category'))->sortKeys()->map(function ($group, $category) use ($months) {
            $row = [ucfirst($category)];
            foreach (array_keys($months) as $month) {
                $row[] = $this->money($group->filter(fn (Record $expense) => $expense->occurs_on?->format('Y-m') === $month)->sum('amount'));
            }
            $row[] = $this->money($group->sum('amount'));

            return $row;
        })->values()->all();

        $methods = $spent->groupBy(fn (Record $expense) => (string) ($expense->value('payment_method') ?: 'not given'))->sortKeys()
            ->map(fn ($group, $method) => [ucfirst(str_replace('_', ' ', $method)), $group->count(), $this->money($group->sum('amount'))])->values()->all();

        $billable = $spent->filter(fn (Record $expense) => (bool) $expense->value('billable'))->map(fn (Record $expense) => [
            $expense->occurs_on?->format('d M Y') ?? '—', $expense->title, (string) ($expense->value('supplier') ?? '—'), $this->money($expense->amount),
        ])->values()->all();

        return [
            ['title' => 'Spending by category', 'columns' => ['Category', ...array_values($months), 'Total'], 'rows' => $categories],
            ['title' => 'Paid with', 'columns' => ['Method', 'Expenses', 'Amount'], 'rows' => $methods],
            ['title' => 'Billable to clients', 'columns' => ['Date', 'Expense', 'Supplier', 'Amount'], 'rows' => $billable],
        ];
    }
}
