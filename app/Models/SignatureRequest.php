<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Database\Factories\SignatureRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A PDF sent out to be signed. The file is kept privately with its SHA-256 fingerprint,
 * so the completion certificate can prove what was signed was never changed.
 */
class SignatureRequest extends Model
{
    /** @use HasFactory<SignatureRequestFactory> */
    use BelongsToWorkspace, HasFactory;

    /** @var array<string, string> */
    public const STATUSES = [
        'pending' => 'Waiting for signatures',
        'completed' => 'Signed',
        'declined' => 'Declined',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ];

    /** @var array<string, string> */
    public const ORDERS = [
        'parallel' => 'Everyone at once',
        'sequential' => 'One after another, in order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'signing_order' => 'parallel'];

    protected $fillable = [
        'workspace_id', 'uuid', 'title', 'message', 'status', 'signing_order', 'signable_type', 'signable_id',
        'document_name', 'document_path', 'document_hash', 'document_size', 'certificate_path',
        'created_by', 'expires_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'completed_at' => 'datetime', 'document_size' => 'integer'];
    }

    public function signers(): HasMany
    {
        return $this->hasMany(SignatureSigner::class)->orderBy('position')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignatureEvent::class)->orderBy('id');
    }

    /** The document it was made from, such as a quote; it may since have been deleted. */
    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSequential(): bool
    {
        return $this->signing_order === 'sequential';
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /** For x-pill, which colours by status name. */
    public function pillStatus(): string
    {
        return $this->status === 'declined' ? 'rejected' : $this->status;
    }

    public function activityUrl(): string
    {
        return route('signatures.show', $this);
    }
}
