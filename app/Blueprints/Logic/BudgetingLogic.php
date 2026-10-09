<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Budgeting: each budget line compares what was budgeted with what has happened and flags
 * itself on track, over budget (spending) or under budget (income), and the budget adds up
 * its spending lines. The variance report shows every line side by side.
 */
class BudgetingLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'budgets') {
            return [];
        }

        $start = $payload['data']['period_start'] ?? null;
        $end = $payload['data']['period_end'] ?? null;
        if ($start && $end && Carbon::parse($end)->lt(Carbon::parse($start))) {
            return ['data.period_end' => 'The period has to end on or after the day it starts.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'lines') {
            $record->status = $this->lineStatus($record);
        }

        if ($record->entity === 'budgets' && $record->exists) {
            $record->amount = round((float) $this->linked('lines', 'budget', $record)->get()->where('data.type', 'expense')->sum('amount'), 2);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'lines') {
            $this->recalculate($this->parent($record, 'budget'));
            $this->recalculate($this->previousParent($record, 'budget'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'lines') {
            $this->recalculate($this->parent($record, 'budget'));
        }
    }

    /** Spending above budget is over; income short of budget is under; anything else is on track. */
    public function lineStatus(Record $line): string
    {
        $budgeted = (float) $line->amount;
        $actual = $this->number($line, 'actual');

        if ($line->value('type') === 'income') {
            return $actual < $budgeted ? 'under_budget' : 'on_track';
        }

        return $actual > $budgeted ? 'over_budget' : 'on_track';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'budgets') {
            return [];
        }

        $lines = $this->linked('lines', 'budget', $record)->get();
        $spending = $lines->where('data.type', 'expense');
        $budgeted = (float) $spending->sum('amount');
        $actual = $spending->sum(fn (Record $line) => $this->number($line, 'actual'));
        $used = $budgeted > 0 ? round($actual / $budgeted * 100) : 0;

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Spending against budget', 'icon' => 'trending-up', 'stats' => [
                ['label' => 'Budgeted', 'value' => $this->money($budgeted)],
                ['label' => 'Actual', 'value' => $this->money($actual)],
                ['label' => 'Remaining', 'value' => $this->money($budgeted - $actual), 'tone' => $actual > $budgeted ? 'danger' : 'success'],
                ['label' => 'Used', 'value' => $used.'%', 'tone' => $used > 100 ? 'danger' : ($used >= 90 ? 'warning' : null)],
            ], 'note' => 'Income lines: budgeted '.$this->money($lines->where('data.type', 'income')->sum('amount')).', received '.$this->money($lines->where('data.type', 'income')->sum(fn (Record $line) => $this->number($line, 'actual'))).'.']],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Lines needing attention', 'icon' => 'alert-triangle', 'empty' => 'Every line is on track.',
                'rows' => $lines->whereIn('status', ['over_budget', 'under_budget'])->map(fn (Record $line) => [
                    'label' => $line->title, 'sub' => $line->status === 'over_budget' ? 'Over budget' : 'Income behind budget',
                    'value' => $this->money($this->number($line, 'actual') - (float) $line->amount), 'href' => $line->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $over = $this->records('lines')->where('status', 'over_budget')->orderBy('title')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Over budget', 'icon' => 'alert-triangle', 'empty' => 'No spending line is over budget.',
            'rows' => $over->map(fn (Record $line) => [
                'label' => $line->title, 'sub' => 'Budgeted '.$this->money($line->amount),
                'value' => '+'.$this->money($this->number($line, 'actual') - (float) $line->amount), 'href' => $line->url(), 'tone' => 'danger',
            ])->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $budgets = $this->records('budgets')->whereNot('status', 'draft')->get()
            ->filter(fn (Record $budget) => (! $budget->value('period_end') || Carbon::parse($budget->value('period_end'))->gte($from))
                && (! $budget->value('period_start') || Carbon::parse($budget->value('period_start'))->lte($to)));
        $rows = [];
        foreach ($budgets as $budget) {
            foreach ($this->linked('lines', 'budget', $budget)->orderBy('title')->get() as $line) {
                $actual = $this->number($line, 'actual');
                $rows[] = [
                    $budget->title, $line->title, ucfirst((string) $line->value('type')), $this->money($line->amount), $this->money($actual),
                    $this->money($actual - (float) $line->amount), $line->value('forecast') !== null && $line->value('forecast') !== '' ? $this->money($line->value('forecast')) : '—',
                ];
            }
        }

        return [['title' => 'Budget against actual', 'columns' => ['Budget', 'Line', 'Type', 'Budgeted', 'Actual', 'Variance', 'Forecast'], 'rows' => $rows,
            'note' => 'Approved and closed budgets whose period overlaps the dates chosen. A positive variance on spending means over budget.']];
    }
}
