<?php

namespace Database\Factories;

use App\Models\CustomField;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomField>
 */
class CustomFieldFactory extends Factory
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
            'entity' => 'contact',
            'label' => ucfirst(fake()->unique()->words(2, true)),
            'type' => 'text',
            'is_required' => false,
        ];
    }

    /** @param list<string> $options */
    public function select(array $options = ['Gold', 'Silver', 'Bronze']): static
    {
        return $this->state(['type' => 'select', 'options' => $options]);
    }

    public function required(): static
    {
        return $this->state(['is_required' => true]);
    }
}
