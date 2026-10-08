<?php

namespace Database\Factories;

use App\Models\PortalAccess;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Contacts\Models\Contact;

/**
 * @extends Factory<PortalAccess>
 */
class PortalAccessFactory extends Factory
{
    protected $model = PortalAccess::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'contact_id' => fn (array $attributes) => Contact::factory()->create(['workspace_id' => $attributes['workspace_id']])->id,
            'audience' => 'customer',
            'email' => fake()->unique()->safeEmail(),
            'status' => 'active',
            'invited_at' => now(),
        ];
    }

    public function disabled(): static
    {
        return $this->state(['status' => 'disabled']);
    }
}
