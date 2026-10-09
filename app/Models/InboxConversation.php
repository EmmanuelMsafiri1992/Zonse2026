<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Database\Factories\InboxConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Contacts\Models\Contact;

/**
 * Everything one person has said on one channel, newest at the bottom. The handle is how the
 * channel knows them: an email address, a +E.164 number, or a Facebook / Instagram user id.
 */
class InboxConversation extends Model
{
    /** @use HasFactory<InboxConversationFactory> */
    use BelongsToWorkspace, HasFactory;

    public const STATUSES = ['open' => 'Open', 'closed' => 'Closed'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open', 'unread_count' => 0];

    protected $fillable = [
        'workspace_id', 'inbox_channel_id', 'contact_id', 'handle', 'name', 'subject', 'status', 'assigned_to',
        'unread_count', 'last_message_preview', 'last_message_at',
    ];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'unread_count' => 'integer'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(InboxChannel::class, 'inbox_channel_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<InboxMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /** Who the customer is, in the words the team knows them by. */
    public function displayName(): string
    {
        return $this->contact?->displayName() ?: ($this->name ?: $this->handle);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
