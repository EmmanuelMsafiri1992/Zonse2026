<?php

namespace Database\Factories;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoicing\Models\Invoice;

/**
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
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
            'subject' => 'invoice.send',
            'approvable_type' => Invoice::class,
            'approvable_id' => 1,
            'title' => 'Invoice INV-00001',
            'amount' => 1500,
            'currency_code' => 'USD',
            'status' => 'pending',
            'requested_by' => User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => 'approved', 'decided_at' => now()]);
    }
}
