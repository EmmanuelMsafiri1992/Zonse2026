<?php

namespace App\Models;

use Database\Factories\HelpFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reader's "was this article helpful?" answer. One per user and article; answering again replaces it. */
class HelpFeedback extends Model
{
    /** @use HasFactory<HelpFeedbackFactory> */
    use HasFactory;

    protected $table = 'help_feedback';

    protected $fillable = ['user_id', 'workspace_id', 'article', 'helpful', 'comment'];

    protected function casts(): array
    {
        return ['helpful' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
