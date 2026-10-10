<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Personal finance: income can only be filed under income categories and spending under spending
 * ones. A savings goal is reached once the saved amount meets the target, and shows how much to put
 * away each month to get there by the target date. Paying a debt brings its balance down and moves the
 * next payment on a month; it is paid off at zero. The home page shows this month's income and spending.
 */
class PersonalMoneyLogic extends AppLogic
{
    /**
     * @var list<string>
     */
    public const INCOME_CATEGORIES = ['salary', 'side_income'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'transactions') {
            if ((float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Give an amount above zero.';
            }
            $income = in_array($data['category'] ?? null, self::INCOME_CATEGORIES, true);
            if (($data['type'] ?? null) === 'expense' && $income) {
                $errors['data.category'] = ucfirst(str_replace('_', ' ', (string) $data['category'])).' is income, not spending.';
            }
            if (($data['type'] ?? null) === 'income' && ! $income && ($data['category'] ?? null) !== 'other') {
                $errors['data.category'] = ucfirst(str_replace('_', ' ', (string) $data['category'])).' is spending, not income.';
            }
        }
        if ($entity->key === 'goals') {
            if ((float) ($data['target'] ?? 0) <= 0) {
                $errors['data.target'] = 'Set a target above zero.';
            }
            if ((float) ($data['saved'] ?? 0) < 0) {
                $errors['data.saved'] = 'Saved so far cannot be negative.';
            }
        }
        if ($entity->key === 'debts' && ((float) ($data['balance'] ?? 0) < 0 || (float) ($data['monthly_payment'] ?? 0) < 0)) {
            $errors['data.balance'] = 'Amounts cannot be negative.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'transactions') {
            $record->occurs_on ??= today();

            return;
        }
        if ($record->entity === 'goals') {
            $left = max(0, $this->number($record, 'target') - $this->number($record, 'saved'));
            if ($record->status !== 'paused') {
                $record->status = $left <= 0 ? 'reached' : 'saving';
            }
            $months = $record->due_on ? max(1, (int) ceil(today()->floatDiffInMonths($record->due_on, false))) : null;
            $this->put($record, ['_monthly_needed' => $months && $left > 0 ? round($left / $months, 2) : null]);

            return;
        }
        if ($this->number($record, 'balance') <= 0) {
            $record->status = 'paid_off';
        }
        $monthly = $this->number($record, 'monthly_payment');
        $this->put($record, ['_months_left' => $monthly > 0 && $this->number($record, 'balance') > 0 ? (int) ceil($this->number($record, 'balance') / $monthly) : null]);
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'goals' && $record->status !== 'reached' => ['add' => ['label' => 'Add savings', 'icon' => 'plus', 'fields' => [['name' => 'amount', 'label' => 'Amount', 'type' => 'number']]]],
            $record->entity === 'debts' && $record->status === 'active' => ['pay' => ['label' => 'Make a payment', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'value' => min($this->number($record, 'monthly_payment') ?: $this->number($record, 'balance'), $this->number($record, 'balance'))]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'gt:0']])['amount'];
        if ($action === 'add') {
            $record->update(['data' => [...$record->data, 'saved' => round($this->number($record, 'saved') + $amount, 2)]]);
            $target = $this->number($record, 'target');

            return 'Added '.$this->money($amount).' to '.$record->title.': '.$this->money($this->number($record, 'saved')).' of '.$this->money($target).' ('.round($this->number($record, 'saved') / $target * 100).'%).';
        }
        $balance = $this->number($record, 'balance');
        if ($amount > $balance) {
            throw ValidationException::withMessages(['amount' => 'Only '.$this->money($balance).' is owed.']);
        }
        $left = round($balance - $amount, 2);
        $record->update(['due_on' => $left > 0 ? ($record->due_on ?? today())->copy()->addMonthNoOverflow() : $record->due_on, 'data' => [...$record->data, 'balance' => $left, '_paid_total' => round($this->number($record, '_paid_total') + $amount, 2)]]);

        return 'Paid '.$this->money($amount).' to '.$record->title.'; '.($left > 0 ? $this->money($left).' still owed.' : 'it is paid off.');
    }

    /**
     * Income and spending in a date range.
     *
     * @return array{0: float, 1: float}
     */
    protected function flow(Carbon $from, Carbon $to): array
    {
        $transactions = $this->dated('transactions', $from, $to)->get();

        return [
            round($transactions->filter(fn (Record $transaction) => $transaction->value('type') === 'income')->sum(fn (Record $transaction) => (float) $transaction->amount), 2),
            round($transactions->filter(fn (Record $transaction) => $transaction->value('type') === 'expense')->sum(fn (Record $transaction) => (float) $transaction->amount), 2),
        ];
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'goals') {
            return [];
        }
        $target = $this->number($record, 'target');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Progress', 'icon' => 'piggy-bank', 'stats' => [
            ['label' => 'Saved', 'value' => $target > 0 ? round($this->number($record, 'saved') / $target * 100).'%' : '—', 'tone' => $record->status === 'reached' ? 'success' : null],
            ['label' => 'Still to save', 'value' => $this->money(max(0, $target - $this->number($record, 'saved')))],
            ['label' => 'Save each month', 'value' => $record->value('_monthly_needed') ? $this->money($this->number($record, '_monthly_needed')) : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        [$income, $spending] = $this->flow(today()->startOfMonth(), today()->endOfDay());
        $debts = $this->records('debts')->where('status', 'active')->get();
        $goals = $this->records('goals')->where('status', 'saving')->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => today()->format('F'), 'icon' => 'piggy-bank', 'stats' => [
                ['label' => 'Income', 'value' => $this->money($income)],
                ['label' => 'Spending', 'value' => $this->money($spending)],
                ['label' => 'Left over', 'value' => $this->money($income - $spending), 'tone' => $income - $spending < 0 ? 'danger' : 'success'],
                ['label' => 'Debt owed', 'value' => $this->money($debts->sum(fn (Record $debt) => $this->number($debt, 'balance')))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Savings goals', 'icon' => 'target', 'empty' => 'No goals you are saving for.',
                'rows' => $goals->map(fn (Record $goal) => [
                    'label' => $goal->title, 'sub' => $goal->value('_monthly_needed') ? $this->money($this->number($goal, '_monthly_needed')).' a month' : null,
                    'value' => round($this->number($goal, 'saved') / max(0.01, $this->number($goal, 'target')) * 100).'%', 'href' => $goal->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $transactions = $this->dated('transactions', $from, $to)->get();
        $months = $this->months($from, $to);
        $income = $this->sumByMonth($transactions->filter(fn (Record $transaction) => $transaction->value('type') === 'income'));
        $spending = $this->sumByMonth($transactions->filter(fn (Record $transaction) => $transaction->value('type') === 'expense'));

        return [
            ['title' => 'Monthly cash flow', 'columns' => ['Month', 'Income', 'Spending', 'Left over'], 'rows' => collect($months)
                ->map(fn (string $label, string $key) => [$label, $this->money($income[$key] ?? 0), $this->money($spending[$key] ?? 0), $this->money(($income[$key] ?? 0) - ($spending[$key] ?? 0))])->values()->all()],
            ['title' => 'Spending by category', 'columns' => ['Category', 'Transactions', 'Spent', 'Share'], 'rows' => $transactions->filter(fn (Record $transaction) => $transaction->value('type') === 'expense')
                ->groupBy(fn (Record $transaction) => ucfirst(str_replace('_', ' ', (string) $transaction->value('category'))))
                ->map(fn ($group, string $category) => [$category, $group->count(), (float) $group->sum(fn (Record $transaction) => (float) $transaction->amount)])
                ->sortByDesc(2)->map(fn (array $row) => [$row[0], $row[1], $this->money($row[2]), array_sum($spending) > 0 ? round($row[2] / array_sum($spending) * 100).'%' : '—'])->values()->all()],
        ];
    }
}
