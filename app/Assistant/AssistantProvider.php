<?php

namespace App\Assistant;

/**
 * A large language model service. Drivers talk to the provider over plain HTTP with the
 * workspace's own key, and translate the shared message format to and from the provider's API.
 *
 * Messages are a list of:
 *   ['role' => 'user', 'content' => string]
 *   ['role' => 'assistant', 'content' => ?string, 'tool_calls' => list<array{id: string, name: string, arguments: array}>]
 *   ['role' => 'tool', 'tool_call_id' => string, 'name' => string, 'content' => string]
 */
interface AssistantProvider
{
    public function key(): string;

    public function label(): string;

    /** One line on the provider, shown in settings. */
    public function description(): string;

    /**
     * The settings fields this provider needs, keyed by field name.
     *
     * @return array<string, array{label: string, secret: bool, required: bool, help?: string}>
     */
    public function fields(): array;

    /**
     * Ask the model for the next turn.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     * @param  array<string, string>  $credentials
     *
     * @throws AssistantException when the provider refuses the request or cannot be reached
     */
    public function reply(string $system, array $messages, array $tools, array $credentials): AssistantReply;
}
