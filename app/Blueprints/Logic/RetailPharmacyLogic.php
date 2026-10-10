<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Retail pharmacy: a product's stock status follows its stock (low at 5 or fewer) unless it is recalled.
 * The scheduled-medicine register only takes scheduled products that are neither recalled nor expired,
 * never more than is on the shelf, and from schedule 3 up it needs the patient's ID and a prescription
 * number. Dispensing takes the quantity off stock and a return puts it back.
 */
class RetailPharmacyLogic extends AppLogic
{
    /**
     * Schedules that need an ID and a prescription.
     *
     * @var list<string>
     */
    public const PRESCRIPTION = ['s3', 's4', 's5', 's6'];

    public const LOW_STOCK = 5;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'products') {
            if ((float) ($data['stock'] ?? 0) < 0) {
                $errors['data.stock'] = 'Stock cannot be negative.';
            }

            return $errors;
        }
        if ($payload['status'] !== 'dispensed' || ! filled($data['product'] ?? null) || ! ($product = $this->records('products')->find($data['product']))) {
            return $errors;
        }
        $schedule = $product->value('schedule') ?: 'unscheduled';
        if ($schedule === 'unscheduled') {
            $errors['data.product'] = $product->title.' is not a scheduled medicine.';
        } elseif ($product->status === 'recalled') {
            $errors['data.product'] = $product->title.' has been recalled.';
        } elseif (filled($product->value('expiry_date')) && Carbon::parse($product->value('expiry_date'))->lt(today())) {
            $errors['data.product'] = $product->title.' expired on '.Carbon::parse($product->value('expiry_date'))->format('d M Y').'.';
        }
        $already = $existing && $existing->status === 'dispensed' && (int) $existing->value('product') === $product->id ? $this->number($existing, 'quantity') : 0;
        if ((float) ($data['quantity'] ?? 0) > $this->number($product, 'stock') + $already) {
            $errors['data.quantity'] = 'Only '.(int) ($this->number($product, 'stock') + $already).' of '.$product->title.' in stock.';
        }
        if (in_array($schedule, self::PRESCRIPTION, true)) {
            if (blank($data['id_number'] ?? null)) {
                $errors['data.id_number'] = 'Record the patient\'s ID for a '.strtoupper($schedule).' medicine.';
            }
            if (blank($data['prescription_number'] ?? null)) {
                $errors['data.prescription_number'] = 'A '.strtoupper($schedule).' medicine needs a prescription number.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'products') {
            if ($record->status === 'recalled') {
                return;
            }
            $stock = $this->number($record, 'stock');
            $record->status = $stock <= 0 ? 'out_of_stock' : ($stock <= self::LOW_STOCK ? 'low_stock' : 'in_stock');

            return;
        }
        $record->occurs_on ??= today();
        $this->moveStock($record);
    }

    /**
     * Keep the product's stock in step with what this register line has taken off the shelf.
     */
    protected function moveStock(Record $sale): void
    {
        $taken = (float) $sale->value('_taken');
        $takenFrom = (int) $sale->value('_taken_from');
        $product = $this->parent($sale, 'product');
        $wanted = $sale->status === 'dispensed' && $product ? $this->number($sale, 'quantity') : 0;
        if ($takenFrom && $takenFrom !== $product?->id && $taken > 0 && ($previous = $this->records('products')->find($takenFrom))) {
            $previous->update(['data' => [...$previous->data, 'stock' => $this->number($previous, 'stock') + $taken]]);
            $taken = 0;
        }
        if ($product && $wanted !== $taken) {
            $product->update(['data' => [...$product->data, 'stock' => max(0, $this->number($product, 'stock') - ($wanted - $taken))]]);
        }
        $this->put($sale, ['_taken' => $wanted, '_taken_from' => $product?->id]);
    }

    public function actions(Record $record): array
    {
        return $record->entity === 'scheduled_sales' && $record->status === 'dispensed' ? ['return' => ['label' => 'Returned', 'icon' => 'undo-2']] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $record->update(['status' => 'returned']);

        return (int) $this->number($record, 'quantity').' × '.($this->parent($record, 'product')?->title ?? 'medicine').' returned to stock.';
    }

    public function homeCards(): array
    {
        $expiring = $this->records('products')->where('status', '!=', 'recalled')->get()
            ->filter(fn (Record $product) => filled($product->value('expiry_date')) && Carbon::parse($product->value('expiry_date'))->lte(today()->addDays(60)))
            ->sortBy(fn (Record $product) => $product->value('expiry_date'));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Expired or expiring within 60 days', 'icon' => 'calendar-x', 'empty' => 'Nothing expires in the next 60 days.',
            'rows' => $expiring->map(function (Record $product) {
                $expiry = Carbon::parse($product->value('expiry_date'));

                return ['label' => $product->title, 'sub' => 'Batch '.($product->value('batch_number') ?: '—').' · '.(int) $this->number($product, 'stock').' in stock', 'value' => ($expiry->lt(today()) ? 'expired ' : '').$expiry->format('d M Y'), 'href' => $product->url(), 'tone' => $expiry->lt(today()) ? 'danger' : 'warning'];
            })->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $products = $this->records('products')->get()->keyBy('id');

        return [['title' => 'Scheduled register by schedule', 'columns' => ['Schedule', 'Dispensed', 'Units', 'Returned'], 'rows' => $this->dated('scheduled_sales', $from, $to)->get()
            ->groupBy(fn (Record $sale) => strtoupper((string) $products->get((int) $sale->value('product'))?->value('schedule')) ?: 'Unknown')->sortKeys()
            ->map(fn ($group, string $schedule) => [$schedule, $group->where('status', 'dispensed')->count(), (int) $group->where('status', 'dispensed')->sum(fn (Record $sale) => $this->number($sale, 'quantity')), $group->where('status', 'returned')->count()])
            ->values()->all()]];
    }
}
