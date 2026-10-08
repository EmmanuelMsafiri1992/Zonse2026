<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'google',
            'provider_user_id' => (string) fake()->unique()->numerify('1##################'),
            'email' => fake()->safeEmail(),
            'name' => fake()->name(),
        ];
    }

    public function microsoft(): static
    {
        return $this->state(['provider' => 'microsoft', 'provider_user_id' => fake()->uuid()]);
    }
}
