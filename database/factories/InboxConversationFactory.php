<?php

namespace Database\Factories;

use App\Models\InboxChannel;
use App\Models\InboxConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxConversation>
 */
class InboxConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inbox_channel_id' => InboxChannel::factory(),
            'workspace_id' => fn (array $attributes) => InboxChannel::allWorkspaces()->find($attributes['inbox_channel_id'])?->workspace_id,
            'handle' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'status' => 'open',
            'unread_count' => 1,
            'last_message_preview' => fake()->sentence(),
            'last_message_at' => now(),
        ];
    }
}
