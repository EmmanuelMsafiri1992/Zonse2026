<?php

namespace App\Models;

use App\Support\Fiscal\Authorities;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\FiscalDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoicing\Models\Invoice;

/**
 * One invoice or credit note as reported to the tax authority. Documents form a hash chain per
 * workspace: each hash covers the document's payload and the previous document's hash, so an
 * edited or removed document breaks every link after it.
 */
class FiscalDocument extends Model
{
    /** @use HasFactory<FiscalDocumentFactory> */
    use BelongsToWorkspace, HasFactory;

    public const TYPES = ['invoice' => 'Invoice', 'credit_note' => 'Credit note'];

    public const STATUSES = ['pending' => 'Waiting to send', 'signed' => 'Accepted', 'rejected' => 'Rejected'];

    protected $fillable = [
        'workspace_id', 'invoice_id', 'type', 'authority', 'counter', 'fiscal_number', 'verification_code', 'status',
        'payload', 'previous_hash', 'hash', 'authority_reference', 'attempts', 'last_error', 'signed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'counter' => 'integer', 'attempts' => 'integer', 'signed_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function authorityName(): string
    {
        return Authorities::get($this->authority)['name'] ?? strtoupper($this->authority);
    }

    public function verifyUrl(): string
    {
        return route('fiscal.verify', $this->verification_code);
    }

    /** What the document's QR code carries: the authority's own format where it has one, otherwise the verification link. */
    public function qrData(): string
    {
        return Authorities::qrData($this);
    }
}
