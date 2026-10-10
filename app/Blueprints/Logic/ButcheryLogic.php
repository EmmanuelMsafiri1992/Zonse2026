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
 * Butchery with scale integration: a weighed sale is always its weight times the price per kg. When
 * the scale's label barcode is scanned into the scale ticket (an EAN-13 starting with 2: flag, 5-digit
 * PLU, weight in grams and a check digit) the check digit is verified and the weight must match the
 * label. A carcass's yield can't exceed its hanging weight, and its cost per kg is worked out on the
 * yield. Meat sold is taken from the oldest cut carcasses first, so each carcass shows what is left
 * and goes sold out when it is used up; a refund puts the meat back.
 */
class ButcheryLogic extends AppLogic
{
    /**
     * Set while carcasses are being updated from the sales, so their own saves don't start it again.
     */
    protected static bool $allocating = false;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'carcasses') {
            if (filled($data['yield'] ?? null) && (float) $data['yield'] > (float) ($data['weight'] ?? 0)) {
                $errors['data.yield'] = 'The yield cannot be more than the hanging weight.';
            }
            if (in_array($payload['status'], ['cut', 'sold_out'], true) && (float) ($data['yield'] ?? 0) <= 0) {
                $errors['data.yield'] = 'Weigh the cut meat to record the yield.';
            }

            return $errors;
        }

        if ((float) ($data['weight'] ?? 0) <= 0) {
            $errors['data.weight'] = 'The weight must be more than zero.';
        }
        if ((float) ($data['price_per_kg'] ?? 0) <= 0) {
            $errors['data.price_per_kg'] = 'The price per kg must be more than zero.';
        }
        $ticket = preg_replace('/\s+/', '', (string) ($data['scale_ticket'] ?? ''));
        if (preg_match('/^2\d{12}$/', $ticket)) {
            if (! $this->checksumValid($ticket)) {
                $errors['data.scale_ticket'] = 'The scale label didn\'t scan correctly (check digit wrong); scan it again.';
            } elseif (abs($this->labelWeight($ticket) - (float) ($data['weight'] ?? 0)) > 0.0005) {
                $errors['data.weight'] = 'The scale label says '.number_format($this->labelWeight($ticket), 3).' kg.';
            }
        }
        if ($existing && $existing->status === 'refunded' && $payload['status'] !== 'refunded') {
            $errors['status'] = 'This sale was refunded.';
        }

        return $errors;
    }

    /**
     * Whether an EAN-13's last digit matches the first twelve.
     */
    protected function checksumValid(string $barcode): bool
    {
        $sum = 0;
        foreach (str_split(substr($barcode, 0, 12)) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) $barcode[12];
    }

    /**
     * The weight in kg printed into a scale label (digits 8–12, in grams).
     */
    protected function labelWeight(string $barcode): float
    {
        return (int) substr($barcode, 7, 5) / 1000;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'sales') {
            $record->amount = round($this->number($record, 'weight') * $this->number($record, 'price_per_kg'), 2);
            $ticket = preg_replace('/\s+/', '', (string) $record->value('scale_ticket'));
            $this->put($record, ['_plu' => preg_match('/^2\d{12}$/', $ticket) ? substr($ticket, 2, 5) : null]);

            return;
        }

        $yield = $this->number($record, 'yield');
        $weight = $this->number($record, 'weight');
        $this->put($record, [
            '_yield_pct' => $weight > 0 && $yield > 0 ? round($yield / $weight * 100, 1) : null,
            '_cost_per_kg' => $yield > 0 && $record->amount !== null ? round((float) $record->amount / $yield, 2) : null,
        ]);
        if ($record->status === 'hanging' || $record->value('_remaining') === null) {
            $this->put($record, ['_remaining' => $record->status === 'hanging' ? null : $yield]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'sales' || $record->wasRecentlyCreated || $record->wasChanged(['status', 'data'])) {
            $this->allocate();
        }
    }

    public function deleted(Record $record): void
    {
        $this->allocate();
    }

    /**
     * Take the meat sold from the oldest cut carcasses first and mark the used-up ones sold out.
     */
    protected function allocate(): void
    {
        if (static::$allocating) {
            return;
        }
        static::$allocating = true;

        try {
            $sold = (float) $this->records('sales')->where('status', 'completed')->get()->sum(fn (Record $sale) => $this->number($sale, 'weight'));
            $this->records('carcasses')->whereIn('status', ['cut', 'sold_out'])->orderBy('occurs_on')->orderBy('id')->get()
                ->each(function (Record $carcass) use (&$sold) {
                    $yield = $this->number($carcass, 'yield');
                    $taken = min($yield, max(0, $sold));
                    $sold -= $taken;
                    $remaining = round($yield - $taken, 3);
                    $status = $remaining <= 0 ? 'sold_out' : 'cut';
                    if ($carcass->status !== $status || (float) $carcass->value('_remaining') !== $remaining) {
                        $carcass->update(['status' => $status, 'data' => [...$carcass->data, '_remaining' => $remaining]]);
                    }
                });
        } finally {
            static::$allocating = false;
        }
    }

    /**
     * Kilograms of cut meat still to sell.
     */
    protected function inStock(): float
    {
        return round((float) $this->records('carcasses')->where('status', 'cut')->get()->sum(fn (Record $carcass) => $this->number($carcass, '_remaining')), 3);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'sales') {
            return $record->status === 'completed' ? ['refund' => ['label' => 'Refund', 'icon' => 'undo-2', 'confirm' => 'Refund this sale and put the meat back in stock?']] : [];
        }

        return $record->status === 'hanging' ? ['cut' => ['label' => 'Cut up', 'icon' => 'scissors', 'fields' => [['name' => 'yield', 'label' => 'Meat yield (kg)', 'type' => 'number']]]] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'refund') {
            $record->update(['status' => 'refunded']);

            return $this->money($record->amount).' refunded; '.number_format($this->number($record, 'weight'), 3).' kg back in stock.';
        }

        $yield = (float) $request->validate(['yield' => ['required', 'numeric', 'gt:0']])['yield'];
        if ($yield > $this->number($record, 'weight')) {
            throw ValidationException::withMessages(['yield' => 'The yield cannot be more than the '.$this->number($record, 'weight').' kg hanging weight.']);
        }
        $record->update(['status' => 'cut', 'data' => [...$record->data, 'yield' => $yield, '_remaining' => $yield]]);
        $record = $record->fresh();

        return $record->title.' cut: '.number_format($yield, 1).' kg ('.$record->value('_yield_pct').'% yield)'.($record->value('_cost_per_kg') !== null ? ' at '.$this->money($record->value('_cost_per_kg')).' per kg.' : '.');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'carcasses' || $record->status === 'hanging') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Meat', 'icon' => 'beef', 'stats' => [
            ['label' => 'Yield', 'value' => number_format($this->number($record, 'yield'), 1).' kg ('.$record->value('_yield_pct').'%)'],
            ['label' => 'Left to sell', 'value' => number_format($this->number($record, '_remaining'), 1).' kg', 'tone' => $record->status === 'sold_out' ? 'danger' : null],
            ['label' => 'Cost per kg', 'value' => $record->value('_cost_per_kg') !== null ? $this->money($record->value('_cost_per_kg')) : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $today = $this->records('sales')->where('status', 'completed')->whereDate('occurs_on', today())->get();
        $hanging = $this->records('carcasses')->where('status', 'hanging')->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Counter', 'icon' => 'weight', 'stats' => [
            ['label' => 'Meat in stock', 'value' => number_format($this->inStock(), 1).' kg'],
            ['label' => 'Hanging', 'value' => $hanging->count().' · '.number_format($hanging->sum(fn (Record $carcass) => $this->number($carcass, 'weight')), 1).' kg'],
            ['label' => 'Sold today', 'value' => number_format($today->sum(fn (Record $sale) => $this->number($sale, 'weight')), 1).' kg · '.$this->money($today->sum('amount'))],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $carcasses = $this->dated('carcasses', $from, $to)->whereIn('status', ['cut', 'sold_out'])->get();
        $sales = $this->dated('sales', $from, $to)->where('status', 'completed')->get();

        $yields = $carcasses->groupBy(fn (Record $carcass) => (string) $carcass->value('animal'))->sortKeys()
            ->map(function (Collection $group, string $animal) {
                $hanging = $group->sum(fn (Record $carcass) => $this->number($carcass, 'weight'));
                $yield = $group->sum(fn (Record $carcass) => $this->number($carcass, 'yield'));

                return [ucfirst($animal), $group->count(), number_format($hanging, 1), number_format($yield, 1), $hanging > 0 ? round($yield / $hanging * 100, 1).'%' : '—', $yield > 0 ? $this->money($group->sum('amount') / $yield) : '—'];
            })->values()->all();

        $cuts = $sales->groupBy(fn (Record $sale) => mb_strtolower(trim($sale->title)))->sortKeys()
            ->map(function (Collection $group) {
                $kilos = $group->sum(fn (Record $sale) => $this->number($sale, 'weight'));

                return [$group->first()->title, $group->count(), number_format($kilos, 3), $this->money($group->sum('amount')), $this->money($kilos > 0 ? $group->sum('amount') / $kilos : 0)];
            })->values()->all();

        $kilos = $sales->sum(fn (Record $sale) => $this->number($sale, 'weight'));
        $costed = $carcasses->filter(fn (Record $carcass) => $carcass->amount !== null);
        $costPerKg = $costed->sum(fn (Record $carcass) => $this->number($carcass, 'yield')) > 0 ? $costed->sum('amount') / $costed->sum(fn (Record $carcass) => $this->number($carcass, 'yield')) : 0;
        $revenue = $sales->sum('amount');

        return [
            ['title' => 'Yield by animal', 'columns' => ['Animal', 'Carcasses', 'Hanging kg', 'Yield kg', 'Yield', 'Cost per kg'], 'rows' => $yields],
            ['title' => 'Sales by cut', 'columns' => ['Cut', 'Sales', 'Kg', 'Revenue', 'Average per kg'], 'rows' => $cuts],
            ['title' => 'Gross margin', 'columns' => ['Kg sold', 'Revenue', 'Meat cost', 'Margin'], 'rows' => [[
                number_format($kilos, 3), $this->money($revenue), $this->money($kilos * $costPerKg), $revenue > 0 ? round(($revenue - $kilos * $costPerKg) / $revenue * 100, 1).'%' : '—',
            ]]],
        ];
    }
}
