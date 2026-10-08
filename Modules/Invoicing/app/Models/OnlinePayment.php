<?php

namespace Modules\Invoicing\Models;

use App\Support\Money;
use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Invoicing\Database\Factories\OnlinePaymentFactory;

/**
 * A customer's attempt to pay an invoice online. It stays pending until the gateway confirms
 * it, then records exactly one Payment against the invoice, however many times it is confirmed.
 */
class OnlinePayment extends Model
{
    /** @use HasFactory<OnlinePaymentFactory> */
    use BelongsToWorkspace, HasFactory;

    public const STATUSES = ['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'cancelled' => 'Cancelled'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'workspace_id', 'invoice_id', 'payment_id', 'uuid', 'gateway', 'amount', 'currency_code', 'status',
        'gateway_reference', 'poll_url', 'failure_reason', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'paid_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (OnlinePayment $attempt) {
            $attempt->uuid ??= (string) Str::uuid();
        });
    }

    protected static function newFactory(): OnlinePaymentFactory
    {
        return OnlinePaymentFactory::new();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('workspace');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScope('workspace');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function money(): string
    {
        return Money::format($this->amount, $this->currency_code);
    }

    /**
     * Record the money against the invoice. Safe to call from the return page and the webhook
     * at the same time: the row is locked, and a second call finds the payment already recorded.
     */
    public function markPaid(?string $gatewayReference = null): Payment
    {
        return DB::transaction(function () use ($gatewayReference) {
            $attempt = static::query()->allWorkspaces()->lockForUpdate()->findOrFail($this->id);
            if ($attempt->payment_id && ($payment = $attempt->payment()->first())) {
                $this->setRawAttributes($attempt->getAttributes(), true);

                return $payment;
            }

            $invoice = $attempt->invoice()->firstOrFail();
            $reference = $gatewayReference ?: $attempt->gateway_reference;
            $payment = Payment::create([
                'workspace_id' => $attempt->workspace_id,
                'invoice_id' => $invoice->id,
                'contact_id' => $invoice->contact_id,
                'currency_code' => $attempt->currency_code,
                'amount' => $attempt->amount,
                'paid_on' => today(),
                'method' => 'online',
                'reference' => Str::limit(ucfirst($attempt->gateway).($reference ? ' '.$reference : ''), 120, ''),
                'notes' => 'Paid online by the customer.',
            ]);

            $attempt->forceFill([
                'status' => 'paid', 'paid_at' => now(), 'payment_id' => $payment->id,
                'gateway_reference' => $reference, 'failure_reason' => null,
            ])->save();
            $this->setRawAttributes($attempt->getAttributes(), true);

            return $payment;
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
}
