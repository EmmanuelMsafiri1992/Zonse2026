<?php

namespace Modules\Appointments\Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Appointments\Models\Service;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->randomElement(['Consultation', 'Follow-up', 'Haircut', 'Massage', 'Lesson', 'Site visit']),
            'description' => fake()->boolean(40) ? fake()->sentence() : null,
            'duration_minutes' => fake()->randomElement([15, 30, 45, 60]),
            'price' => fake()->randomElement([0, 20, 35, 50, 80]),
            'color' => fake()->randomElement(Service::COLORS),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
