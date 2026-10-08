<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * One turn in an assistant conversation: the person's question ("user"), the assistant's reply
 * ("assistant", possibly asking for data lookups in tool_calls) or the result of one lookup ("tool").
 */
class AssistantMessage extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'conversation_id', 'role', 'content', 'tool_calls', 'tool_call_id', 'tool_name',
        'provider', 'model', 'input_tokens', 'output_tokens',
    ];

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    /** The reply as safe HTML: Markdown is rendered, raw HTML is stripped and unsafe links are dropped. */
    public function html(): HtmlString
    {
        return new HtmlString(Str::markdown((string) $this->content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]));
    }
}
