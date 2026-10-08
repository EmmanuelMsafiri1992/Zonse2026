<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Database\Factories\AssistantConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One chat with the AI assistant. Conversations are private to the person who started them.
 * While the assistant is answering the status is "thinking"; a failed answer keeps the error
 * so the question can be tried again.
 */
class AssistantConversation extends Model
{
    /** @use HasFactory<AssistantConversationFactory> */
    use BelongsToWorkspace, HasFactory;

    public const STATUSES = ['idle' => 'Ready', 'thinking' => 'Thinking', 'failed' => 'Failed'];

    protected $fillable = ['workspace_id', 'user_id', 'uuid', 'title', 'status', 'error', 'context', 'last_message_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'last_message_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (AssistantConversation $conversation) {
            $conversation->uuid ??= (string) Str::uuid();
            $conversation->last_message_at ??= now();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AssistantMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id')->orderBy('id');
    }

    public function isThinking(): bool
    {
        return $this->status === 'thinking';
    }

    /** The record the conversation was started from, when there is one. */
    public function contextRecord(): ?Record
    {
        $id = $this->context['record_id'] ?? null;

        return $id ? Record::query()->find($id) : null;
    }

    /** A short title from the first question. */
    public static function titleFrom(string $question): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $question)), 80);
    }
}
