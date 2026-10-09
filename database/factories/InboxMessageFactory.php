<?php

namespace Database\Factories;

use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxMessage>
 */
class InboxMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inbox_conversation_id' => InboxConversation::factory(),
            'workspace_id' => fn (array $attributes) => InboxConversation::allWorkspaces()->find($attributes['inbox_conversation_id'])?->workspace_id,
            'direction' => 'in',
            'body' => fake()->sentence(),
            'status' => 'received',
        ];
    }
}
