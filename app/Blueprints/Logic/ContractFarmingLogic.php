<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Contract farming: grower numbers are unique; a delivery cannot deduct more than the grower
 * still owes on their input loan or more than the delivery is worth; each grower adds up
 * kilograms delivered, gross value, loan recovered and what is left to recover.
 */
class ContractFarmingLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'growers' && filled($data['grower_number'] ?? null)
            && $this->records('growers')->where('data->grower_number', $data['grower_number'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            return ['data.grower_number' => 'Grower number '.$data['grower_number'].' is already taken.'];
        }

        if ($entity->key !== 'deliveries' || empty($data['grower'])) {
            return [];
        }

        $grower = $this->records('growers')->find($data['grower']);
        if (! $grower) {
            return [];
        }
        $same = $existing && (int) $existing->value('grower') === $grower->id;
        if (! $same && in_array($grower->status, ['suspended', 'exited'], true)) {
            return ['data.grower' => $grower->title.' is '.$grower->status.'.'];
        }

        $deduction = (float) ($data['loan_deduction'] ?? 0);
        $balance = round($this->loanBalance($grower) + ($same ? $this->number($existing, 'loan_deduction') : 0), 2);
        if ($deduction - $balance > 0.004) {
            return ['data.loan_deduction' => $grower->title.' only owes '.$this->money($balance).' on the input loan.'];
        }
        if ($deduction > 0 && filled($payload['amount'] ?? null) && $deduction - (float) $payload['amount'] > 0.004) {
            return ['data.loan_deduction' => 'The deduction is more than the delivery is worth.'];
        }

        return [];
    }

    public function loanBalance(Record $grower): float
    {
        $recovered = $this->linked('deliveries', 'grower', $grower)->get()->sum(fn (Record $delivery) => $this->number($delivery, 'loan_deduction'));

        return max(0, round($this->number($grower, 'input_loan') - $recovered, 2));
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'deliveries') {
            $this->put($record, ['_net' => round((float) $record->amount - $this->number($record, 'loan_deduction'), 2)]);
        }

        if ($record->entity === 'growers' && $record->exists) {
            $deliveries = $this->linked('deliveries', 'grower', $record)->get();
            $recovered = $deliveries->sum(fn (Record $delivery) => $this->number($delivery, 'loan_deduction'));
            $kg = $deliveries->sum(fn (Record $delivery) => $this->number($delivery, 'weight'));
            $this->put($record, [
                '_delivered' => round($kg, 1), '_gross' => round((float) $deliveries->sum('amount'), 2), '_recovered' => round($recovered, 2),
                '_loan_balance' => max(0, round($this->number($record, 'input_loan') - $recovered, 2)),
                '_yield_per_ha' => $this->number($record, 'hectares') > 0 ? round($kg / $this->number($record, 'hectares'), 1) : null,
            ]);
            if ($deliveries->isNotEmpty() && $record->status === 'contracted') {
                $record->status = 'active';
            }
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'deliveries') {
            $this->recalculate($this->parent($record, 'grower'));
            $this->recalculate($this->previousParent($record, 'grower'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'deliveries') {
            $this->recalculate($this->parent($record, 'grower'));
        }
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'deliveries' ? ['slip' => 'Grower payment slip'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'slip' || $record->entity !== 'deliveries') {
            return null;
        }

        $grower = $this->parent($record, 'grower');

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Grower payment slip',
            'meta' => array_filter(['Grower' => $grower ? $grower->title.' ('.$grower->value('grower_number').')' : null, 'Delivery note' => $record->title, 'Delivered on' => $record->occurs_on?->format('d M Y')]),
            'columns' => ['Crop', 'Weight (kg)', 'Grade', 'Gross value'],
            'rows' => [[(string) ($grower?->value('crop') ?? '—'), $this->number($record, 'weight'), (string) ($record->value('grade') ?? '—'), $this->money($record->amount)]],
            'totals' => [
                'Gross value' => $this->money($record->amount),
                'Input loan deduction' => $this->money($record->value('loan_deduction')),
                'Net payable' => $this->money($record->value('_net')),
                'Loan still owed' => $grower ? $this->money($grower->value('_loan_balance')) : '—',
            ],
        ]];
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'growers') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Grower account', 'icon' => 'handshake', 'stats' => [
            ['label' => 'Delivered', 'value' => number_format($this->number($record, '_delivered'), 1).' kg'],
            ['label' => 'Gross value', 'value' => $this->money($record->value('_gross'))],
            ['label' => 'Loan recovered', 'value' => $this->money($record->value('_recovered')).' of '.$this->money($record->value('input_loan'))],
            ['label' => 'Loan balance', 'value' => $this->money($record->value('_loan_balance')), 'tone' => $this->number($record, '_loan_balance') > 0 ? 'warning' : 'success'],
        ]]]];
    }

    public function homeCards(): array
    {
        $growers = $this->records('growers')->whereIn('status', ['contracted', 'active'])->get();
        $loans = $growers->sum(fn (Record $grower) => $this->number($grower, 'input_loan'));
        $recovered = $growers->sum(fn (Record $grower) => $this->number($grower, '_recovered'));

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Scheme', 'icon' => 'handshake', 'stats' => [
            ['label' => 'Growers', 'value' => (string) $growers->count()],
            ['label' => 'Hectares', 'value' => (string) round($growers->sum(fn (Record $grower) => $this->number($grower, 'hectares')), 1)],
            ['label' => 'Delivered', 'value' => number_format($growers->sum(fn (Record $grower) => $this->number($grower, '_delivered'))).' kg'],
            ['label' => 'Loans recovered', 'value' => $loans > 0 ? round($recovered / $loans * 100).'%' : '—'],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $deliveries = $this->dated('deliveries', $from, $to)->get();
        $growers = $this->records('growers')->orderBy('title')->get()->map(function (Record $grower) use ($deliveries) {
            $mine = $deliveries->where('data.grower', $grower->id);

            return [(string) $grower->value('grower_number'), $grower->title, number_format($mine->sum(fn (Record $delivery) => $this->number($delivery, 'weight')), 1),
                $this->money($mine->sum('amount')), $this->money($mine->sum(fn (Record $delivery) => $this->number($delivery, 'loan_deduction'))), $this->money($grower->value('_loan_balance'))];
        })->all();

        return [['title' => 'Grower deliveries', 'columns' => ['Number', 'Grower', 'Kg', 'Gross', 'Loan recovered', 'Loan balance'], 'rows' => $growers]];
    }
}
