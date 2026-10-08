<?php

namespace Database\Factories;

use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SignatureSigner>
 */
class SignatureSignerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'signature_request_id' => SignatureRequest::factory(),
            'workspace_id' => fn (array $attributes) => SignatureRequest::allWorkspaces()->find($attributes['signature_request_id'])?->workspace_id,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'position' => 1,
            'token' => Str::random(48),
            'status' => 'pending',
        ];
    }

    public function signed(): static
    {
        return $this->state(['status' => 'signed', 'signature_type' => 'type', 'signed_name' => 'Signed Name', 'signed_at' => now()]);
    }
}
