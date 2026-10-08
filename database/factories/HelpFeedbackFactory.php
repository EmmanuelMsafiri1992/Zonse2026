<?php

namespace Database\Factories;

use App\Models\HelpFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelpFeedback>
 */
class HelpFeedbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => null,
            'article' => 'getting-started',
            'helpful' => fake()->boolean(70),
            'comment' => null,
        ];
    }
}
