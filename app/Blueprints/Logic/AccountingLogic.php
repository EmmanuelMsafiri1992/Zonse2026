<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Accounting: a double-entry ledger. Posted journal entries move money from one account to
 * another, accounts carry their running balance, and the books give a trial balance, a
 * profit and loss and a balance sheet. Mistakes are reversed, never edited away.
 */
class AccountingLogic extends AppLogic
{
    /** Journal statuses that count in the books (a reversed entry still counts; its reversal cancels it). */
    public const COUNTED = ['posted', 'reversed'];

    public const DEBIT_NORMAL = ['asset', 'expense'];

    public const TYPES = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'accounts' && filled($data['code'] ?? null)) {
            $taken = $this->records('accounts')->where('data->code', $data['code'])
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();

            return $taken ? ['data.code' => 'Another account already uses code '.$data['code'].'.'] : [];
        }

        if ($entity->key === 'journals') {
            $errors = [];
            if (! empty($data['debit_account']) && (int) $data['debit_account'] === (int) ($data['credit_account'] ?? 0)) {
                $errors['data.credit_account'] = 'Debit and credit must be different accounts.';
            }
            if (in_array($payload['status'], self::COUNTED, true) && (float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'A posted entry needs an amount above zero.';
            }
            if ($existing && in_array($existing->status, self::COUNTED, true) && $this->changesPostedEntry($existing, $payload)) {
                $errors['amount'] = 'Posted entries cannot be changed. Reverse it and post a new one.';
            }

            return $errors;
        }

        return [];
    }

    /** @param  array<string, mixed>  $payload */
    protected function changesPostedEntry(Record $existing, array $payload): bool
    {
        return (float) $existing->amount !== (float) ($payload['amount'] ?? 0)
            || (int) $existing->value('debit_account') !== (int) ($payload['data']['debit_account'] ?? 0)
            || (int) $existing->value('credit_account') !== (int) ($payload['data']['credit_account'] ?? 0)
            || $existing->occurs_on?->toDateString() !== (! empty($payload['occurs_on']) ? Carbon::parse($payload['occurs_on'])->toDateString() : null);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'journals' && $record->status === 'posted') {
            return ['reverse' => ['label' => 'Reverse entry', 'icon' => 'undo-2', 'confirm' => 'Post an equal and opposite entry to cancel this one?']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $reversal = $this->reverse($record);

        return 'Reversed by '.$reversal->number.'.';
    }

    /** Post the mirror image of an entry today and mark the original reversed. */
    public function reverse(Record $journal): Record
    {
        $reversal = Record::create([
            'workspace_id' => $journal->workspace_id, 'blueprint' => $this->app->key, 'entity' => 'journals',
            'title' => 'Reversal of '.$journal->number.': '.$journal->title, 'status' => 'posted', 'amount' => $journal->amount, 'occurs_on' => today(),
            'data' => ['debit_account' => $journal->value('credit_account'), 'credit_account' => $journal->value('debit_account'), 'reference' => $journal->number, 'source' => 'adjustment'],
        ]);
        $journal->update(['status' => 'reversed']);

        return $reversal;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'accounts') {
            return [];
        }

        $journals = $this->counted()->get()->filter(fn (Record $journal) => in_array($record->id, [(int) $journal->value('debit_account'), (int) $journal->value('credit_account')], true));
        $debits = $journals->filter(fn (Record $journal) => (int) $journal->value('debit_account') === $record->id)->sum('amount');
        $credits = $journals->filter(fn (Record $journal) => (int) $journal->value('credit_account') === $record->id)->sum('amount');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Account balance', 'icon' => 'scale', 'stats' => [
                ['label' => 'Opening', 'value' => $this->money($record->value('opening_balance'))],
                ['label' => 'Debits', 'value' => $this->money($debits)],
                ['label' => 'Credits', 'value' => $this->money($credits)],
                ['label' => 'Balance', 'value' => $this->money($this->balance($record, $debits, $credits))],
            ], 'note' => in_array($record->value('type'), self::DEBIT_NORMAL, true) ? 'Debits increase this account.' : 'Credits increase this account.']],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Latest entries', 'icon' => 'book-open', 'empty' => 'No posted entries yet.',
                'rows' => $journals->sortByDesc(fn (Record $journal) => $this->dateOf($journal)->timestamp.'-'.$journal->id)->take(10)->map(fn (Record $journal) => [
                    'label' => $journal->title, 'sub' => $journal->number.' · '.$this->dateOf($journal)->format('d M Y'),
                    'value' => ((int) $journal->value('debit_account') === $record->id ? 'Dr ' : 'Cr ').$this->money($journal->amount), 'href' => $journal->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $profit = $this->profitAndLoss(today()->startOfMonth(), today()->endOfDay());

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => today()->format('F Y'), 'icon' => 'book-open', 'stats' => [
                ['label' => 'Income', 'value' => $this->money($profit['income'])],
                ['label' => 'Expenses', 'value' => $this->money($profit['expense'])],
                ['label' => 'Net profit', 'value' => $this->money($profit['income'] - $profit['expense']), 'tone' => $profit['income'] - $profit['expense'] < 0 ? 'danger' : 'success'],
                ['label' => 'Drafts to post', 'value' => (string) $this->records('journals')->where('status', 'draft')->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $accounts = $this->records('accounts')->get()->sortBy(fn (Record $account) => (string) $account->value('code'));
        $asAt = $this->movements(null, $to);

        $trial = [];
        $totalDebit = $totalCredit = 0.0;
        foreach ($accounts as $account) {
            $balance = $this->balance($account, $asAt[$account->id]['debit'] ?? 0, $asAt[$account->id]['credit'] ?? 0);
            if (abs($balance) < 0.005) {
                continue;
            }
            $debitSide = in_array($account->value('type'), self::DEBIT_NORMAL, true) === $balance > 0;
            $debitSide ? $totalDebit += abs($balance) : $totalCredit += abs($balance);
            $trial[] = [$account->value('code'), $account->title, $debitSide ? $this->money(abs($balance)) : '', $debitSide ? '' : $this->money(abs($balance))];
        }

        $period = $this->movements($from, $to);
        $profitRows = [];
        foreach (['income', 'expense'] as $type) {
            foreach ($accounts->filter(fn (Record $account) => $account->value('type') === $type) as $account) {
                $amount = $this->balance($account, $period[$account->id]['debit'] ?? 0, $period[$account->id]['credit'] ?? 0) - (float) $account->value('opening_balance');
                if (abs($amount) >= 0.005) {
                    $profitRows[] = [self::TYPES[$type], $account->title, $this->money($amount)];
                }
            }
        }
        $profit = $this->profitAndLoss($from, $to);

        $sheet = [];
        $totals = array_fill_keys(['asset', 'liability', 'equity', 'income', 'expense'], 0.0);
        foreach ($accounts as $account) {
            $totals[$account->value('type')] = ($totals[$account->value('type')] ?? 0) + $this->balance($account, $asAt[$account->id]['debit'] ?? 0, $asAt[$account->id]['credit'] ?? 0);
        }
        foreach (['asset', 'liability', 'equity'] as $type) {
            $sheet[] = [self::TYPES[$type], $this->money($totals[$type])];
        }
        $sheet[] = ['Profit to date (income less expenses)', $this->money($totals['income'] - $totals['expense'])];

        return [
            ['title' => 'Trial balance at '.$to->format('d M Y'), 'columns' => ['Code', 'Account', 'Debit', 'Credit'], 'rows' => $trial,
                'note' => 'Totals: debit '.$this->money($totalDebit).', credit '.$this->money($totalCredit).(abs($totalDebit - $totalCredit) < 0.005 ? '. The books balance.' : '. The difference comes from opening balances that do not balance.')],
            ['title' => 'Profit and loss', 'columns' => ['Type', 'Account', 'Amount'], 'rows' => $profitRows,
                'note' => 'Income '.$this->money($profit['income']).' less expenses '.$this->money($profit['expense']).' = net profit '.$this->money($profit['income'] - $profit['expense']).'.'],
            ['title' => 'Balance sheet at '.$to->format('d M Y'), 'columns' => ['Section', 'Amount'], 'rows' => $sheet,
                'note' => 'Assets should equal liabilities plus equity plus profit to date.'],
        ];
    }

    /** @return array{income: float, expense: float} */
    public function profitAndLoss(Carbon $from, Carbon $to): array
    {
        $types = $this->records('accounts')->get()->mapWithKeys(fn (Record $account) => [$account->id => $account->value('type')]);
        $totals = ['income' => 0.0, 'expense' => 0.0];

        foreach ($this->movements($from, $to) as $accountId => $movement) {
            $type = $types[$accountId] ?? null;
            if ($type === 'income') {
                $totals['income'] += $movement['credit'] - $movement['debit'];
            } elseif ($type === 'expense') {
                $totals['expense'] += $movement['debit'] - $movement['credit'];
            }
        }

        return $totals;
    }

    /** Debits and credits per account from counted entries dated in a range. @return array<int, array{debit: float, credit: float}> */
    public function movements(?Carbon $from, ?Carbon $to): array
    {
        $movements = [];
        foreach ($this->counted()->get() as $journal) {
            $date = $this->dateOf($journal);
            if (($from && $date->lt($from->copy()->startOfDay())) || ($to && $date->gt($to->copy()->endOfDay()))) {
                continue;
            }
            $debit = (int) $journal->value('debit_account');
            $credit = (int) $journal->value('credit_account');
            $movements[$debit]['debit'] = ($movements[$debit]['debit'] ?? 0) + (float) $journal->amount;
            $movements[$debit]['credit'] ??= 0.0;
            $movements[$credit]['credit'] = ($movements[$credit]['credit'] ?? 0) + (float) $journal->amount;
            $movements[$credit]['debit'] ??= 0.0;
        }

        return $movements;
    }

    /** An account's balance on its normal side: debit-normal accounts grow with debits, the rest with credits. */
    public function balance(Record $account, float $debits, float $credits): float
    {
        $opening = (float) $account->value('opening_balance');

        return round(in_array($account->value('type'), self::DEBIT_NORMAL, true) ? $opening + $debits - $credits : $opening + $credits - $debits, 2);
    }

    /** @return Builder<Record> */
    protected function counted()
    {
        return $this->records('journals')->whereIn('status', self::COUNTED);
    }

    protected function dateOf(Record $journal): Carbon
    {
        return $journal->occurs_on ?? $journal->created_at;
    }
}
