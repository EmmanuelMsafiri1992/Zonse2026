<?php

namespace Database\Factories;

use App\Models\SmsMessage;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsMessage>
 */
class SmsMessageFactory extends Factory
{
    protected $model = SmsMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'to' => '+26377'.fake()->numerify('#######'),
            'body' => fake()->sentence(),
            'segments' => 1,
            'purpose' => 'manual',
            'status' => 'queued',
            'provider' => 'test',
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => 'sent', 'sent_at' => now(), 'provider_message_id' => 'test-'.fake()->uuid()]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed', 'error' => 'Rejected by the provider.']);
    }
}
