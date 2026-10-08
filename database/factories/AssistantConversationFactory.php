<?php

namespace Database\Factories;

use App\Models\AssistantConversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssistantConversation>
 */
class AssistantConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'uuid' => (string) Str::uuid(),
            'title' => 'How much did we spend this month?',
            'status' => 'idle',
            'last_message_at' => now(),
        ];
    }

    public function thinking(): static
    {
        return $this->state(['status' => 'thinking']);
    }
}
