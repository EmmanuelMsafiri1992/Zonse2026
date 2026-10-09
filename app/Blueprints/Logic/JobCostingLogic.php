<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Job costing: each job adds up the costs booked to it against its budget and works out its
 * margin on the revenue (or, until there is revenue, the quoted price). Closed jobs take no
 * more costs, and billable costs can be invoiced to the client at cost in one step.
 */
class JobCostingLogic extends AppLogic
{
    public const TYPES = ['labour' => 'Labour', 'materials' => 'Materials', 'subcontract' => 'Subcontract', 'equipment' => 'Equipment', 'travel' => 'Travel', 'overhead' => 'Overhead'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'costs' || empty($payload['data']['job'])) {
            return [];
        }

        $job = $this->records('jobs')->find($payload['data']['job']);
        $moving = ! $existing || (int) $existing->value('job') !== (int) $payload['data']['job'];
        if ($job && $job->status === 'closed' && $moving) {
            return ['data.job' => $job->number.' is closed. Reopen it to book more costs.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'jobs' && $record->exists) {
            $costs = $this->linked('costs', 'job', $record)->get();
            $this->put($record, [
                '_actual_cost' => round((float) $costs->sum('amount'), 2),
                '_unbilled' => round((float) $costs->where('status', 'recorded')->filter(fn (Record $cost) => (bool) $cost->value('billable'))->sum('amount'), 2),
            ]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'costs') {
            $this->recalculate($this->parent($record, 'job'));
            $this->recalculate($this->previousParent($record, 'job'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'costs') {
            $this->recalculate($this->parent($record, 'job'));
        }
    }

    /** Revenue, or the quoted price while nothing has been earned yet. */
    public function revenue(Record $job): float
    {
        return (float) $job->amount > 0 ? (float) $job->amount : $this->number($job, 'quoted_price');
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'jobs' && $this->number($record, '_unbilled') > 0 && $this->billing()->available()) {
            return ['invoice_costs' => ['label' => 'Invoice billable costs', 'icon' => 'receipt', 'confirm' => 'Invoice '.$this->money($record->value('_unbilled')).' of billable costs to the client at cost?']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $costs = $this->linked('costs', 'job', $record)->where('status', 'recorded')->get()->filter(fn (Record $cost) => (bool) $cost->value('billable'))->values();
        $invoice = $this->billing()->invoice($record, $costs->map(fn (Record $cost) => [
            'description' => (self::TYPES[$cost->value('type')] ?? 'Cost').': '.$cost->title, 'quantity' => 1, 'unit_price' => (float) $cost->amount,
        ])->all(), 'costs-'.now()->format('YmdHis'));

        $costs->each(fn (Record $cost) => $cost->update(['status' => 'billed', 'data' => array_merge((array) $cost->data, ['_invoice' => $invoice->id])]));

        return 'Invoice '.$invoice->number.' raised for '.$costs->count().' cost(s).';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'jobs') {
            return [];
        }

        $budget = $this->number($record, 'budget_cost');
        $actual = $this->number($record, '_actual_cost');
        $revenue = $this->revenue($record);
        $margin = $revenue - $actual;
        $costs = $this->linked('costs', 'job', $record)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Job cost', 'icon' => 'pie-chart', 'stats' => [
                ['label' => 'Budgeted cost', 'value' => $this->money($budget)],
                ['label' => 'Actual cost', 'value' => $this->money($actual), 'tone' => $budget > 0 && $actual > $budget ? 'danger' : null],
                ['label' => (float) $record->amount > 0 ? 'Revenue' : 'Quoted price', 'value' => $this->money($revenue)],
                ['label' => 'Margin', 'value' => $this->money($margin).($revenue > 0 ? ' ('.round($margin / $revenue * 100).'%)' : ''), 'tone' => $margin < 0 ? 'danger' : 'success'],
            ], 'note' => $this->number($record, '_unbilled') > 0 ? $this->money($record->value('_unbilled')).' of billable costs not yet invoiced.' : null]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Cost by type', 'icon' => 'receipt', 'empty' => 'No costs booked yet.',
                'rows' => $costs->groupBy(fn (Record $cost) => (string) $cost->value('type'))->map(fn ($group, $type) => [
                    'label' => self::TYPES[$type] ?? 'Other', 'sub' => $group->count().' cost(s)', 'value' => $this->money($group->sum('amount')),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $over = $this->records('jobs')->whereIn('status', ['open', 'in_progress'])->get()
            ->filter(fn (Record $job) => $this->number($job, 'budget_cost') > 0 && $this->number($job, '_actual_cost') > $this->number($job, 'budget_cost'));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Jobs over budget', 'icon' => 'alert-triangle', 'empty' => 'Every open job is within budget.',
            'rows' => $over->map(fn (Record $job) => [
                'label' => $job->title, 'sub' => 'Budget '.$this->money($job->value('budget_cost')),
                'value' => '+'.$this->money($this->number($job, '_actual_cost') - $this->number($job, 'budget_cost')), 'href' => $job->url(), 'tone' => 'danger',
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $jobs = $this->records('jobs')->orderBy('title')->get()->map(function (Record $job) {
            $actual = $this->number($job, '_actual_cost');
            $revenue = $this->revenue($job);

            return [$job->number, $job->title, $this->money($job->value('budget_cost')), $this->money($actual), $this->money($revenue), $this->money($revenue - $actual), $revenue > 0 ? round(($revenue - $actual) / $revenue * 100).'%' : '—'];
        })->all();
        $costs = $this->dated('costs', $from, $to)->get();

        return [
            ['title' => 'Job profitability', 'columns' => ['Job', 'Name', 'Budget', 'Actual cost', 'Revenue', 'Margin', 'Margin %'], 'rows' => $jobs, 'note' => 'Jobs without revenue yet use their quoted price.'],
            ['title' => 'Costs by type', 'columns' => ['Type', 'Costs', 'Total'], 'rows' => $costs->groupBy(fn (Record $cost) => (string) $cost->value('type'))
                ->map(fn ($group, $type) => [self::TYPES[$type] ?? 'Other', $group->count(), $this->money($group->sum('amount'))])->values()->all()],
        ];
    }
}
