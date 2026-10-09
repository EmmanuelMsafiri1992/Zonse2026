<?php

namespace App\Models;

use App\Inbox\ChannelDriver;
use App\Inbox\Inbox;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\InboxChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One place customers write in: an email address, an SMS number, a WhatsApp number, a Facebook
 * page or an Instagram account. The random token in its webhook URL identifies it to the provider.
 * In test mode replies are recorded but never leave the server.
 */
class InboxChannel extends Model
{
    /** @use HasFactory<InboxChannelFactory> */
    use BelongsToWorkspace, HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['test_mode' => true, 'is_active' => true];

    protected $fillable = ['workspace_id', 'type', 'name', 'address', 'credentials', 'test_mode', 'is_active'];

    protected $hidden = ['credentials', 'token'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'test_mode' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (InboxChannel $channel) {
            $channel->token ??= Str::random(48);
        });
    }

    /** @return HasMany<InboxConversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(InboxConversation::class);
    }

    public function driver(): ChannelDriver
    {
        return app(Inbox::class)->driver($this->type);
    }

    public function credential(string $field): string
    {
        return (string) ($this->credentials[$field] ?? '');
    }

    /** The URL the provider posts incoming messages to. */
    public function webhookUrl(): string
    {
        return route('inbox.webhook', $this->token);
    }

    /** Ready to send real replies: every required credential is filled in. */
    public function isConfigured(): bool
    {
        foreach ($this->driver()->fields() as $field => $meta) {
            if ($meta['required'] && $this->credential($field) === '') {
                return false;
            }
        }

        return true;
    }
}
