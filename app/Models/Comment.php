<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A note, reply or internal comment attached to any module record
 * (ticket, task, invoice, contact, appointment, …).
 */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use BelongsToWorkspace, HasFactory;

    protected $fillable = ['workspace_id', 'commentable_type', 'commentable_id', 'user_id', 'author_name', 'body', 'is_internal'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function authorName(): string
    {
        return $this->user?->name ?? $this->author_name ?? 'Someone';
    }
}
