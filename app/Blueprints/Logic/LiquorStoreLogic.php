<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Liquor / bottle store: stock status follows stock (low at 12 or fewer), and a case costs more than a
 * single unit. An empties return needs at least one crate or bottle, and its deposit is worked out from
 * the crate and bottle deposit rates unless an amount is given.
 */
class LiquorStoreLogic extends AppLogic
{
    public const LOW_STOCK = 12;

    public const CRATE_DEPOSIT = 30;

    public const BOTTLE_DEPOSIT = 1;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'products') {
            if ((float) ($data['case_price'] ?? 0) > 0 && (float) $data['case_price'] <= (float) ($data['price'] ?? 0)) {
                $errors['data.case_price'] = 'A case must cost more than a single unit.';
            }
            if ((float) ($data['stock'] ?? 0) < 0) {
                $errors['data.stock'] = 'Stock cannot be negative.';
            }
        }
        if ($entity->key === 'empties') {
            if ((float) ($data['crates'] ?? 0) < 0 || (float) ($data['bottles'] ?? 0) < 0) {
                $errors['data.crates'] = 'Counts cannot be negative.';
            } elseif ((float) ($data['crates'] ?? 0) + (float) ($data['bottles'] ?? 0) <= 0) {
                $errors['data.crates'] = 'Count at least one crate or bottle.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'empties') {
            $record->occurs_on ??= today();
            if ((float) $record->amount <= 0) {
                $record->amount = $this->number($record, 'crates') * self::CRATE_DEPOSIT + $this->number($record, 'bottles') * self::BOTTLE_DEPOSIT;
            }

            return;
        }
        $stock = $this->number($record, 'stock');
        $record->status = $stock <= 0 ? 'out_of_stock' : ($stock <= self::LOW_STOCK ? 'low_stock' : 'in_stock');
    }

    public function homeCards(): array
    {
        $low = $this->records('products')->whereIn('status', ['low_stock', 'out_of_stock'])->get()->sortBy(fn (Record $product) => $this->number($product, 'stock'));
        $month = $this->records('empties')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->whereDate('occurs_on', '<=', today()->toDateString())->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reorder', 'icon' => 'wine', 'empty' => 'Every line has more than '.self::LOW_STOCK.' in stock.',
                'rows' => $low->map(fn (Record $product) => ['label' => $product->title, 'sub' => trim(ucfirst((string) $product->value('type')).' '.$product->value('size')), 'value' => (int) $this->number($product, 'stock').' left', 'href' => $product->url(), 'tone' => $product->status === 'out_of_stock' ? 'danger' : 'warning'])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Empties this month', 'icon' => 'recycle', 'stats' => [
                ['label' => 'Crates', 'value' => (int) $month->sum(fn (Record $return) => $this->number($return, 'crates'))],
                ['label' => 'Bottles', 'value' => (int) $month->sum(fn (Record $return) => $this->number($return, 'bottles'))],
                ['label' => 'Deposits back', 'value' => $this->money($month->sum('amount'))],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $empties = $this->dated('empties', $from, $to)->get();

        return [
            ['title' => 'Empties by month', 'columns' => ['Month', 'Crates', 'Bottles', 'Refunded in cash', 'Credited'], 'rows' => collect($this->months($from, $to))
                ->map(function (string $label, string $month) use ($empties) {
                    $inMonth = $empties->filter(fn (Record $return) => $return->occurs_on->format('Y-m') === $month);

                    return [$label, (int) $inMonth->sum(fn (Record $return) => $this->number($return, 'crates')), (int) $inMonth->sum(fn (Record $return) => $this->number($return, 'bottles')),
                        $this->money($inMonth->where('status', 'refunded')->sum('amount')), $this->money($inMonth->where('status', 'credited')->sum('amount'))];
                })->values()->all()],
            ['title' => 'Stock by type', 'columns' => ['Type', 'Lines', 'Units', 'Stock value'], 'rows' => $this->records('products')->get()
                ->groupBy(fn (Record $product) => ucfirst(str_replace('_', ' ', (string) $product->value('type'))))->sortKeys()
                ->map(fn ($group, string $type) => [$type, $group->count(), (int) $group->sum(fn (Record $product) => $this->number($product, 'stock')), $this->money($group->sum(fn (Record $product) => $this->number($product, 'stock') * $this->number($product, 'price')))])
                ->values()->all()],
        ];
    }
}
