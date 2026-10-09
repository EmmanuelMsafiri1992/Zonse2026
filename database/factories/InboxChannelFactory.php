<?php

namespace Database\Factories;

use App\Models\InboxChannel;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxChannel>
 */
class InboxChannelFactory extends Factory
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
            'type' => 'email',
            'name' => 'Support email',
            'address' => fake()->safeEmail(),
            'credentials' => [],
            'test_mode' => true,
            'is_active' => true,
        ];
    }

    /** A live WhatsApp Cloud API number with its keys saved. */
    public function whatsapp(?string $appSecret = null): static
    {
        return $this->state(fn () => [
            'type' => 'whatsapp',
            'name' => 'Shop WhatsApp',
            'address' => '+265991234567',
            'credentials' => ['phone_number_id' => '1098765432', 'access_token' => 'wa-token', 'app_secret' => (string) $appSecret],
            'test_mode' => false,
        ]);
    }

    /** A live Facebook page with its keys saved. */
    public function facebook(?string $appSecret = null): static
    {
        return $this->state(fn () => [
            'type' => 'facebook',
            'name' => 'Facebook page',
            'address' => '1122334455',
            'credentials' => ['page_access_token' => 'fb-token', 'app_secret' => (string) $appSecret],
            'test_mode' => false,
        ]);
    }

    public function live(): static
    {
        return $this->state(fn () => ['test_mode' => false]);
    }
}
