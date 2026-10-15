<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One chat with Zonseob AI. Chats belong to a person and are private to them. */
class AiChat extends Model
{
    protected $fillable = ['user_id', 'uuid', 'title', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (AiChat $chat) {
            $chat->uuid ??= (string) Str::uuid();
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

    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'chat_id')->orderBy('id');
    }

    /** A title from the first question. */
    public static function titleFor(string $question): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', Str::before(trim($question), "\n")));

        return Str::limit($line !== '' ? $line : 'New chat', 80, '…');
    }

    /** Tokens taken from the wallet for this chat so far. */
    public function costTokens(): int
    {
        return (int) $this->messages()->sum('cost_tokens');
    }
}
