<?php

namespace Modules\Helpdesk\Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Helpdesk\Models\Ticket;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'requester_name' => fake()->name(),
            'requester_email' => fake()->safeEmail(),
            'subject' => fake()->sentence(5),
            'body' => fake()->paragraph(),
            'channel' => 'phone',
            'status' => 'open',
            'priority' => 'normal',
        ];
    }

    public function priority(string $priority): static
    {
        return $this->state(['priority' => $priority]);
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending', 'first_replied_at' => now()]);
    }

    public function resolved(): static
    {
        return $this->state(['status' => 'resolved', 'resolved_at' => now()]);
    }

    public function closed(): static
    {
        return $this->state(['status' => 'closed', 'resolved_at' => now(), 'closed_at' => now()]);
    }
}
