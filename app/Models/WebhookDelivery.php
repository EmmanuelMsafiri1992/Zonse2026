<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One attempt to tell an endpoint about an event, kept so people can see what was sent and resend it. */
class WebhookDelivery extends Model
{
    use BelongsToWorkspace;

    public const STATUSES = ['pending' => 'Sending', 'succeeded' => 'Delivered', 'failed' => 'Failed'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected $fillable = [
        'workspace_id', 'webhook_endpoint_id', 'event', 'payload', 'status', 'response_status', 'response_body', 'error',
        'attempts', 'delivered_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime', 'attempts' => 'integer', 'response_status' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookDelivery $delivery) {
            $delivery->uuid ??= (string) Str::uuid();
        });
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
