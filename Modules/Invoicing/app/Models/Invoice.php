<?php

namespace Modules\Invoicing\Models;

use App\Models\Record;
use App\Support\Money;
use App\Support\Sequence;
use App\Tenancy\BelongsToWorkspace;
use App\Tenancy\HasComments;
use App\Tenancy\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Invoicing\Database\Factories\InvoiceFactory;
use Modules\Invoicing\Models\Concerns\IsSalesDocument;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToWorkspace, HasComments, HasFactory, IsSalesDocument, RecordsActivity, SoftDeletes;

    public const STATUSES = [
        'draft' => 'Draft', 'sent' => 'Sent', 'partial' => 'Partially paid', 'paid' => 'Paid',
        'overdue' => 'Overdue', 'cancelled' => 'Cancelled',
    ];

    public const OPEN_STATUSES = ['sent', 'partial', 'overdue'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    protected $fillable = [
        'workspace_id', 'branch_id', 'contact_id', 'number', 'status', 'issue_date', 'due_date', 'currency_code', 'reference',
        'discount_type', 'discount_value', 'notes', 'terms', 'quote_id', 'record_id', 'period', 'created_by',
    ];

    /** @var list<string> */
    protected array $activityAttributes = ['status', 'total', 'due_date', 'contact_id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'due_date' => 'date', 'sent_at' => 'datetime', 'paid_at' => 'datetime',
            'subtotal' => 'float', 'discount_value' => 'float', 'discount_amount' => 'float', 'tax_total' => 'float',
            'total' => 'float', 'amount_paid' => 'float', 'balance' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            $invoice->number ??= Sequence::next('invoice', 'INV-', $invoice->workspace_id);
            $invoice->due_date ??= $invoice->issue_date->copy()->addDays((int) ($invoice->workspace?->setting('invoicing.due_days', 14) ?? 14));
        });

        // Payments move the invoice's status; the app record it came from (a fee, a visit) follows.
        static::saved(function (Invoice $invoice) {
            if ($invoice->record_id && ($invoice->wasRecentlyCreated || $invoice->wasChanged(['status', 'amount_paid']))) {
                $record = $invoice->record()->first();
                $record?->appLogic()?->invoiceChanged($record, $invoice);
            }
        });
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('paid_on')->latest('id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** The app record (visit, lease, fee, sale…) this invoice was raised from, if any. */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES)->whereDate('due_date', '<', today());
    }

    /** Flip "sent"/"partial" invoices past their due date to overdue. Cheap enough to run on every list load. */
    public static function refreshOverdue(): void
    {
        static::query()->whereIn('status', ['sent', 'partial'])->whereDate('due_date', '<', today())->update(['status' => 'overdue']);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'sent', 'overdue'], true) && $this->amount_paid <= 0;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->balance > 0 && $this->due_date->isPast() && ! $this->due_date->isToday();
    }

    /** Recompute amount paid, balance and status from payments. */
    public function refreshPaymentStatus(bool $save = true): static
    {
        $paid = Money::round($this->payments()->sum('amount'));
        $balance = Money::round((float) $this->total - $paid);

        $status = $this->status ?? 'draft';
        if ($status !== 'cancelled') {
            if ($paid > 0 && $balance <= 0) {
                $status = 'paid';
            } elseif ($paid > 0) {
                $status = 'partial';
            } elseif ($status === 'paid' || $status === 'partial') {
                $status = 'sent';
            }
            if (in_array($status, ['sent', 'partial'], true) && $this->due_date && $this->due_date->isPast() && ! $this->due_date->isToday()) {
                $status = 'overdue';
            }
        }

        $this->forceFill([
            'amount_paid' => $paid,
            'balance' => $balance,
            'status' => $status,
            'paid_at' => $status === 'paid' ? ($this->paid_at ?? now()) : null,
        ]);

        if ($save) {
            $this->save();
        }

        return $this;
    }

    public function markSent(): static
    {
        if ($this->status === 'draft') {
            $this->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
            $this->refreshPaymentStatus();
        }

        return $this;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function activityLabel(): string
    {
        return 'Invoice '.$this->number;
    }

    public function activityUrl(): string
    {
        return route('invoices.show', $this);
    }

    public function publicUrl(): string
    {
        return route('invoices.public', $this->uuid);
    }
}
