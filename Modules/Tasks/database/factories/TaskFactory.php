<?php

namespace Modules\Tasks\Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Tasks\Models\Task;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => 'todo',
            'priority' => 'normal',
            'due_date' => null,
        ];
    }

    public function due(string $date): static
    {
        return $this->state(['due_date' => $date]);
    }

    public function priority(string $priority): static
    {
        return $this->state(['priority' => $priority]);
    }

    public function inProgress(): static
    {
        return $this->state(['status' => 'in_progress']);
    }

    public function done(): static
    {
        return $this->state(['status' => 'done', 'completed_at' => now()]);
    }
}
