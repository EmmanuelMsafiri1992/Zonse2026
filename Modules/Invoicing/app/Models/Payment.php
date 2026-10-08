<?php

namespace Modules\Invoicing\Models;

use App\Models\User;
use App\Support\Money;
use App\Support\Sequence;
use App\Tenancy\BelongsToWorkspace;
use App\Tenancy\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Database\Factories\PaymentFactory;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToWorkspace, HasFactory, RecordsActivity;

    public const METHODS = [
        'cash' => 'Cash', 'bank' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'card' => 'Card',
        'cheque' => 'Cheque', 'other' => 'Other',
    ];

    protected $fillable = ['workspace_id', 'invoice_id', 'contact_id', 'number', 'amount', 'currency_code', 'paid_on', 'method', 'reference', 'notes', 'received_by'];

    /** @var list<string> */
    protected array $activityAttributes = ['amount', 'paid_on', 'method'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'paid_on' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            $payment->number ??= Sequence::next('payment', 'PAY-', $payment->workspace_id);
            $payment->received_by ??= auth()->id();
            $payment->paid_on ??= today();
            if ($payment->invoice) {
                $payment->contact_id ??= $payment->invoice->contact_id;
                $payment->currency_code ??= $payment->invoice->currency_code;
            }
        });

        $sync = fn (Payment $payment) => $payment->invoice?->refreshPaymentStatus();
        static::created($sync);
        static::updated($sync);
        static::deleted($sync);
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? ucfirst($this->method);
    }

    public function money(): string
    {
        return Money::format($this->amount, $this->currency_code);
    }

    public function activityLabel(): string
    {
        return 'Payment '.$this->number.' ('.$this->money().')';
    }

    public function activityUrl(): ?string
    {
        return $this->invoice_id ? route('invoices.show', $this->invoice_id) : null;
    }
}
