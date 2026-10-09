<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Banking: each bank account keeps a running book balance from its statement lines. A
 * reconciliation works out the book balance at the statement date, can only be completed
 * when it agrees with the bank, and then ticks off every line it covered.
 */
class BankingLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'transactions' && (float) ($payload['amount'] ?? 0) <= 0) {
            return ['amount' => 'Enter the amount as a positive number and pick money in or out.'];
        }

        if ($entity->key === 'reconciliations' && $payload['status'] === 'completed' && ! empty($payload['data']['account'])) {
            $account = $this->records('accounts')->find($payload['data']['account']);
            $date = ! empty($payload['occurs_on']) ? Carbon::parse($payload['occurs_on']) : today();
            $book = $account ? $this->bookBalance($account, $date) : 0.0;
            $difference = round((float) ($payload['data']['statement_balance'] ?? 0) - $book, 2);
            if (abs($difference) >= 0.005) {
                return ['data.statement_balance' => 'The statement and the books differ by '.$this->money($difference).' (books: '.$this->money($book).'). Record the missing lines before completing.'];
            }
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'reconciliations' && $record->value('account')) {
            $account = $this->records('accounts')->find($record->value('account'));
            if ($account) {
                $this->put($record, ['book_balance' => $this->bookBalance($account, $record->occurs_on ?? today())]);
            }
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'reconciliations' && $record->status === 'completed' && ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
            $date = ($record->occurs_on ?? today())->copy()->endOfDay();
            $this->lines((int) $record->value('account'))->where('status', 'unreconciled')->get()
                ->filter(fn (Record $line) => $this->dateOf($line)->lte($date))
                ->each(fn (Record $line) => $line->update(['status' => 'reconciled', 'data' => array_merge((array) $line->data, ['_reconciliation' => $record->id])]));
        }
    }

    /** Opening balance plus money in less money out, for lines dated up to the given day. */
    public function bookBalance(Record $account, ?Carbon $upTo = null, ?string $status = null): float
    {
        $lines = $this->lines($account->id)->when($status, fn ($query) => $query->where('status', $status))->get();
        if ($upTo) {
            $lines = $lines->filter(fn (Record $line) => $this->dateOf($line)->lte($upTo->copy()->endOfDay()));
        }

        return round((float) $account->value('opening_balance') + $this->net($lines), 2);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'accounts') {
            $lines = $this->lines($record->id)->get();
            $open = $lines->where('status', 'unreconciled');

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Balance', 'icon' => 'landmark', 'stats' => [
                ['label' => 'Book balance', 'value' => $this->money($this->bookBalance($record))],
                ['label' => 'Money in', 'value' => $this->money($lines->where('data.direction', 'money_in')->sum('amount'))],
                ['label' => 'Money out', 'value' => $this->money($lines->where('data.direction', 'money_out')->sum('amount'))],
                ['label' => 'Unreconciled lines', 'value' => (string) $open->count(), 'tone' => $open->isNotEmpty() ? 'warning' : null],
            ]]]];
        }

        if ($record->entity === 'reconciliations') {
            $difference = round((float) $record->value('statement_balance') - (float) $record->value('book_balance'), 2);

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Reconciliation', 'icon' => 'check-check', 'stats' => [
                ['label' => 'Bank statement', 'value' => $this->money($record->value('statement_balance'))],
                ['label' => 'Books', 'value' => $this->money($record->value('book_balance'))],
                ['label' => 'Difference', 'value' => $this->money($difference), 'tone' => abs($difference) < 0.005 ? 'success' : 'danger'],
            ], 'note' => 'The book balance counts every statement line on this account dated on or before the statement date.']]];
        }

        return [];
    }

    public function homeCards(): array
    {
        $accounts = $this->records('accounts')->where('status', 'active')->orderBy('title')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Bank balances', 'icon' => 'landmark', 'empty' => 'Add a bank account to start.',
            'rows' => $accounts->map(fn (Record $account) => [
                'label' => $account->title, 'sub' => trim($account->value('bank').' · '.$account->value('account_number'), ' ·'),
                'value' => $this->money($this->bookBalance($account)), 'href' => $account->url(),
            ])->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $accounts = $this->records('accounts')->orderBy('title')->get();
        $summary = $accounts->map(function (Record $account) use ($from, $to) {
            $period = $this->lines($account->id)->get()->filter(fn (Record $line) => $this->dateOf($line)->between($from->copy()->startOfDay(), $to->copy()->endOfDay()));

            return [
                $account->title,
                $this->money($this->bookBalance($account, $from->copy()->subDay())),
                $this->money($period->where('data.direction', 'money_in')->sum('amount')),
                $this->money($period->where('data.direction', 'money_out')->sum('amount')),
                $this->money($this->bookBalance($account, $to)),
            ];
        })->all();

        $names = $accounts->pluck('title', 'id');
        $unreconciled = $this->records('transactions')->where('status', 'unreconciled')->get()
            ->sortBy(fn (Record $line) => $this->dateOf($line)->timestamp)
            ->map(fn (Record $line) => [$this->dateOf($line)->format('d M Y'), $names[$line->value('account')] ?? '—', $line->title, ($line->value('direction') === 'money_out' ? '-' : '').$this->money($line->amount)])
            ->values()->all();

        return [
            ['title' => 'Cash movement', 'columns' => ['Account', 'Opening', 'Money in', 'Money out', 'Closing'], 'rows' => $summary],
            ['title' => 'Unreconciled lines', 'columns' => ['Date', 'Account', 'Description', 'Amount'], 'rows' => $unreconciled],
        ];
    }

    /** @return Builder<Record> */
    protected function lines(int $accountId)
    {
        return $this->linked('transactions', 'account', $accountId)->whereNot('status', 'queried');
    }

    /** @param  Collection<int, Record>  $lines */
    protected function net(Collection $lines): float
    {
        return $lines->sum(fn (Record $line) => ($line->value('direction') === 'money_out' ? -1 : 1) * (float) $line->amount);
    }

    protected function dateOf(Record $line): Carbon
    {
        return $line->occurs_on ?? $line->created_at;
    }
}
