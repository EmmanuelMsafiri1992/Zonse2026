<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A personal API key. It acts as the person who made it, but only inside the
 * one workspace it was made for, and only with the access it was given.
 */
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** What a key may do; "write" keys can also read. */
    public const ACCESS = [
        'read' => ['label' => 'Read only', 'abilities' => ['read']],
        'write' => ['label' => 'Read and write', 'abilities' => ['read', 'write']],
    ];

    public const EXPIRY_DAYS = ['30' => '30 days', '90' => '90 days', '365' => '1 year', '' => 'Never'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function accessLabel(): string
    {
        return $this->can('write') ? self::ACCESS['write']['label'] : self::ACCESS['read']['label'];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
