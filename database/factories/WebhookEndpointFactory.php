<?php

namespace Database\Factories;

use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
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
            'url' => 'https://hooks.example.com/'.fake()->slug(2),
            'description' => fake()->words(3, true),
            'events' => ['contact.created', 'task.created'],
        ];
    }
}
