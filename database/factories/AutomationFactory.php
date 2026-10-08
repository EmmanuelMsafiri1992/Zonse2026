<?php

namespace Database\Factories;

use App\Models\Automation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Automation>
 */
class AutomationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->words(3, true)),
            'trigger' => 'contact.created',
            'conditions' => [],
            'actions' => [['type' => 'notify', 'to' => 'admins', 'message' => 'New contact: {{name}}']],
            'is_active' => true,
        ];
    }
}
