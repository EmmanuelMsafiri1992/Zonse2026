<?php

namespace Database\Factories;

use App\Models\SignatureRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SignatureRequest>
 */
class SignatureRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'workspace_id' => Workspace::factory(),
            'uuid' => $uuid,
            'title' => 'Service agreement',
            'status' => 'pending',
            'signing_order' => 'parallel',
            'document_name' => 'service-agreement.pdf',
            'document_path' => 'signatures/'.$uuid.'.pdf',
            'document_hash' => hash('sha256', $uuid),
            'document_size' => 1024,
            'created_by' => User::factory(),
            'expires_at' => now()->addDays(30),
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => 'completed', 'completed_at' => now()]);
    }
}
