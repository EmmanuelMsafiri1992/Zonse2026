<?php

namespace App\Assistant\Providers;

use App\Assistant\AssistantException;
use App\Assistant\AssistantProvider;
use App\Assistant\AssistantReply;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Claude, through Anthropic's Messages API. */
class AnthropicProvider implements AssistantProvider
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public const DEFAULT_MODEL = 'claude-sonnet-5-5';

    public const MAX_TOKENS = 2000;

    public function key(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Anthropic Claude';
    }

    public function description(): string
    {
        return 'Claude reads your question, looks up what it needs and answers in plain language. Strong at drafting and summarising.';
    }

    public function fields(): array
    {
        return [
            'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true, 'help' => 'From console.anthropic.com › API keys.'],
            'model' => ['label' => 'Model', 'secret' => false, 'required' => false, 'help' => 'Leave blank for '.self::DEFAULT_MODEL.'. claude-haiku-5-5 is cheaper and faster.'],
        ];
    }

    public function reply(string $system, array $messages, array $tools, array $credentials): AssistantReply
    {
        $model = ($credentials['model'] ?? '') !== '' ? $credentials['model'] : self::DEFAULT_MODEL;

        try {
            $response = Http::withHeaders(['x-api-key' => $credentials['api_key'], 'anthropic-version' => '2023-06-01'])
                ->acceptJson()->timeout(90)
                ->post(self::ENDPOINT, array_filter([
                    'model' => $model,
                    'max_tokens' => self::MAX_TOKENS,
                    'system' => $system,
                    'messages' => $this->messages($messages),
                    'tools' => array_map(fn (array $tool) => ['name' => $tool['name'], 'description' => $tool['description'], 'input_schema' => $tool['parameters']], $tools),
                ]));
        } catch (ConnectionException $e) {
            throw new AssistantException('Anthropic could not be reached.', false, $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            throw new AssistantException('Anthropic: '.($response->json('error.message') ?? 'HTTP '.$status), $status < 500 && $status !== 429);
        }

        $text = [];
        $calls = [];
        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text[] = $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $calls[] = ['id' => (string) $block['id'], 'name' => (string) $block['name'], 'arguments' => (array) ($block['input'] ?? [])];
            }
        }

        return new AssistantReply(
            trim(implode("\n\n", $text)) ?: null,
            $calls,
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            $response->json('model', $model),
        );
    }

    /**
     * Anthropic wants alternating user/assistant turns, with lookups as tool_use blocks and their
     * results as tool_result blocks inside the following user turn.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array{role: string, content: list<array<string, mixed>>}>
     */
    protected function messages(array $messages): array
    {
        $turns = [];
        foreach ($messages as $message) {
            [$role, $blocks] = match ($message['role']) {
                'assistant' => ['assistant', [
                    ...(filled($message['content'] ?? null) ? [['type' => 'text', 'text' => $message['content']]] : []),
                    ...array_map(fn (array $call) => ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => (object) $call['arguments']], $message['tool_calls'] ?? []),
                ]],
                'tool' => ['user', [['type' => 'tool_result', 'tool_use_id' => $message['tool_call_id'], 'content' => $message['content']]]],
                default => ['user', [['type' => 'text', 'text' => (string) $message['content']]]],
            };
            if (! $blocks) {
                continue;
            }
            $last = array_key_last($turns);
            if ($last !== null && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] = [...$turns[$last]['content'], ...$blocks];
            } else {
                $turns[] = ['role' => $role, 'content' => $blocks];
            }
        }

        return $turns;
    }
}
