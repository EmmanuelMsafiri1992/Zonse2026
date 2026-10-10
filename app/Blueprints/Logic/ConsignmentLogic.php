<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Wholesale distribution & consignment stock: a consignment lists the stock placed with a dealer
 * ("20 x Cement @ 15"), and its stock value follows the lines. Settlements record units the dealer
 * sold: they can't add up to more than was placed, and the amount due is their value less the
 * dealer's commission. The consignment's sold value and status follow its settlements, and taking
 * back unsold stock needs the count returned.
 */
class ConsignmentLogic extends AppLogic
{
    /**
     * Guards against re-entering the consignment roll-up.
     */
    protected static bool $rolling = false;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'consignments') {
            [$lines, $problems] = $this->lines((string) ($data['items'] ?? ''));
            if ($problems) {
                $errors['data.items'] = implode(' ', $problems);
            }
            $rate = (float) ($data['commission_rate'] ?? 0);
            if ($rate < 0 || $rate > 100) {
                $errors['data.commission_rate'] = 'Commission must be between 0 and 100%.';
            }
            if ($existing && ($sold = $this->unitsSold($existing)) > array_sum(array_column($lines, 'quantity'))) {
                $errors['data.items'] = $sold.' units are already settled; the consignment cannot hold fewer.';
            }
            if ($payload['status'] === 'returned' && $existing?->status !== 'returned') {
                $errors['status'] = 'Use "Take back stock" so the units returned are recorded.';
            }
            if ($payload['status'] === 'settled' && $existing?->status !== 'settled') {
                $errors['status'] = 'A consignment is settled once all its stock is sold and paid for.';
            }
            if (filled($payload['due_on'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The settle-by date cannot be before the stock was placed.';
            }

            return $errors;
        }

        $units = (float) ($data['units_sold'] ?? 0);
        if ($units <= 0) {
            $errors['data.units_sold'] = 'Enter how many units sold.';
        }
        if ($existing?->status === 'paid' && ($payload['status'] !== 'paid' || $units !== $this->number($existing, 'units_sold'))) {
            $errors['status'] = 'This settlement is paid.';
        }
        $consignment = filled($data['consignment'] ?? null) ? $this->records('consignments')->find($data['consignment']) : null;
        if ($consignment) {
            if (! $existing && in_array($consignment->status, ['settled', 'returned'], true)) {
                $errors['data.consignment'] = $consignment->title.' is '.$consignment->status.'.';
            }
            $left = $this->number($consignment, '_units') - $this->unitsSold($consignment, $existing);
            if ($units > $left) {
                $errors['data.units_sold'] = 'Only '.$this->quantity($left).' units are left with the dealer.';
            }
        }

        return $errors;
    }

    /**
     * Read item lines like "20 x Cement @ 15" (the price is optional).
     *
     * @return array{0: list<array{item: string, quantity: float, price: float|null}>, 1: list<string>}
     */
    public function lines(string $items): array
    {
        $lines = [];
        $problems = [];
        foreach (preg_split('/\R/', $items) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*[x×*]?\s+(.+?)(?:\s*@\s*(\d+(?:\.\d+)?))?\s*$/iu', $line, $match)) {
                $problems[] = 'Start "'.trim($line).'" with a quantity, like "10 x '.trim($line).'".';

                continue;
            }
            $lines[] = ['item' => trim($match[2]), 'quantity' => (float) $match[1], 'price' => isset($match[3]) && $match[3] !== '' ? (float) $match[3] : null];
        }

        return [$lines, $problems];
    }

    /**
     * Units settled against the consignment, leaving out one settlement if given.
     */
    protected function unitsSold(Record $consignment, ?Record $except = null): float
    {
        return $this->linked('settlements', 'consignment', $consignment)->when($except, fn ($query) => $query->whereKeyNot($except->id))->get()
            ->sum(fn (Record $settlement) => $this->number($settlement, 'units_sold'));
    }

    /**
     * A quantity without trailing zeros.
     */
    protected function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.');
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'consignments') {
            [$lines] = $this->lines((string) $record->value('items'));
            $priced = collect($lines)->filter(fn (array $line) => $line['price'] !== null);
            if ($priced->isNotEmpty()) {
                $record->amount = round($priced->sum(fn (array $line) => $line['quantity'] * $line['price']), 2);
            }
            $this->put($record, ['_lines' => $lines, '_units' => array_sum(array_column($lines, 'quantity'))]);

            return;
        }

        $consignment = $this->parent($record, 'consignment');
        if (! $consignment || $record->status === 'paid') {
            return;
        }
        $unitPrice = (float) $consignment->amount / max(1, $this->number($consignment, '_units'));
        $gross = round($this->number($record, 'units_sold') * $unitPrice, 2);
        $commission = round($gross * $this->number($consignment, 'commission_rate') / 100, 2);
        $record->amount = round($gross - $commission, 2);
        $this->put($record, ['_gross' => $gross, '_commission' => $commission]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'settlements') {
            $this->rollUp($this->parent($record, 'consignment'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'settlements') {
            $this->rollUp($this->parent($record, 'consignment'));
        }
    }

    /**
     * Bring the consignment's sold value and status into line with its settlements.
     */
    protected function rollUp(?Record $consignment): void
    {
        if (! $consignment || static::$rolling || $consignment->status === 'returned') {
            return;
        }
        $settlements = $this->linked('settlements', 'consignment', $consignment)->get();
        $sold = $settlements->sum(fn (Record $settlement) => $this->number($settlement, 'units_sold'));
        $allSold = $sold >= $this->number($consignment, '_units') && $sold > 0;
        $status = $allSold && $settlements->every(fn (Record $settlement) => $settlement->status === 'paid') ? 'settled' : ($sold > 0 ? 'partly_sold' : 'placed');

        static::$rolling = true;
        try {
            $consignment->update(['status' => $status, 'data' => [
                ...$consignment->data,
                'sold_value' => round($settlements->sum(fn (Record $settlement) => $this->number($settlement, '_gross')), 2),
                '_units_sold' => $sold,
                '_commission' => round($settlements->sum(fn (Record $settlement) => $this->number($settlement, '_commission')), 2),
                '_paid' => round($settlements->where('status', 'paid')->sum('amount'), 2),
            ]]);
        } finally {
            static::$rolling = false;
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'settlements') {
            return $record->status === 'pending' ? ['pay' => ['label' => 'Mark paid', 'icon' => 'banknote']] : [];
        }

        return in_array($record->status, ['placed', 'partly_sold'], true) ? ['take_back' => ['label' => 'Take back stock', 'icon' => 'undo-2', 'fields' => [
            ['name' => 'units', 'label' => 'Units returned', 'type' => 'number', 'value' => $this->number($record, '_units') - $this->number($record, '_units_sold')],
        ]]] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'pay') {
            $record->update(['status' => 'paid', 'data' => [...$record->data, '_paid_on' => today()->toDateString()]]);

            return $record->title.' paid: '.$this->money($record->amount).'.';
        }

        $units = (float) $request->validate(['units' => ['required', 'numeric', 'min:0']])['units'];
        $left = $this->number($record, '_units') - $this->unitsSold($record);
        if (round($units, 4) !== round($left, 4)) {
            throw ValidationException::withMessages(['units' => $this->quantity($left).' units should be with the dealer; settle any sold units before taking the rest back.']);
        }
        $record->update(['status' => 'returned', 'data' => [...$record->data, '_returned_units' => $units, '_returned_on' => today()->toDateString()]]);

        return $this->quantity($units).' units taken back from '.$record->title.'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'consignments') {
            return [];
        }
        $units = $this->number($record, '_units');
        $sold = $this->number($record, '_units_sold');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'With the dealer', 'icon' => 'boxes', 'stats' => [
            ['label' => 'Units placed', 'value' => $this->quantity($units)],
            ['label' => 'Sold', 'value' => $this->quantity($sold), 'tone' => 'success'],
            ['label' => 'Still with dealer', 'value' => $record->status === 'returned' ? '0' : $this->quantity(max(0, $units - $sold))],
            ['label' => 'Owed to us', 'value' => $this->money($this->number($record, 'sold_value') - $this->number($record, '_commission') - $this->number($record, '_paid')), 'tone' => 'warning'],
        ]]]];
    }

    public function homeCards(): array
    {
        $overdue = $this->records('consignments')->whereIn('status', ['placed', 'partly_sold'])->whereNotNull('due_on')->where('due_on', '<', today()->startOfDay())->with('contact')->orderBy('due_on')->get();
        $pending = $this->records('settlements')->where('status', 'pending')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Past settle-by date', 'icon' => 'alarm-clock', 'empty' => 'No consignments overdue.',
                'rows' => $overdue->map(fn (Record $consignment) => ['label' => $consignment->title, 'sub' => $consignment->contact?->name, 'value' => $this->money((float) $consignment->amount - $this->number($consignment, 'sold_value')).' unsold', 'href' => $consignment->url(), 'tone' => 'danger'])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Settlements', 'icon' => 'hand-coins', 'stats' => [
                ['label' => 'Waiting for payment', 'value' => $pending->count()],
                ['label' => 'Amount due', 'value' => $this->money($pending->sum('amount')), 'tone' => $pending->isNotEmpty() ? 'warning' : null],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $consignments = $this->dated('consignments', $from, $to)->with('contact')->get();

        $dealers = $consignments->groupBy(fn (Record $consignment) => $consignment->contact?->name ?? 'No dealer')->sortKeys()
            ->map(fn ($group, string $dealer) => [
                $dealer, $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $consignment) => $this->number($consignment, 'sold_value'))),
                $group->sum('amount') > 0 ? round($group->sum(fn (Record $consignment) => $this->number($consignment, 'sold_value')) / $group->sum('amount') * 100, 1).'%' : '—',
                $this->money($group->sum(fn (Record $consignment) => $this->number($consignment, '_commission'))),
            ])->values()->all();

        $settled = $this->sumByMonth($this->dated('settlements', $from, $to)->get());

        return [
            ['title' => 'Sell-through by dealer', 'columns' => ['Dealer', 'Consignments', 'Placed', 'Sold', 'Sell-through', 'Commission'], 'rows' => $dealers],
            ['title' => 'Settlements by month', 'columns' => ['Month', 'Amount due'], 'rows' => collect($this->months($from, $to))->map(fn (string $label, string $month) => [$label, $this->money($settled[$month] ?? 0)])->values()->all()],
        ];
    }
}
