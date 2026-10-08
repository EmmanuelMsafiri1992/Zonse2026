<?php

namespace App\Tenancy;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Gives a module record a thread of comments / notes. */
trait HasComments
{
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->latest();
    }

    public function addComment(string $body, ?User $user = null, bool $internal = false, ?string $authorName = null): Comment
    {
        return $this->comments()->create([
            'workspace_id' => $this->workspace_id,
            'user_id' => $user?->id,
            'author_name' => $authorName,
            'body' => $body,
            'is_internal' => $internal,
        ]);
    }
}
