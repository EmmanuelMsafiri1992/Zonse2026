<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Personal finance: a budget names a month and one of the spending categories, and its
 * "spent so far" is added up from that month's expenses in the category every time a
 * transaction changes; the home page shows the month's income, spending and any budget
 * that has gone over its limit.
 */
class PersonalFinanceLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'budgets' && filled($payload['data']['category'] ?? null) && ! $this->categoryKey((string) $payload['data']['category'])) {
            return ['data.category' => 'Use one of the transaction categories: '.implode(', ', $this->categories()).'.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'budgets') {
            return;
        }

        $category = $this->categoryKey((string) $record->value('category')) ?? $record->value('category');
        $month = $this->monthOf($record);
        $this->put($record, ['category' => $category, '_month' => $month->format('Y-m'), 'spent' => $this->spent((string) $category, $month)]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'transactions') {
            $categories = array_filter([$record->value('category'), ((array) $record->getOriginal('data'))['category'] ?? null]);
            $this->budgetsFor($categories)->each(fn (Record $budget) => $budget->save());
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'transactions') {
            $this->budgetsFor([$record->value('category')])->each(fn (Record $budget) => $budget->save());
        }
    }

    /** @param  list<mixed>  $categories */
    protected function budgetsFor(array $categories)
    {
        return $this->records('budgets')->get()->filter(fn (Record $budget) => in_array($budget->value('category'), $categories, true));
    }

    /** @return array<string, string> key => label */
    public function categories(): array
    {
        return $this->app()->entity('transactions')?->field('category')?->options ?? [];
    }

    /** "School fees", "school_fees" or "SCHOOL FEES" → school_fees. */
    public function categoryKey(string $category): ?string
    {
        $wanted = str_replace([' ', '-'], '_', strtolower(trim($category)));
        foreach ($this->categories() as $key => $label) {
            if ($wanted === $key || $wanted === str_replace([' ', '-'], '_', strtolower($label))) {
                return $key;
            }
        }

        return null;
    }

    /** The month a budget covers, from its title ("October 2026", "2026-10"), else when it was added. */
    public function monthOf(Record $budget): Carbon
    {
        try {
            return Carbon::parse((string) $budget->title)->startOfMonth();
        } catch (Throwable) {
            return ($budget->created_at ?? now())->copy()->startOfMonth();
        }
    }

    public function spent(string $category, Carbon $month): float
    {
        return round((float) $this->records('transactions')->where('data->type', 'expense')->where('data->category', $category)
            ->whereBetween('occurs_on', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])->sum('amount'), 2);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'budgets') {
            return [];
        }

        $limit = $this->number($record, 'limit');
        $spent = $this->number($record, 'spent');
        $over = $limit > 0 && $spent > $limit;

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => Carbon::parse($record->value('_month').'-01')->format('F Y'), 'icon' => 'target', 'stats' => [
            ['label' => 'Limit', 'value' => $this->money($limit)],
            ['label' => 'Spent', 'value' => $this->money($spent), 'tone' => $over ? 'danger' : null],
            ['label' => $over ? 'Over by' : 'Left', 'value' => $this->money(abs($limit - $spent)), 'tone' => $over ? 'danger' : 'success'],
            ['label' => 'Used', 'value' => $limit > 0 ? round($spent / $limit * 100).'%' : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $month = $this->records('transactions')->whereBetween('occurs_on', [today()->startOfMonth(), today()->endOfMonth()])->get();
        $income = (float) $month->where('data.type', 'income')->sum('amount');
        $spending = (float) $month->where('data.type', 'expense')->sum('amount');
        $over = $this->records('budgets')->where('status', 'active')->get()
            ->filter(fn (Record $budget) => $this->number($budget, 'limit') > 0 && $this->number($budget, 'spent') > $this->number($budget, 'limit'));

        $cards = [['view' => 'apps.logic.stats-card', 'data' => ['title' => today()->format('F Y'), 'icon' => 'wallet', 'stats' => [
            ['label' => 'Income', 'value' => $this->money($income), 'tone' => 'success'],
            ['label' => 'Spending', 'value' => $this->money($spending)],
            ['label' => 'Left over', 'value' => $this->money($income - $spending), 'tone' => $income - $spending < 0 ? 'danger' : null],
        ]]]];

        foreach ($over as $budget) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'danger', 'icon' => 'triangle-alert',
                'title' => ($this->categories()[$budget->value('category')] ?? $budget->value('category')).' is over budget',
                'body' => $this->money($budget->value('spent')).' spent against a '.$this->money($budget->value('limit')).' limit for '.$budget->title.'.']];
        }

        return $cards;
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $transactions = $this->dated('transactions', $from, $to)->get();
        $income = $this->sumByMonth($transactions->where('data.type', 'income'));
        $spending = $this->sumByMonth($transactions->where('data.type', 'expense'));
        $monthly = collect($this->months($from, $to))->map(fn ($label, $month) => [$label, $this->money($income[$month] ?? 0), $this->money($spending[$month] ?? 0), $this->money(($income[$month] ?? 0) - ($spending[$month] ?? 0))])->values()->all();

        $expenses = $transactions->where('data.type', 'expense');
        $total = (float) $expenses->sum('amount');
        $byCategory = $expenses->groupBy(fn (Record $transaction) => (string) $transaction->value('category'))->map(fn ($group) => (float) $group->sum('amount'))->sortDesc()
            ->map(fn ($amount, $category) => [$this->categories()[$category] ?? $category, $this->money($amount), $total > 0 ? round($amount / $total * 100).'%' : '—'])->values()->all();

        return [
            ['title' => 'Income and spending by month', 'columns' => ['Month', 'Income', 'Spending', 'Net'], 'rows' => $monthly],
            ['title' => 'Spending by category', 'columns' => ['Category', 'Spent', 'Share'], 'rows' => $byCategory],
        ];
    }
}
