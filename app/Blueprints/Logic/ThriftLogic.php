<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Second-hand / consignment / thrift: each consignor takes an agreed share of what their items sell for.
 * An item goes back after 60 days unless a return date is set. A sold item sells for its asking price
 * unless another price is given, and works out the consignor's payout and the shop's share. Only sold items
 * can be paid out, a sold item cannot be returned or donated, and new items need an active consignor.
 */
class ThriftLogic extends AppLogic
{
    public const RETURN_AFTER_DAYS = 60;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'consignors') {
            $split = (float) ($data['split_percent'] ?? 0);
            if ($split < 0 || $split > 100) {
                $errors['data.split_percent'] = 'The consignor share must be between 0 and 100%.';
            }

            return $errors;
        }
        $consignor = filled($data['consignor'] ?? null) ? $this->records('consignors')->find($data['consignor']) : null;
        if (! $existing && $consignor && $consignor->status !== 'active') {
            $errors['data.consignor'] = $consignor->title.' is inactive; reactivate them to take new items.';
        }
        if (! empty($data['paid_out']) && $payload['status'] !== 'sold') {
            $errors['data.paid_out'] = 'Only sold items can be paid out.';
        }
        if ($existing?->status === 'sold' && in_array($payload['status'], ['returned', 'donated'], true)) {
            $errors['status'] = 'This item is sold; it cannot be '.$payload['status'].'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'items') {
            return;
        }
        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDays(self::RETURN_AFTER_DAYS);
        if ($record->status !== 'sold') {
            $this->put($record, ['_sold_on' => null, '_payout' => 0, '_shop_share' => 0, 'paid_out' => false]);

            return;
        }
        if ((float) $record->amount <= 0) {
            $record->amount = $this->number($record, 'price');
        }
        $split = $this->number($this->parent($record, 'consignor') ?? new Record, 'split_percent');
        $payout = round((float) $record->amount * $split / 100, 2);
        $this->put($record, ['_sold_on' => $record->value('_sold_on') ?? today()->toDateString(), '_payout' => $payout, '_shop_share' => round((float) $record->amount - $payout, 2)]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'consignors') {
            return $this->owed($record)->isNotEmpty() ? ['pay_all' => ['label' => 'Pay out sold items', 'icon' => 'banknote']] : [];
        }

        return match ($record->status) {
            'received', 'listed' => [
                ...($record->status === 'received' ? ['list' => ['label' => 'On the floor', 'icon' => 'tag']] : []),
                'sell' => ['label' => 'Sold', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Sold for', 'type' => 'number', 'value' => $this->number($record, 'price')]]],
                'return' => ['label' => 'Returned to consignor', 'icon' => 'undo-2'],
                'donate' => ['label' => 'Donated', 'icon' => 'heart'],
            ],
            'sold' => $record->value('paid_out') ? [] : ['pay_out' => ['label' => 'Consignor paid', 'icon' => 'banknote']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'list':
                $record->update(['status' => 'listed']);

                return $record->title.' is on the floor at '.$this->money($this->number($record, 'price')).'.';
            case 'sell':
                $price = (float) ($request->validate(['amount' => ['nullable', 'numeric', 'min:0']])['amount'] ?? 0) ?: $this->number($record, 'price');
                $record->update(['status' => 'sold', 'amount' => $price]);

                return $record->title.' sold for '.$this->money($price).'; '.$this->money($this->number($record, '_payout')).' owed to '.($this->parent($record, 'consignor')?->title ?? 'the consignor').'.';
            case 'return':
            case 'donate':
                $record->update(['status' => $action === 'return' ? 'returned' : 'donated']);

                return $record->title.($action === 'return' ? ' returned to the consignor.' : ' donated.');
            case 'pay_out':
                $record->update(['data' => [...$record->data, 'paid_out' => true]]);

                return 'Paid '.($this->parent($record, 'consignor')?->title ?? 'the consignor').' '.$this->money($this->number($record, '_payout')).' for '.$record->title.'.';
            default:
                $items = $this->owed($record);
                if ($items->isEmpty()) {
                    throw ValidationException::withMessages(['status' => $record->title.' is owed nothing.']);
                }
                $items->each(fn (Record $item) => $item->update(['data' => [...$item->data, 'paid_out' => true]]));

                return 'Paid '.$record->title.' '.$this->money($items->sum(fn (Record $item) => $this->number($item, '_payout'))).' for '.$items->count().' '.str('item')->plural($items->count()).'.';
        }
    }

    /**
     * A consignor's sold items that have not been paid out yet.
     *
     * @return Collection<int, Record>
     */
    protected function owed(Record $consignor)
    {
        return $this->linked('items', 'consignor', $consignor)->where('status', 'sold')->get()->reject(fn (Record $item) => $item->value('paid_out'))->values();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'consignors') {
            return [];
        }
        $items = $this->linked('items', 'consignor', $record)->orderByDesc('occurs_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Owed '.$this->money($this->owed($record)->sum(fn (Record $item) => $this->number($item, '_payout'))), 'icon' => 'shirt', 'empty' => 'No items yet.',
            'rows' => $items->map(fn (Record $item) => ['label' => $item->title, 'sub' => ucfirst($item->status).($item->status === 'sold' ? ($item->value('paid_out') ? ' · paid out' : ' · not paid out') : ''), 'value' => $item->status === 'sold' ? $this->money($this->number($item, '_payout')) : $this->money($this->number($item, 'price')), 'href' => $item->url(), 'tone' => $item->status === 'sold' && ! $item->value('paid_out') ? 'warning' : null])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $floor = $this->records('items')->whereIn('status', ['received', 'listed'])->get();
        $owed = $this->records('items')->where('status', 'sold')->get()->reject(fn (Record $item) => $item->value('paid_out'));
        $due = $floor->filter(fn (Record $item) => $item->due_on && $item->due_on->lt(today()))->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Consignment', 'icon' => 'shirt', 'stats' => [
                ['label' => 'Items on hand', 'value' => $floor->count()],
                ['label' => 'Owed to consignors', 'value' => $this->money($owed->sum(fn (Record $item) => $this->number($item, '_payout')))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Past their return date', 'icon' => 'undo-2', 'empty' => 'No items are past their return date.',
                'rows' => $due->map(fn (Record $item) => ['label' => $item->title, 'sub' => $this->parent($item, 'consignor')?->title, 'value' => 'since '.$item->due_on->format('d M'), 'href' => $item->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sold = $this->records('items')->where('status', 'sold')->get()
            ->filter(fn (Record $item) => $item->value('_sold_on') && Carbon::parse($item->value('_sold_on'))->betweenIncluded($from->copy()->startOfDay(), $to->copy()->endOfDay()));

        return [['title' => 'Consignment sales by month', 'columns' => ['Month', 'Items sold', 'Sales', 'Consignor share', 'Shop share'], 'rows' => collect($this->months($from, $to))
            ->map(function (string $label, string $month) use ($sold) {
                $inMonth = $sold->filter(fn (Record $item) => substr((string) $item->value('_sold_on'), 0, 7) === $month);

                return [$label, $inMonth->count(), $this->money($inMonth->sum('amount')), $this->money($inMonth->sum(fn (Record $item) => $this->number($item, '_payout'))), $this->money($inMonth->sum(fn (Record $item) => $this->number($item, '_shop_share')))];
            })->values()->all()]];
    }
}
