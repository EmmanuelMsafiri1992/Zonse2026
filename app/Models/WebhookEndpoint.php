<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A URL outside Zonseob that is sent a signed POST when chosen events happen in the workspace. */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use BelongsToWorkspace, HasFactory;

    /** Consecutive failed deliveries before the endpoint is switched off. */
    public const DISABLE_AFTER_FAILURES = 15;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'failure_count' => 0];

    protected $fillable = ['workspace_id', 'url', 'description', 'events', 'secret', 'is_active', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array', 'secret' => 'encrypted', 'is_active' => 'boolean',
            'last_delivered_at' => 'datetime', 'disabled_at' => 'datetime', 'failure_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint) {
            $endpoint->secret ??= self::newSecret();
            $endpoint->created_by ??= auth()->id();
        });
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeListeningTo(Builder $query, string $event): Builder
    {
        return $query->where('is_active', true)->whereJsonContains('events', $event);
    }

    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }
}
