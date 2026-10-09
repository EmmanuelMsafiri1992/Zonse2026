<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Database\Factories\InboxMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in a conversation: received from the customer ("in") or a reply from the team ("out"). */
class InboxMessage extends Model
{
    /** @use HasFactory<InboxMessageFactory> */
    use BelongsToWorkspace, HasFactory;

    public const STATUSES = ['received' => 'Received', 'queued' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed'];

    protected $fillable = [
        'workspace_id', 'inbox_conversation_id', 'direction', 'body', 'status', 'external_id', 'sent_by', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InboxConversation::class, 'inbox_conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function isIncoming(): bool
    {
        return $this->direction === 'in';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
