<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Payment gateways: every gateway transaction works out its fee from the gateway's rate and
 * its net settlement, a gateway reference can only be logged once, disabled gateways take
 * nothing new, and only a successful payment can be refunded. Test gateways stay out of the
 * money totals.
 */
class PaymentGatewaysLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'transactions') {
            return [];
        }

        $data = $payload['data'];
        $gateway = ! empty($data['gateway']) ? $this->records('gateways')->find($data['gateway']) : null;
        $errors = [];

        if ($gateway && $gateway->status === 'disabled' && (! $existing || (int) $existing->value('gateway') !== $gateway->id)) {
            $errors['data.gateway'] = $gateway->title.' is disabled. Switch it back on or pick another gateway.';
        }

        if ($gateway && filled($data['gateway_reference'] ?? null)) {
            $duplicate = $this->linked('transactions', 'gateway', $gateway)->where('data->gateway_reference', $data['gateway_reference'])
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();
            if ($duplicate) {
                $errors['data.gateway_reference'] = 'This gateway reference is already logged as '.$duplicate->number.'.';
            }
        }

        if ($payload['status'] === 'refunded' && (! $existing || ! in_array($existing->status, ['successful', 'refunded'], true))) {
            $errors['status'] = 'Only a successful payment can be refunded.';
        }

        if ((float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Enter the amount paid.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'transactions') {
            return;
        }

        $gateway = $this->parent($record, 'gateway');
        if (($record->value('fee') === null || $record->value('fee') === '') && $gateway && $this->number($gateway, 'fee_percent') > 0) {
            $this->put($record, ['fee' => round((float) $record->amount * $this->number($gateway, 'fee_percent') / 100, 2)]);
        }
        $this->put($record, ['_net' => round((float) $record->amount - $this->number($record, 'fee'), 2)]);
    }

    /** @param  Collection<int, Record>  $transactions  @return array{count: int, collected: float, fees: float, net: float, failed: int, refunded: float} */
    public function totals(Collection $transactions): array
    {
        $successful = $transactions->where('status', 'successful');

        return [
            'count' => $successful->count(),
            'collected' => round((float) $successful->sum('amount'), 2),
            'fees' => round($successful->sum(fn (Record $line) => $this->number($line, 'fee')), 2),
            'net' => round($successful->sum(fn (Record $line) => $this->number($line, '_net')), 2),
            'failed' => $transactions->where('status', 'failed')->count(),
            'refunded' => round((float) $transactions->where('status', 'refunded')->sum('amount'), 2),
        ];
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'gateways') {
            return [];
        }

        $transactions = $this->linked('transactions', 'gateway', $record)->get();
        $totals = $this->totals($transactions);
        $attempts = $totals['count'] + $totals['failed'];

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Collections', 'icon' => 'credit-card', 'stats' => [
            ['label' => 'Collected', 'value' => $this->money($totals['collected'])],
            ['label' => 'Fees', 'value' => $this->money($totals['fees'])],
            ['label' => 'Net settled', 'value' => $this->money($totals['net']), 'tone' => 'success'],
            ['label' => 'Success rate', 'value' => $attempts ? round($totals['count'] / $attempts * 100).'%' : '—', 'tone' => $attempts && $totals['failed'] / $attempts > 0.2 ? 'warning' : null],
        ], 'note' => $record->status === 'test' ? 'Test mode: no real money moves and these payments stay out of the reports.' : null]]];
    }

    public function homeCards(): array
    {
        $live = $this->records('gateways')->whereNot('status', 'test')->pluck('id')->all();
        $today = $this->records('transactions')->whereDate('occurs_on', today())->get()->filter(fn (Record $line) => in_array((int) $line->value('gateway'), $live, true));
        $totals = $this->totals($today);

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'credit-card', 'stats' => [
            ['label' => 'Payments', 'value' => (string) $totals['count']],
            ['label' => 'Collected', 'value' => $this->money($totals['collected'])],
            ['label' => 'Net of fees', 'value' => $this->money($totals['net'])],
            ['label' => 'Failed', 'value' => (string) $totals['failed'], 'tone' => $totals['failed'] ? 'warning' : null],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $transactions = $this->dated('transactions', $from, $to)->get();
        $rows = $this->records('gateways')->whereNot('status', 'test')->orderBy('title')->get()->map(function (Record $gateway) use ($transactions) {
            $totals = $this->totals($transactions->filter(fn (Record $line) => (int) $line->value('gateway') === $gateway->id));

            return [$gateway->title, $totals['count'], $this->money($totals['collected']), $this->money($totals['fees']), $this->money($totals['net']), $totals['failed'], $this->money($totals['refunded'])];
        })->all();

        return [['title' => 'Collections by gateway', 'columns' => ['Gateway', 'Payments', 'Collected', 'Fees', 'Net', 'Failed', 'Refunded'], 'rows' => $rows,
            'note' => 'Test-mode gateways are left out.']];
    }
}
