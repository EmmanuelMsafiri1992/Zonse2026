<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Models\FiscalDocument;
use App\Models\Record;
use App\Models\User;
use App\Support\Hardware\CardTerminal;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\Payment;

/**
 * Point of sale: the till rings up Invoicing items into a sale record, a paid invoice and
 * a stock movement in one go; cashier shifts close against the cash the till expects.
 */
class PosLogic extends AppLogic
{
    /** Sale payment method => Invoicing payment method. "account" sales stay owing on the invoice. */
    public const PAYMENT_METHODS = ['cash' => 'cash', 'card' => 'card', 'mobile_money' => 'mobile_money', 'account' => null, 'split' => 'other'];

    /** Name of the shared contact that sales without a chosen customer are billed to. */
    public const WALK_IN_CUSTOMER = 'Walk-in customer';

    /**
     * Ring up a sale. Prices and tax always come from the item list, never from the browser.
     *
     * @param  list<array{item_id: int|string, quantity: float|int|string}>  $cart
     *
     * @throws ValidationException when an item is unknown, stock is short, or cash tendered is too little
     */
    public function sell(array $cart, string $method, ?float $tendered, ?int $tillId, ?int $contactId, User $cashier): Record
    {
        $items = Item::query()->active()->with('taxRate')->whereKey(collect($cart)->pluck('item_id')->all())->get()->keyBy('id');

        $lines = [];
        foreach ($cart as $row) {
            $item = $items->get((int) $row['item_id']) ?? throw ValidationException::withMessages(['lines' => 'One of the items is no longer for sale.']);
            $quantity = (float) $row['quantity'];
            if ($item->tracksStock() && $item->stock_qty < $quantity) {
                throw ValidationException::withMessages(['lines' => 'Only '.rtrim(rtrim(number_format($item->stock_qty, 3), '0'), '.').' '.$item->name.' left in stock.']);
            }
            $net = Money::round($quantity * $item->price);
            $taxRate = (float) ($item->taxRate?->rate ?? 0);
            $lines[] = [
                'item_id' => $item->id, 'description' => $item->name, 'quantity' => $quantity, 'unit' => $item->unit,
                'unit_price' => (float) $item->price, 'tax_rate' => $taxRate, 'total' => Money::round($net + $net * $taxRate / 100),
            ];
        }

        $total = Money::round(array_sum(array_column($lines, 'total')));
        if ($method === 'cash' && $tendered !== null && $tendered + 0.001 < $total) {
            throw ValidationException::withMessages(['tendered' => 'Cash tendered is less than the total of '.$this->money($total).'.']);
        }
        if ($method === 'account' && ! $contactId) {
            throw ValidationException::withMessages(['contact_id' => 'Choose the customer to put this sale on account.']);
        }

        $approvalCode = null;
        $terminal = app(CardTerminal::class);
        $workspace = app(WorkspaceContext::class)->getOrFail();
        if ($method === 'card' && $terminal->linked($workspace)) {
            $charge = $terminal->charge($workspace, $total, 'a till sale of '.$this->money($total));
            if (! $charge['approved']) {
                throw ValidationException::withMessages(['payment_method' => $charge['message']]);
            }
            $approvalCode = $charge['approval_code'];
        }

        return DB::transaction(function () use ($lines, $items, $total, $method, $tendered, $tillId, $contactId, $cashier, $approvalCode) {
            $tendered = $method === 'cash' ? ($tendered ?? $total) : $total;
            $sale = Record::create([
                'blueprint' => $this->app->key, 'entity' => 'sales', 'status' => 'completed',
                'title' => str(collect($lines)->map(fn ($line) => $this->quantity($line['quantity']).' × '.$line['description'])->implode(', '))->limit(240)->toString(),
                'contact_id' => $contactId, 'assignee_id' => $cashier->id, 'amount' => $total, 'occurs_on' => today(),
                'currency' => $cashier->currentWorkspace?->currency_code,
                'data' => [
                    'till' => $tillId, 'payment_method' => $method,
                    'items' => collect($lines)->map(fn ($line) => $this->quantity($line['quantity']).' × '.$line['description'].' @ '.number_format($line['unit_price'], 2))->implode("\n"),
                    'tendered' => $tendered, 'change' => Money::round(max(0, $tendered - $total)), 'discount' => 0.0,
                    '_card_approval' => $approvalCode, '_lines' => $lines,
                ],
            ]);

            foreach ($lines as $line) {
                $items->get($line['item_id'])->adjustStock(-$line['quantity']);
            }

            if ($this->billing()->available()) {
                $invoice = $this->billing()->invoice($sale);
                if (self::PAYMENT_METHODS[$method] ?? null) {
                    $this->billing()->pay($invoice, $invoice->balance, self::PAYMENT_METHODS[$method], today()->toDateString(), $sale->number.($approvalCode ? ' · '.$approvalCode : ''));
                }
            }

            return $sale;
        });
    }

    public function billingContact(Record $record): ?Contact
    {
        if ($record->entity !== 'sales' || $record->contact_id) {
            return null;
        }

        return Contact::query()->firstOrCreate(['name' => self::WALK_IN_CUSTOMER, 'type' => 'customer'], ['kind' => 'person']);
    }

    public function invoiceLines(Record $record): array
    {
        $lines = (array) $record->value('_lines');

        return $lines
            ? array_map(fn (array $line) => array_intersect_key($line, array_flip(['item_id', 'description', 'quantity', 'unit', 'unit_price', 'tax_rate'])), $lines)
            : parent::invoiceLines($record);
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'sales' ? ['receipt' => 'Receipt'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'receipt' || $record->entity !== 'sales') {
            return null;
        }

        $lines = (array) $record->value('_lines');
        $till = $record->related('till');
        $fiscal = FiscalDocument::query()->where('type', 'invoice')->whereIn('invoice_id', $record->invoices()->select('invoices.id'))->first();

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Receipt',
            'meta' => array_filter([
                'Date' => ($record->occurs_on ?? $record->created_at)->format('d M Y').' '.$record->created_at?->format('H:i'),
                'Cashier' => $record->assignee?->name,
                'Paid by' => ucfirst(str_replace('_', ' ', (string) $record->value('payment_method'))),
                'Card approval' => $record->value('_card_approval'),
                'Fiscal no.' => $fiscal ? $fiscal->fiscal_number.' · '.$fiscal->verification_code : null,
            ]),
            'columns' => ['Item', 'Qty', 'Price', 'Amount'],
            'rows' => array_map(fn (array $line) => [$line['description'], $this->quantity($line['quantity']), number_format($line['unit_price'], 2), number_format($line['total'], 2)], $lines),
            'totals' => array_filter([
                'Tax' => array_sum(array_map(fn ($line) => $line['total'] - Money::round($line['quantity'] * $line['unit_price']), $lines)) > 0
                    ? $this->money(array_sum(array_map(fn ($line) => $line['total'] - Money::round($line['quantity'] * $line['unit_price']), $lines))) : null,
                'Tendered' => $this->money($record->value('tendered')),
                'Change' => $this->money($record->value('change')),
                'Total' => $this->money($record->amount),
            ]),
            'notes' => $till?->value('receipt_footer') ?: 'Thank you for shopping with us.',
        ]];
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'sales' && $record->value('_lines')) {
            return [['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Items', 'icon' => 'shopping-basket',
                'rows' => array_map(fn (array $line) => [
                    'label' => $line['description'], 'sub' => $this->quantity($line['quantity']).' × '.number_format($line['unit_price'], 2), 'value' => $this->money($line['total']),
                ], (array) $record->value('_lines')),
            ]]];
        }

        if ($record->entity === 'shifts') {
            $expected = $record->status === 'open' ? $this->expectedCash($record) : (float) $record->value('expected_cash');

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Cash drawer', 'icon' => 'banknote', 'stats' => [
                ['label' => 'Opening float', 'value' => $this->money($record->value('opening_float'))],
                ['label' => 'Cash sales', 'value' => $this->money($expected - (float) $record->value('opening_float'))],
                ['label' => 'Expected in drawer', 'value' => $this->money($expected)],
                ['label' => 'Counted', 'value' => $record->value('counted_cash') !== null ? $this->money($record->value('counted_cash')) : '—'],
            ]]]];
        }

        return [];
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'shifts' || $record->status !== 'open') {
            return [];
        }

        return ['close_shift' => [
            'label' => 'Close shift', 'icon' => 'lock',
            'fields' => [['name' => 'counted_cash', 'label' => 'Cash counted in drawer', 'type' => 'number']],
        ]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $validated = $request->validate(['counted_cash' => ['required', 'numeric', 'min:0']]);
        $record = $this->closeShift($record, (float) $validated['counted_cash']);

        return 'Shift closed: '.strtolower($record->statusLabel()).' (expected '.$this->money($record->value('expected_cash')).', counted '.$this->money($record->value('counted_cash')).').';
    }

    public function closeShift(Record $shift, float $counted): Record
    {
        $expected = $this->expectedCash($shift);
        $difference = Money::round($counted - $expected);

        $shift->update([
            'status' => $difference == 0 ? 'closed' : ($difference < 0 ? 'short' : 'over'),
            'data' => array_merge((array) $shift->data, ['expected_cash' => $expected, 'counted_cash' => Money::round($counted), '_closed_at' => now()->toDateTimeString()]),
        ]);

        return $shift;
    }

    /** Opening float plus this cashier's cash sales on this till since the shift opened. */
    public function expectedCash(Record $shift): float
    {
        $sales = $this->records('sales')->where('status', 'completed')->where('data->payment_method', 'cash')
            ->where('created_at', '>=', $shift->created_at)
            ->when($shift->value('_closed_at'), fn ($query, $closedAt) => $query->where('created_at', '<=', $closedAt))
            ->when($shift->value('till'), fn ($query, $till) => $query->where('data->till', $till))
            ->when($shift->value('cashier'), fn ($query, $cashier) => $query->where('assignee_id', $cashier))
            ->sum('amount');

        return Money::round((float) $shift->value('opening_float') + (float) $sales);
    }

    public function homeCards(): array
    {
        $today = $this->records('sales')->whereDate('occurs_on', today())->where('status', 'completed')->get(['id', 'amount', 'data']);
        $cards = [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today\'s sales', 'icon' => 'shopping-cart', 'stats' => [
            ['label' => 'Sales', 'value' => (string) $today->count()],
            ['label' => 'Takings', 'value' => $this->money($today->sum('amount'))],
            ['label' => 'Cash', 'value' => $this->money($today->filter(fn ($sale) => $sale->value('payment_method') === 'cash')->sum('amount'))],
            ['label' => 'Average sale', 'value' => $this->money($today->count() ? $today->sum('amount') / $today->count() : 0)],
        ]]]];

        if ($this->billing()->available()) {
            $low = Item::query()->active()->lowOnStock()->orderBy('stock_qty')->limit(10)->get();
            $cards[] = ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Low on stock', 'icon' => 'package', 'empty' => 'Stock levels are fine.',
                'link' => ['label' => 'Items', 'href' => route('items.index')],
                'rows' => $low->map(fn (Item $item) => [
                    'label' => $item->name, 'sub' => 'Reorder at '.$this->quantity($item->reorder_level ?? 0), 'value' => $this->quantity($item->stock_qty).' left', 'tone' => 'overdue',
                ])->all(),
            ]];
        }

        return $cards;
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sales = $this->dated('sales', $from, $to)->where('status', 'completed')->with('assignee')->get();

        $byDay = $sales->groupBy(fn (Record $sale) => ($sale->occurs_on ?? $sale->created_at)->toDateString())->sortKeys()
            ->map(fn ($group, $day) => [Carbon::parse($day)->format('D d M Y'), $group->count(), $this->money($group->sum('amount'))])->values()->all();
        $byMethod = $sales->groupBy(fn (Record $sale) => ucfirst(str_replace('_', ' ', (string) $sale->value('payment_method'))))
            ->map(fn ($group, $method) => [$method, $group->count(), $this->money($group->sum('amount'))])->values()->all();
        $byCashier = $sales->groupBy(fn (Record $sale) => $sale->assignee?->name ?? 'Unknown')
            ->map(fn ($group, $name) => [$name, $group->count(), $this->money($group->sum('amount'))])->values()->all();

        $items = [];
        foreach ($sales as $sale) {
            foreach ((array) $sale->value('_lines') as $line) {
                $items[$line['description']]['quantity'] = ($items[$line['description']]['quantity'] ?? 0) + $line['quantity'];
                $items[$line['description']]['total'] = ($items[$line['description']]['total'] ?? 0) + $line['total'];
            }
        }
        uasort($items, fn ($a, $b) => $b['total'] <=> $a['total']);
        $topItems = array_map(fn ($name, $row) => [$name, $this->quantity($row['quantity']), $this->money($row['total'])], array_keys($items), $items);

        return [
            ['title' => 'Sales by day', 'columns' => ['Day', 'Sales', 'Takings'], 'rows' => $byDay],
            ['title' => 'Top items', 'columns' => ['Item', 'Quantity', 'Takings'], 'rows' => array_slice($topItems, 0, 15)],
            ['title' => 'By payment method', 'columns' => ['Method', 'Sales', 'Takings'], 'rows' => $byMethod],
            ['title' => 'By cashier', 'columns' => ['Cashier', 'Sales', 'Takings'], 'rows' => $byCashier],
        ];
    }

    protected function quantity(float|int|string|null $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ','), '0'), '.');
    }
}
