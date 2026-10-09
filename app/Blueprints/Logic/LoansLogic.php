<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Loans & microfinance: flat monthly interest on the principal, repaid in equal monthly
 * instalments from a month after disbursement. Each loan tracks paid, balance and arrears;
 * it falls into arrears when payments are behind schedule and is repaid when the balance clears.
 */
class LoansLogic extends AppLogic
{
    public const LIVE = ['disbursed', 'in_arrears'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'loans' && in_array($payload['status'], [...self::LIVE, 'repaid'], true)) {
            if ((float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Enter the principal.';
            }
            if ((float) ($data['term_months'] ?? 0) < 1) {
                $errors['data.term_months'] = 'Set the term in months.';
            }
            if (blank($payload['occurs_on'] ?? null)) {
                $errors['occurs_on'] = 'Set the disbursement date.';
            }
        }

        if ($entity->key === 'repayments' && $payload['status'] === 'received' && ! empty($data['loan'])) {
            $loan = $this->records('loans')->find($data['loan']);
            if ($loan) {
                $same = $existing && $existing->status === 'received' && (int) $existing->value('loan') === $loan->id;
                if (! $same && ! in_array($loan->status, self::LIVE, true)) {
                    $errors['data.loan'] = $loan->title.'\'s loan is '.str_replace('_', ' ', $loan->status).', so it takes no repayments.';
                } else {
                    $balance = round($this->balance($loan) + ($same ? (float) $existing->amount : 0), 2);
                    if ((float) ($payload['amount'] ?? 0) - $balance > 0.004) {
                        $errors['amount'] = 'Only '.$this->money($balance).' is left to pay.';
                    }
                    if ((float) ($payload['amount'] ?? 0) <= 0) {
                        $errors['amount'] = 'Enter the amount received.';
                    }
                }
            }
        }

        return $errors;
    }

    public function totalDue(Record $loan): float
    {
        return round((float) $loan->amount * (1 + $this->number($loan, 'interest_rate') / 100 * $this->number($loan, 'term_months')), 2);
    }

    public function instalment(Record $loan): float
    {
        return $this->number($loan, 'term_months') > 0 ? round($this->totalDue($loan) / $this->number($loan, 'term_months'), 2) : 0.0;
    }

    public function paid(Record $loan): float
    {
        return $loan->exists ? round((float) $this->linked('repayments', 'loan', $loan)->where('status', 'received')->sum('amount'), 2) : 0.0;
    }

    public function balance(Record $loan): float
    {
        return max(0, round($this->totalDue($loan) - $this->paid($loan), 2));
    }

    /** Instalments that have fallen due by a date. */
    public function instalmentsDue(Record $loan, Carbon $asOf): int
    {
        if (! $loan->occurs_on) {
            return 0;
        }

        $count = 0;
        for ($n = 1; $n <= (int) $this->number($loan, 'term_months'); $n++) {
            if ($loan->occurs_on->copy()->addMonthsNoOverflow($n)->gt($asOf)) {
                break;
            }
            $count++;
        }

        return $count;
    }

    public function arrears(Record $loan, ?Carbon $asOf = null): float
    {
        $count = $this->instalmentsDue($loan, $asOf ?? today());
        $expected = $count >= (int) $this->number($loan, 'term_months') ? $this->totalDue($loan) : $this->instalment($loan) * $count;

        return max(0, round($expected - $this->paid($loan), 2));
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'loans') {
            return;
        }

        if ($record->occurs_on && $this->number($record, 'term_months') > 0 && ! $record->due_on) {
            $record->due_on = $record->occurs_on->copy()->addMonthsNoOverflow((int) $this->number($record, 'term_months'));
        }

        $balance = $this->balance($record);
        $arrears = $this->arrears($record);
        $next = $this->instalmentsDue($record, today()) < (int) $this->number($record, 'term_months') && $record->occurs_on
            ? $record->occurs_on->copy()->addMonthsNoOverflow($this->instalmentsDue($record, today()) + 1)->toDateString() : null;
        $this->put($record, ['_total_due' => $this->totalDue($record), '_instalment' => $this->instalment($record), '_paid' => $this->paid($record),
            '_balance' => $balance, '_arrears' => $arrears, '_next_due' => $next]);

        if (in_array($record->status, [...self::LIVE, 'repaid'], true)) {
            $record->status = match (true) {
                $balance <= 0.004 && $this->paid($record) > 0 => 'repaid',
                $arrears > 0.004 => 'in_arrears',
                default => 'disbursed',
            };
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'repayments') {
            $this->recalculate($this->parent($record, 'loan'));
            $this->recalculate($this->previousParent($record, 'loan'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'repayments') {
            $this->recalculate($this->parent($record, 'loan'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('loans')->whereIn('status', self::LIVE)->get() as $loan) {
            $before = $loan->status;
            $loan->save();
            $changed += $loan->status !== $before ? 1 : 0;
        }

        return $changed;
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'loans' && $record->occurs_on ? ['statement' => 'Loan statement'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'statement' || $record->entity !== 'loans') {
            return null;
        }

        $payments = $this->linked('repayments', 'loan', $record)->where('status', 'received')->orderBy('occurs_on')->get();
        $rows = [];
        $owing = $this->totalDue($record);
        for ($n = 1; $n <= (int) $this->number($record, 'term_months'); $n++) {
            $rows[] = ['Instalment '.$n, $record->occurs_on->copy()->addMonthsNoOverflow($n)->format('d M Y'), $this->money($this->instalment($record)), ''];
        }
        foreach ($payments as $payment) {
            $owing -= (float) $payment->amount;
            $rows[] = ['Payment '.$payment->title, $payment->occurs_on?->format('d M Y') ?? '—', '', $this->money($payment->amount)];
        }

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Loan statement '.$record->number,
            'meta' => array_filter(['Borrower' => $record->contact?->name ?? $record->title, 'Product' => ucfirst(str_replace('_', ' ', (string) $record->value('product'))),
                'Disbursed' => $record->occurs_on?->format('d M Y'), 'Matures' => $record->due_on?->format('d M Y'),
                'Interest' => $this->number($record, 'interest_rate').'% per month flat']),
            'columns' => ['Item', 'Date', 'Due', 'Paid'],
            'rows' => $rows,
            'totals' => ['Principal' => $this->money($record->amount), 'Total repayable' => $this->money($this->totalDue($record)), 'Paid' => $this->money($this->paid($record)),
                'Balance' => $this->money(max(0, $owing)), 'In arrears' => $this->money($this->arrears($record))],
        ]];
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'loans' || ! in_array($record->status, [...self::LIVE, 'repaid'], true)) {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Repayment', 'icon' => 'coins', 'stats' => [
            ['label' => 'Monthly instalment', 'value' => $this->money($record->value('_instalment'))],
            ['label' => 'Paid', 'value' => $this->money($record->value('_paid')).' of '.$this->money($record->value('_total_due'))],
            ['label' => 'Balance', 'value' => $this->money($record->value('_balance'))],
            ['label' => 'In arrears', 'value' => $this->money($record->value('_arrears')), 'tone' => $this->number($record, '_arrears') > 0 ? 'danger' : 'success'],
        ]]]];
    }

    public function homeCards(): array
    {
        $live = $this->records('loans')->whereIn('status', self::LIVE)->get();
        $outstanding = $live->sum(fn (Record $loan) => $this->number($loan, '_balance'));
        $late = $live->where('status', 'in_arrears');
        $atRisk = $late->sum(fn (Record $loan) => $this->number($loan, '_balance'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Loan book', 'icon' => 'piggy-bank', 'stats' => [
                ['label' => 'Active loans', 'value' => (string) $live->count()],
                ['label' => 'Outstanding', 'value' => $this->money($outstanding)],
                ['label' => 'Portfolio at risk', 'value' => $outstanding > 0 ? round($atRisk / $outstanding * 100, 1).'%' : '0%', 'tone' => $atRisk > 0 ? 'danger' : 'success'],
                ['label' => 'Applications', 'value' => (string) $this->records('loans')->whereIn('status', ['application', 'approved'])->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'In arrears', 'icon' => 'alarm-clock', 'empty' => 'No loans are behind.',
                'rows' => $late->sortByDesc(fn (Record $loan) => $this->number($loan, '_arrears'))->map(fn (Record $loan) => [
                    'label' => $loan->title, 'sub' => 'Balance '.$this->money($loan->value('_balance')), 'value' => $this->money($loan->value('_arrears')), 'href' => $loan->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $book = $this->records('loans')->whereIn('status', [...self::LIVE, 'repaid'])->orderBy('title')->get()->map(fn (Record $loan) => [
            $loan->number, $loan->title, ucfirst(str_replace('_', ' ', (string) $loan->value('product'))), $this->money($loan->amount),
            $this->money($loan->value('_balance')), $this->money($loan->value('_arrears')), ucfirst(str_replace('_', ' ', $loan->status)), $loan->due_on?->format('d M Y') ?? '—',
        ])->all();

        $collected = $this->sumByMonth($this->dated('repayments', $from, $to)->where('status', 'received')->get());
        $disbursed = $this->sumByMonth($this->dated('loans', $from, $to)->whereIn('status', [...self::LIVE, 'repaid', 'written_off'])->get());
        $months = collect($this->months($from, $to))->map(fn ($label, $month) => [$label, $this->money($disbursed[$month] ?? 0), $this->money($collected[$month] ?? 0)])->values()->all();

        return [
            ['title' => 'Loan book', 'columns' => ['Loan', 'Borrower', 'Product', 'Principal', 'Balance', 'Arrears', 'Status', 'Matures'], 'rows' => $book],
            ['title' => 'Disbursed and collected', 'columns' => ['Month', 'Disbursed', 'Collected'], 'rows' => $months],
        ];
    }
}
