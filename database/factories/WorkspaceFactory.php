<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => 'company',
            'owner_id' => User::factory(),
            'email' => fake()->companyEmail(),
            'country_code' => 'ZW',
            'currency_code' => 'USD',
            'locale' => 'en',
            'timezone' => 'Africa/Harare',
            'onboarding_step' => 0,
            'onboarded_at' => now(),
            'is_active' => true,
        ];
    }

    /** A workspace still inside the setup wizard at the given step. */
    public function onboarding(int $step = 1): static
    {
        return $this->state(fn () => ['onboarding_step' => $step, 'onboarded_at' => null]);
    }
}
