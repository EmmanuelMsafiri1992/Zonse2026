<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Invoicing\Models\Invoice;

/**
 * Receivables: each debtor account reads its balance and its oldest overdue invoice from
 * Invoicing, moves between current, overdue and settled on its own, and prints a statement.
 * Balances refresh whenever the account is saved, on demand and every night.
 */
class ReceivablesLogic extends AppLogic
{
    /** Statuses the account manages on its own; in_collection and handed_over are set by people. */
    public const AUTOMATIC = ['current', 'overdue'];

    public const AGES = ['Not yet due' => [null, 0], '1–30 days' => [1, 30], '31–60 days' => [31, 60], '61–90 days' => [61, 90], 'Over 90 days' => [91, null]];

    public function saving(Record $record): void
    {
        if ($record->entity !== 'accounts' || ! $record->contact_id || ! $this->billing()->available()) {
            return;
        }

        $invoices = $this->openInvoices($record->contact_id);
        $record->amount = round((float) $invoices->sum('balance'), 2);
        $oldest = $invoices->filter(fn (Invoice $invoice) => $invoice->due_date && $invoice->due_date->lt(today()))->min('due_date');
        $this->put($record, ['days_overdue' => $oldest ? (int) Carbon::parse($oldest)->diffInDays(today()) : 0]);

        if ($record->amount <= 0 && in_array($record->status, ['overdue', 'in_collection', 'handed_over'], true)) {
            $record->status = 'settled';
        } elseif (in_array($record->status, self::AUTOMATIC, true) || ($record->status === 'settled' && $record->amount > 0)) {
            $record->status = $this->number($record, 'days_overdue') > 0 ? 'overdue' : 'current';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('accounts')->whereNotNull('contact_id')->get() as $account) {
            $before = [$account->status, (float) $account->amount, $this->number($account, 'days_overdue')];
            $account->save();
            $changed += $before !== [$account->status, (float) $account->amount, $this->number($account, 'days_overdue')] ? 1 : 0;
        }

        return $changed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'accounts' && $record->contact_id) {
            return ['refresh' => ['label' => 'Refresh balance', 'icon' => 'refresh-cw']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $record->save();

        return 'Balance refreshed: '.$this->money($record->amount).' owing.';
    }

    /** @return array<string, float> age bucket => amount owing */
    public function ageing(Collection $invoices): array
    {
        $buckets = array_fill_keys(array_keys(self::AGES), 0.0);
        foreach ($invoices as $invoice) {
            $days = $invoice->due_date && $invoice->due_date->lt(today()) ? (int) $invoice->due_date->diffInDays(today()) : 0;
            foreach (self::AGES as $label => [$min, $max]) {
                if (($min === null || $days >= $min) && ($max === null || $days <= $max)) {
                    $buckets[$label] += (float) $invoice->balance;
                    break;
                }
            }
        }

        return $buckets;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'actions') {
            return [];
        }

        if (! $record->contact_id || ! $this->billing()->available()) {
            return [['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'info', 'icon' => 'info', 'title' => $record->contact_id ? 'Turn on Invoicing to track this customer\'s balance.' : 'Pick the customer to see what they owe.']]];
        }

        $invoices = $this->openInvoices($record->contact_id);
        $limit = $this->number($record, 'credit_limit');
        $stats = collect($this->ageing($invoices))->map(fn (float $amount, string $label) => ['label' => $label, 'value' => $this->money($amount), 'tone' => $amount > 0 && $label !== 'Not yet due' ? 'danger' : null])->values()->all();
        if ($limit > 0) {
            $stats[] = ['label' => 'Credit left', 'value' => $this->money($limit - (float) $invoices->sum('balance')), 'tone' => (float) $invoices->sum('balance') > $limit ? 'danger' : 'success'];
        }

        $history = $this->linked('actions', 'account', $record)->latest('occurs_on')->latest('id')->take(5)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Ageing', 'icon' => 'hourglass', 'stats' => $stats, 'note' => $invoices->count().' open invoice(s), '.$this->money($invoices->sum('balance')).' owing.']],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Collection history', 'icon' => 'phone-call', 'empty' => 'No collection actions yet.',
                'rows' => $history->map(fn (Record $action) => [
                    'label' => $action->definition()->field('action')?->options[$action->value('action')] ?? $action->title,
                    'sub' => ($action->occurs_on ?? $action->created_at)->format('d M Y').' · '.$action->title, 'href' => $action->url(),
                ])->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $accounts = $this->records('accounts')->whereIn('status', ['overdue', 'in_collection', 'handed_over'])->orderByDesc('amount')->take(8)->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Overdue customers', 'icon' => 'hand-coins', 'empty' => 'Nobody is overdue.',
            'rows' => $accounts->map(fn (Record $account) => [
                'label' => $account->title, 'sub' => (int) $account->value('days_overdue').' days overdue'.($account->value('promise_to_pay') ? ' · promised '.Carbon::parse($account->value('promise_to_pay'))->format('d M') : ''),
                'value' => $this->money($account->amount), 'href' => $account->url(), 'tone' => 'danger',
            ])->all(),
        ]]];
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'accounts' && $record->contact_id && $this->billing()->available() ? ['statement' => 'Statement'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'statement' || $record->entity !== 'accounts' || ! $record->contact_id) {
            return null;
        }

        $invoices = $this->openInvoices($record->contact_id)->sortBy('issue_date');
        $totals = ['Total owing' => $this->money($invoices->sum('balance'))];
        foreach ($this->ageing($invoices) as $label => $amount) {
            if ($amount > 0) {
                $totals[$label] = $this->money($amount);
            }
        }

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Statement of account',
            'meta' => ['Customer' => $record->contact?->name ?? $record->title, 'Account' => $record->number, 'Statement date' => today()->format('d M Y')],
            'columns' => ['Invoice', 'Date', 'Due', 'Total', 'Paid', 'Owing'],
            'rows' => $invoices->map(fn (Invoice $invoice) => [
                $invoice->number, $invoice->issue_date?->format('d M Y'), $invoice->due_date?->format('d M Y'),
                $this->money($invoice->total), $this->money($invoice->amount_paid), $this->money($invoice->balance),
            ])->values()->all(),
            'totals' => $totals,
            'notes' => 'Please pay the amount owing. Ignore this statement if you have paid in the last few days.',
        ]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $rows = [];
        if ($this->billing()->available()) {
            foreach ($this->records('accounts')->whereNotNull('contact_id')->orderBy('title')->get() as $account) {
                $ageing = $this->ageing($this->openInvoices($account->contact_id));
                if (array_sum($ageing) > 0) {
                    $rows[] = [$account->title, ...array_map(fn (float $amount) => $this->money($amount), array_values($ageing)), $this->money(array_sum($ageing))];
                }
            }
        }

        $actions = $this->dated('actions', $from, $to)->get();
        $options = $this->app->entity('actions')?->field('action')?->options ?? [];

        return [
            ['title' => 'Aged debtors today', 'columns' => ['Customer', ...array_keys(self::AGES), 'Total'], 'rows' => $rows],
            ['title' => 'Collection actions', 'columns' => ['Action', 'Count'], 'rows' => $actions->groupBy(fn (Record $action) => (string) $action->value('action'))
                ->map(fn ($group, $key) => [$options[$key] ?? $key, $group->count()])->values()->all()],
        ];
    }

    /** @return Collection<int, Invoice> */
    protected function openInvoices(int $contactId): Collection
    {
        return Invoice::query()->where('contact_id', $contactId)->open()->get();
    }
}
