<?php

namespace Database\Factories;

use App\Models\ApprovalRule;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRule>
 */
class ApprovalRuleFactory extends Factory
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
            'name' => 'Large invoices',
            'subject' => 'invoice.send',
            'min_amount' => 1000,
            'approver' => 'admins',
            'is_active' => true,
        ];
    }

    public function quotes(): static
    {
        return $this->state(['name' => 'Large quotes', 'subject' => 'quote.send']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
