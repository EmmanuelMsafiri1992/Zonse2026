<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Agro-dealer: a product's stock status follows its stock count, expired batches cannot be
 * listed as in stock, and credit sales to farmers need a pay-by date; the credit book shows
 * who still owes and who is past their harvest pay-by date.
 */
class AgroDealerLogic extends AppLogic
{
    public const LOW_STOCK = 10;

    public const EXPIRY_WARNING_DAYS = 30;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'sales' && $payload['status'] === 'on_credit') {
            $errors = [];
            if (blank($payload['due_on'] ?? null)) {
                $errors['due_on'] = 'Set the date the farmer will pay by.';
            }
            if (blank($payload['contact_id'] ?? null) && blank($payload['data']['phone'] ?? null)) {
                $errors['data.phone'] = 'Credit needs the farmer\'s contact or phone number.';
            }

            return $errors;
        }

        if ($entity->key === 'products' && ! $existing && filled($payload['data']['expiry_date'] ?? null) && (float) ($payload['data']['stock'] ?? 0) > 0
            && Carbon::parse($payload['data']['expiry_date'])->lt(today())) {
            return ['data.expiry_date' => 'This batch has already expired.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'products') {
            return;
        }

        $stock = $this->number($record, 'stock');
        $record->status = match (true) {
            $stock <= 0 => 'out_of_stock',
            $stock <= self::LOW_STOCK => 'low_stock',
            default => 'in_stock',
        };
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'products' || blank($record->value('expiry_date'))) {
            return [];
        }

        $expiry = Carbon::parse($record->value('expiry_date'));
        if ($expiry->gt(today()->addDays(self::EXPIRY_WARNING_DAYS))) {
            return [];
        }

        return [['view' => 'apps.logic.alert-card', 'data' => [
            'tone' => $expiry->lt(today()) ? 'danger' : 'warning', 'icon' => 'calendar-x',
            'title' => $expiry->lt(today()) ? 'Expired' : 'Expires soon',
            'body' => 'Batch '.($record->value('batch_number') ?? '—').' '.($expiry->lt(today()) ? 'expired' : 'expires').' on '.$expiry->format('d M Y').'. Take it off the shelf before it is sold.',
        ]]];
    }

    public function homeCards(): array
    {
        $low = $this->records('products')->whereIn('status', ['low_stock', 'out_of_stock'])->orderBy('title')->get();
        $expiring = $this->records('products')->get()->filter(fn (Record $product) => filled($product->value('expiry_date'))
            && Carbon::parse($product->value('expiry_date'))->lte(today()->addDays(self::EXPIRY_WARNING_DAYS)) && $this->number($product, 'stock') > 0);
        $credit = $this->records('sales')->where('status', 'on_credit')->get();
        $overdue = $credit->filter(fn (Record $sale) => $sale->due_on?->lt(today()));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Farmer credit', 'icon' => 'hand-coins', 'stats' => [
                ['label' => 'On credit', 'value' => $this->money($credit->sum('amount'))],
                ['label' => 'Past pay-by date', 'value' => $overdue->count().' · '.$this->money($overdue->sum('amount')), 'tone' => $overdue->count() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reorder', 'icon' => 'package', 'empty' => 'Stock levels are fine.',
                'rows' => $low->map(fn (Record $product) => ['label' => $product->title, 'sub' => $product->value('pack_size'), 'value' => (int) $this->number($product, 'stock').' left', 'href' => $product->url(), 'tone' => $product->status === 'out_of_stock' ? 'danger' : 'warning'])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Expiring stock', 'icon' => 'calendar-x', 'empty' => 'Nothing expires in the next 30 days.',
                'rows' => $expiring->sortBy(fn (Record $product) => $product->value('expiry_date'))->map(fn (Record $product) => [
                    'label' => $product->title, 'sub' => 'Batch '.($product->value('batch_number') ?? '—'), 'value' => Carbon::parse($product->value('expiry_date'))->format('d M Y'), 'href' => $product->url(),
                    'tone' => Carbon::parse($product->value('expiry_date'))->lt(today()) ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sales = $this->dated('sales', $from, $to)->with('contact')->get();
        $monthly = collect($this->months($from, $to))->map(function ($label, $month) use ($sales) {
            $inMonth = $sales->filter(fn (Record $sale) => ($sale->occurs_on ?? $sale->created_at)->format('Y-m') === $month);

            return [$label, $inMonth->count(), $this->money($inMonth->whereIn('status', ['paid', 'settled'])->sum('amount')), $this->money($inMonth->where('status', 'on_credit')->sum('amount'))];
        })->values()->all();

        $book = $this->records('sales')->where('status', 'on_credit')->with('contact')->orderBy('due_on')->get()->map(fn (Record $sale) => [
            $sale->contact?->name ?? $sale->title, (string) ($sale->value('phone') ?? '—'), $sale->occurs_on?->format('d M Y') ?? '—', $sale->due_on?->format('d M Y') ?? '—', $this->money($sale->amount),
        ])->all();

        $stock = $this->records('products')->get()->groupBy(fn (Record $product) => (string) $product->value('type'))->sortKeys()
            ->map(fn ($group, $type) => [ucfirst(str_replace('_', ' ', $type)), $group->count(), $this->money($group->sum(fn (Record $product) => $this->number($product, 'stock') * $this->number($product, 'price')))])->values()->all();

        return [
            ['title' => 'Sales by month', 'columns' => ['Month', 'Sales', 'Cash', 'On credit'], 'rows' => $monthly],
            ['title' => 'Credit book', 'columns' => ['Farmer', 'Phone', 'Sold on', 'Pay by', 'Owed'], 'rows' => $book],
            ['title' => 'Stock value', 'columns' => ['Type', 'Products', 'Value at selling price'], 'rows' => $stock],
        ];
    }
}
