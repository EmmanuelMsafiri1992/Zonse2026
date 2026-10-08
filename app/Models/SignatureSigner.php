<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Database\Factories\SignatureSignerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person asked to sign. They reach the document through their own secret link. */
class SignatureSigner extends Model
{
    /** @use HasFactory<SignatureSignerFactory> */
    use BelongsToWorkspace, HasFactory;

    /** @var array<string, string> */
    public const STATUSES = ['pending' => 'Not opened yet', 'viewed' => 'Opened', 'signed' => 'Signed', 'declined' => 'Declined'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'position' => 1];

    protected $fillable = [
        'workspace_id', 'signature_request_id', 'name', 'email', 'position', 'token', 'status',
        'signature_type', 'signature_data', 'signed_name', 'ip_address', 'user_agent',
        'notified_at', 'viewed_at', 'signed_at', 'declined_at', 'decline_reason',
    ];

    protected $hidden = ['token', 'signature_data'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'notified_at' => 'datetime',
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    public function hasSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function hasFinished(): bool
    {
        return in_array($this->status, ['signed', 'declined'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function signingUrl(): string
    {
        return route('signing.show', $this->token);
    }

    /** A drawn signature as a data URI ready for an <img>, or null for a typed one. */
    public function signatureImage(): ?string
    {
        return $this->signature_type === 'draw' && $this->signature_data ? 'data:image/png;base64,'.$this->signature_data : null;
    }
}
