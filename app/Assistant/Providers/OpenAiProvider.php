<?php

namespace App\Assistant\Providers;

use App\Assistant\AssistantException;
use App\Assistant\AssistantProvider;
use App\Assistant\AssistantReply;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** OpenAI's Chat Completions API, with function calling for the lookups. */
class OpenAiProvider implements AssistantProvider
{
    public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public const DEFAULT_MODEL = 'gpt-4o-mini';

    public const MAX_TOKENS = 2000;

    public function key(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    public function description(): string
    {
        return 'GPT models from OpenAI, with the same lookups into your data.';
    }

    public function fields(): array
    {
        return [
            'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true, 'help' => 'From platform.openai.com › API keys.'],
            'model' => ['label' => 'Model', 'secret' => false, 'required' => false, 'help' => 'Leave blank for '.self::DEFAULT_MODEL.'.'],
        ];
    }

    public function reply(string $system, array $messages, array $tools, array $credentials): AssistantReply
    {
        $model = ($credentials['model'] ?? '') !== '' ? $credentials['model'] : self::DEFAULT_MODEL;

        try {
            $response = Http::withToken($credentials['api_key'])->acceptJson()->timeout(90)
                ->post(self::ENDPOINT, array_filter([
                    'model' => $model,
                    'max_completion_tokens' => self::MAX_TOKENS,
                    'messages' => [['role' => 'system', 'content' => $system], ...$this->messages($messages)],
                    'tools' => array_map(fn (array $tool) => ['type' => 'function', 'function' => ['name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $tool['parameters']]], $tools),
                ]));
        } catch (ConnectionException $e) {
            throw new AssistantException('OpenAI could not be reached.', false, $e);
        }

        if ($response->failed()) {
            $status = $response->status();
            throw new AssistantException('OpenAI: '.($response->json('error.message') ?? 'HTTP '.$status), $status < 500 && $status !== 429);
        }

        $message = $response->json('choices.0.message', []);
        $calls = array_map(fn (array $call) => [
            'id' => (string) $call['id'],
            'name' => (string) ($call['function']['name'] ?? ''),
            'arguments' => (array) (json_decode($call['function']['arguments'] ?? '{}', true) ?: []),
        ], $message['tool_calls'] ?? []);

        return new AssistantReply(
            filled($message['content'] ?? null) ? trim($message['content']) : null,
            $calls,
            (int) $response->json('usage.prompt_tokens', 0),
            (int) $response->json('usage.completion_tokens', 0),
            $response->json('model', $model),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    protected function messages(array $messages): array
    {
        return array_map(fn (array $message) => match ($message['role']) {
            'assistant' => array_filter([
                'role' => 'assistant',
                'content' => $message['content'] ?? null,
                'tool_calls' => array_map(fn (array $call) => [
                    'id' => $call['id'], 'type' => 'function',
                    'function' => ['name' => $call['name'], 'arguments' => json_encode((object) $call['arguments'])],
                ], $message['tool_calls'] ?? []) ?: null,
            ], fn ($value) => $value !== null),
            'tool' => ['role' => 'tool', 'tool_call_id' => $message['tool_call_id'], 'content' => $message['content']],
            default => ['role' => 'user', 'content' => (string) $message['content']],
        }, $messages);
    }
}
