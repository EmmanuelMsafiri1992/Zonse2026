<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Architecture / engineering practice: fees invoiced never exceed the total fee, time is logged only
 * on appointed commissions and in sensible amounts, and billed time is locked. Commissions track
 * their hours and fee position, move through the work stages one click at a time, and cannot be
 * completed with time still unbilled.
 */
class PracticeManagementLogic extends AppLogic
{
    public const STAGES = ['inception', 'concept', 'design_development', 'documentation', 'tender', 'construction', 'close_out'];

    public const WORKING = ['appointed', 'in_progress'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'commissions') {
            if ($payload['amount'] !== null && (float) ($data['fee_invoiced'] ?? 0) > (float) $payload['amount'] + 0.005) {
                $errors['data.fee_invoiced'] = 'Fees invoiced cannot exceed the total fee of '.$this->money($payload['amount']).'.';
            }
            if ($payload['status'] === 'completed' && $existing && $this->linked('timesheets', 'commission', $existing)->where('status', 'logged')->exists()) {
                $errors['status'] = 'Bill the unbilled time before completing the commission.';
            }

            return $errors;
        }

        $commission = ! empty($data['commission']) ? $this->records('commissions')->find($data['commission']) : null;
        if ($commission && ! in_array($commission->status, self::WORKING, true) && (! $existing || (int) $existing->value('commission') !== $commission->id)) {
            $errors['data.commission'] = 'Time can only be logged on appointed commissions ('.$commission->title.' is '.str_replace('_', ' ', $commission->status).').';
        }
        $hours = (float) ($data['hours'] ?? 0);
        if ($hours <= 0 || $hours > 24) {
            $errors['data.hours'] = 'Log between 0 and 24 hours.';
        }
        if ($existing?->status === 'billed' && ((float) $existing->value('hours') !== $hours || $payload['status'] !== 'billed')) {
            $errors['data.hours'] = 'This time is already billed.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'timesheets') {
            $record->occurs_on ??= today();
            if (blank($record->value('stage'))) {
                $this->put($record, ['stage' => $this->parent($record, 'commission')?->value('stage')]);
            }

            return;
        }

        $time = $record->exists ? $this->linked('timesheets', 'commission', $record)->get() : collect();
        $invoiced = $this->number($record, 'fee_invoiced');
        $this->put($record, [
            '_hours' => (float) $time->sum(fn (Record $entry) => $this->number($entry, 'hours')),
            '_unbilled_hours' => (float) $time->where('status', 'logged')->sum(fn (Record $entry) => $this->number($entry, 'hours')),
            '_fee_remaining' => $record->amount !== null ? round((float) $record->amount - $invoiced, 2) : null,
            '_invoiced_pct' => $record->amount > 0 ? round($invoiced / (float) $record->amount * 100) : 0,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'timesheets') {
            $this->recalculate($this->parent($record, 'commission'));
            $this->recalculate($this->previousParent($record, 'commission'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'timesheets') {
            $this->recalculate($this->parent($record, 'commission'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'commissions' || ! in_array($record->status, self::WORKING, true)) {
            return [];
        }

        $actions = [];
        $next = $this->nextStage($record);
        if ($next) {
            $actions['advance_stage'] = ['label' => 'Move to '.str_replace('_', ' ', $next), 'icon' => 'skip-forward'];
        }
        if ((float) $record->value('_unbilled_hours') > 0) {
            $actions['bill_time'] = ['label' => 'Bill time', 'icon' => 'receipt', 'fields' => [
                ['name' => 'fee_invoiced', 'label' => 'Fee invoiced to date', 'type' => 'number', 'value' => $this->number($record, 'fee_invoiced')],
            ]];
        }

        return $actions;
    }

    protected function nextStage(Record $record): ?string
    {
        $index = array_search($record->value('stage'), self::STAGES, true);

        return $index === false ? self::STAGES[0] : (self::STAGES[$index + 1] ?? null);
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'advance_stage') {
            $next = $this->nextStage($record) ?? 'close_out';
            $record->update(['status' => 'in_progress', 'data' => [...(array) $record->data, 'stage' => $next]]);

            return $record->title.' is now at '.str_replace('_', ' ', $next).'.';
        }

        $invoiced = (float) $request->validate(['fee_invoiced' => ['required', 'numeric', 'min:0', 'max:'.($record->amount ?? PHP_FLOAT_MAX)]])['fee_invoiced'];
        $billed = 0.0;
        foreach ($this->linked('timesheets', 'commission', $record)->where('status', 'logged')->get() as $entry) {
            $entry->update(['status' => 'billed']);
            $billed += $this->number($entry, 'hours');
        }
        $record->refresh();
        $record->update(['data' => [...(array) $record->data, 'fee_invoiced' => $invoiced]]);

        return $billed.' hours billed; fees invoiced to date '.$this->money($invoiced).'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'commissions') {
            return [];
        }

        $time = $this->linked('timesheets', 'commission', $record)->get();
        $byStage = collect(self::STAGES)->map(fn (string $stage) => ['stage' => $stage, 'hours' => (float) $time->where('data.stage', $stage)->sum(fn (Record $entry) => $this->number($entry, 'hours'))])->where('hours', '>', 0);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Fees & time', 'icon' => 'drafting-compass', 'stats' => [
                ['label' => 'Total fee', 'value' => $record->amount !== null ? $this->money($record->amount) : '—'],
                ['label' => 'Invoiced', 'value' => $this->money($record->value('fee_invoiced')).' ('.(int) $record->value('_invoiced_pct').'%)'],
                ['label' => 'Remaining', 'value' => $record->value('_fee_remaining') !== null ? $this->money($record->value('_fee_remaining')) : '—'],
                ['label' => 'Hours', 'value' => (string) (float) $record->value('_hours')],
                ['label' => 'Unbilled hours', 'value' => (string) (float) $record->value('_unbilled_hours'), 'tone' => (float) $record->value('_unbilled_hours') > 0 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Hours by stage', 'icon' => 'layers', 'empty' => 'No time logged yet.',
                'rows' => $byStage->map(fn (array $row) => [
                    'label' => ucfirst(str_replace('_', ' ', $row['stage'])), 'value' => $row['hours'].' h', 'tone' => $row['stage'] === $record->value('stage') ? 'success' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $commissions = $this->records('commissions')->with('contact')->get();
        $active = $commissions->whereIn('status', self::WORKING);
        $month = $this->dated('timesheets', today()->startOfMonth(), today()->endOfMonth())->get();
        $toInvoice = $active->filter(fn (Record $commission) => (float) $commission->value('_fee_remaining') > 0)->sortByDesc(fn (Record $commission) => (float) $commission->value('_fee_remaining'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Practice', 'icon' => 'drafting-compass', 'stats' => [
                ['label' => 'Active commissions', 'value' => (string) $active->count()],
                ['label' => 'Fees still to invoice', 'value' => $this->money($toInvoice->sum(fn (Record $commission) => (float) $commission->value('_fee_remaining')))],
                ['label' => 'Hours this month', 'value' => (string) (float) $month->sum(fn (Record $entry) => $this->number($entry, 'hours'))],
                ['label' => 'Unbilled hours', 'value' => (string) (float) $active->sum(fn (Record $commission) => (float) $commission->value('_unbilled_hours'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Fees to invoice', 'icon' => 'receipt', 'empty' => 'Every active commission is fully invoiced.',
                'rows' => $toInvoice->take(10)->map(fn (Record $commission) => [
                    'label' => $commission->title, 'sub' => trim(($commission->contact?->name ?? '').' · '.str_replace('_', ' ', (string) $commission->value('stage')), ' ·'),
                    'value' => $this->money($commission->value('_fee_remaining')), 'href' => $commission->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $commissions = $this->records('commissions')->with('contact')->get();
        $clients = $commissions->groupBy(fn (Record $commission) => $commission->contact?->name ?? 'No client')->sortKeys()->map(fn ($group, $client) => [
            $client, $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $commission) => $this->number($commission, 'fee_invoiced'))),
            $this->money($group->sum(fn (Record $commission) => (float) $commission->value('_fee_remaining'))),
        ])->values()->all();

        $time = $this->dated('timesheets', $from, $to)->get();
        $stages = collect(self::STAGES)->map(fn (string $stage) => [
            ucfirst(str_replace('_', ' ', $stage)), (float) $time->where('data.stage', $stage)->sum(fn (Record $entry) => $this->number($entry, 'hours')),
            (float) $time->where('data.stage', $stage)->where('status', 'logged')->sum(fn (Record $entry) => $this->number($entry, 'hours')),
        ])->all();

        return [
            ['title' => 'Fees by client', 'columns' => ['Client', 'Commissions', 'Total fees', 'Invoiced', 'Remaining'], 'rows' => $clients],
            ['title' => 'Hours by stage', 'columns' => ['Stage', 'Hours', 'Unbilled'], 'rows' => $stages],
        ];
    }
}
