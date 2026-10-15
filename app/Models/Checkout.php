<?php

namespace App\Models;

use App\Payments\PaymentAttempt;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A payment to Zonseob itself: a plan period for a workspace, or AI tokens for a person.
 * The USD price is kept as base_amount; amount is what the gateway actually charged, in its
 * own currency. It stays pending until a gateway or a platform admin confirms the money, and
 * what was bought is applied exactly once however many times that confirmation arrives.
 */
class Checkout extends Model implements PaymentAttempt
{
    public const STATUSES = ['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];

    public const PURPOSES = ['subscription' => 'Plan', 'tokens' => 'AI tokens'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'base_currency' => 'USD'];

    protected $fillable = [
        'uuid', 'user_id', 'workspace_id', 'purpose', 'subject', 'gateway', 'base_amount', 'base_currency',
        'amount', 'currency_code', 'status', 'gateway_reference', 'poll_url', 'failure_reason', 'note',
        'paid_at', 'fulfilled_at', 'confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'subject' => 'array',
            'base_amount' => 'float',
            'amount' => 'float',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Checkout $checkout) {
            $checkout->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isManual(): bool
    {
        return $this->gateway === 'manual';
    }

    /** What the person bought, in words. */
    public function description(): string
    {
        $subject = $this->subject ?? [];

        if ($this->purpose === 'tokens') {
            return number_format((int) ($subject['tokens'] ?? 0)).' Zonseob AI tokens';
        }

        $plan = Plan::query()->where('key', $subject['plan'] ?? '')->first();

        return ($plan?->name ?? 'Zonseob').' plan, '.(($subject['cycle'] ?? 'monthly') === 'yearly' ? 'one year' : 'one month');
    }

    // ---- PaymentAttempt -------------------------------------------------

    public function attemptUuid(): string
    {
        return $this->uuid;
    }

    public function attemptAmount(): float
    {
        return (float) $this->amount;
    }

    public function attemptCurrency(): string
    {
        return $this->currency_code;
    }

    public function pollUrl(): ?string
    {
        return $this->poll_url;
    }

    public function gatewayReference(): ?string
    {
        return $this->gateway_reference;
    }

    public function remember(array $attributes): void
    {
        $this->forceFill($attributes)->save();
    }

    public function money(): string
    {
        return Money::format($this->amount, $this->currency_code);
    }

    public function baseMoney(): string
    {
        return Money::format($this->base_amount, $this->base_currency);
    }

    /**
     * Mark the money as received. Returns true only for the call that moved it from pending,
     * so the caller applies the purchase once even when the return page and the webhook race.
     */
    public function markPaid(?string $gatewayReference = null, ?User $confirmedBy = null): bool
    {
        return DB::transaction(function () use ($gatewayReference, $confirmedBy) {
            $locked = static::query()->lockForUpdate()->findOrFail($this->id);
            if ($locked->status === 'paid') {
                $this->setRawAttributes($locked->getAttributes(), true);

                return false;
            }

            $locked->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'gateway_reference' => $gatewayReference ?: $locked->gateway_reference,
                'confirmed_by' => $confirmedBy?->id,
                'failure_reason' => null,
            ])->save();
            $this->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /** A declined or abandoned attempt. A paid one is never moved back. */
    public function markFailed(string $status, ?string $reason = null): void
    {
        if ($this->status === 'paid') {
            return;
        }

        $this->forceFill([
            'status' => $status === 'cancelled' ? 'cancelled' : 'failed',
            'failure_reason' => $reason ? Str::limit($reason, 500, '') : null,
        ])->save();
    }

    public function markFulfilled(): void
    {
        $this->forceFill(['fulfilled_at' => now()])->save();
    }
}
