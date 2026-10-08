<?php

namespace Modules\Invoicing\Models;

use App\Support\Sequence;
use App\Tenancy\BelongsToWorkspace;
use App\Tenancy\HasComments;
use App\Tenancy\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Database\Factories\QuoteFactory;
use Modules\Invoicing\Models\Concerns\IsSalesDocument;

class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use BelongsToWorkspace, HasComments, HasFactory, IsSalesDocument, RecordsActivity, SoftDeletes;

    public const STATUSES = [
        'draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'rejected' => 'Rejected',
        'expired' => 'Expired', 'converted' => 'Invoiced',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'workspace_id', 'branch_id', 'contact_id', 'number', 'status', 'issue_date', 'valid_until', 'currency_code', 'reference',
        'discount_type', 'discount_value', 'notes', 'terms', 'created_by',
    ];

    /** @var list<string> */
    protected array $activityAttributes = ['status', 'total', 'contact_id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'valid_until' => 'date', 'sent_at' => 'datetime', 'accepted_at' => 'datetime',
            'subtotal' => 'float', 'discount_value' => 'float', 'discount_amount' => 'float', 'tax_total' => 'float', 'total' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quote $quote) {
            $quote->number ??= Sequence::next('quote', 'QT-', $quote->workspace_id);
            $quote->valid_until ??= $quote->issue_date->copy()->addDays(30);
        });
    }

    protected static function newFactory(): QuoteFactory
    {
        return QuoteFactory::new();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('sort');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'sent', 'expired'], true);
    }

    public function isExpired(): bool
    {
        return in_array($this->status, ['sent'], true) && $this->valid_until && $this->valid_until->isPast() && ! $this->valid_until->isToday();
    }

    public static function refreshExpired(): void
    {
        static::query()->where('status', 'sent')->whereDate('valid_until', '<', today())->update(['status' => 'expired']);
    }

    public function markSent(): static
    {
        if ($this->status === 'draft') {
            $this->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        }

        return $this;
    }

    public function accept(): static
    {
        $this->forceFill(['status' => 'accepted', 'accepted_at' => now()])->save();

        return $this;
    }

    public function reject(): static
    {
        $this->forceFill(['status' => 'rejected'])->save();

        return $this;
    }

    /** Create an invoice carrying every line of this quote, and link the two. */
    public function convertToInvoice(): Invoice
    {
        if ($this->invoice_id && $this->invoice) {
            return $this->invoice;
        }

        return DB::transaction(function () {
            $invoice = Invoice::create([
                'workspace_id' => $this->workspace_id,
                'branch_id' => $this->branch_id,
                'contact_id' => $this->contact_id,
                'issue_date' => today(),
                'currency_code' => $this->currency_code,
                'reference' => $this->reference ?: $this->number,
                'discount_type' => $this->discount_type,
                'discount_value' => $this->discount_value,
                'notes' => $this->notes,
                'terms' => $this->terms,
                'quote_id' => $this->id,
            ]);

            $invoice->syncLines($this->lines()->get()->map(fn (QuoteLine $l) => $l->only(['item_id', 'description', 'quantity', 'unit', 'unit_price', 'tax_rate']))->all());

            $this->forceFill(['status' => 'converted', 'invoice_id' => $invoice->id])->save();

            return $invoice;
        });
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function activityLabel(): string
    {
        return 'Quote '.$this->number;
    }

    public function activityUrl(): string
    {
        return route('quotes.show', $this);
    }

    public function publicUrl(): string
    {
        return route('quotes.public', $this->uuid);
    }
}
