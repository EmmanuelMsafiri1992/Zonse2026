<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/** A question to Zonseob AI or Claude's answer to it, with what the answer cost. */
class AiChatMessage extends Model
{
    protected $fillable = ['chat_id', 'role', 'content', 'model', 'input_tokens', 'output_tokens', 'cost_tokens'];

    protected function casts(): array
    {
        return ['input_tokens' => 'integer', 'output_tokens' => 'integer', 'cost_tokens' => 'integer'];
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(AiChat::class, 'chat_id');
    }

    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    /** The answer as safe HTML: Markdown with any raw HTML stripped. */
    public function html(): HtmlString
    {
        return new HtmlString(Str::markdown($this->content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]));
    }
}
