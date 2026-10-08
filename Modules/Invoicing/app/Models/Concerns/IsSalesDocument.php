<?php

namespace Modules\Invoicing\Models\Concerns;

use App\Models\Branch;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Contacts\Models\Contact;

/**
 * Shared behaviour for invoices and quotes: contact, branch, lines, totals,
 * discounts and the public UUID.
 */
trait IsSalesDocument
{
    public static function bootIsSalesDocument(): void
    {
        static::creating(function ($document) {
            $document->uuid ??= (string) Str::uuid();
            $document->created_by ??= auth()->id();
            $document->currency_code ??= $document->contact?->currency_code ?? $document->workspace?->currency_code ?? 'USD';
            $document->issue_date ??= today();
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    abstract public function lines(): HasMany;

    /**
     * Replace all lines and recompute totals.
     *
     * @param  list<array{description: string, quantity: float|string, unit_price: float|string, tax_rate?: float|string|null, item_id?: int|null, unit?: string|null}>  $lines
     */
    public function syncLines(array $lines): static
    {
        $this->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $quantity = (float) ($line['quantity'] ?? 1);
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $taxRate = (float) ($line['tax_rate'] ?? 0);
            $lineTotal = Money::round($quantity * $unitPrice);

            $this->lines()->create([
                'item_id' => $line['item_id'] ?? null,
                'description' => $line['description'],
                'quantity' => $quantity,
                'unit' => $line['unit'] ?? null,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'line_total' => $lineTotal,
                'tax_amount' => Money::round($lineTotal * $taxRate / 100),
                'sort' => $index,
            ]);
        }

        return $this->recalculate();
    }

    /** Recompute subtotal, discount, tax and total from the stored lines. */
    public function recalculate(): static
    {
        $lines = $this->lines()->get();
        $subtotal = Money::round($lines->sum('line_total'));

        $discount = match ($this->discount_type) {
            'percent' => Money::round($subtotal * min(100, max(0, (float) $this->discount_value)) / 100),
            'fixed' => Money::round(min($subtotal, max(0, (float) $this->discount_value))),
            default => 0.0,
        };
        $ratio = $subtotal > 0 ? ($subtotal - $discount) / $subtotal : 1;
        $tax = Money::round($lines->sum('tax_amount') * $ratio);

        $this->forceFill([
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'tax_total' => $tax,
            'total' => Money::round($subtotal - $discount + $tax),
        ]);

        if (method_exists($this, 'refreshPaymentStatus')) {
            $this->refreshPaymentStatus(false);
        }
        $this->save();

        return $this;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('number', 'like', '%'.$term.'%')
                ->orWhere('reference', 'like', '%'.$term.'%')
                ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', '%'.$term.'%')->orWhere('company_name', 'like', '%'.$term.'%'));
        });
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopeForContact(Builder $query, int|string|null $contactId): Builder
    {
        return $contactId ? $query->where('contact_id', $contactId) : $query;
    }

    public function money(float|int|string|null $amount): string
    {
        return Money::format($amount, $this->currency_code);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
