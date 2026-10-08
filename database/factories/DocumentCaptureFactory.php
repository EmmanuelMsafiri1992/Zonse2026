<?php

namespace Database\Factories;

use App\Models\DocumentCapture;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentCapture>
 */
class DocumentCaptureFactory extends Factory
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
            'type' => 'receipt',
            'status' => 'ready',
            'file_name' => 'receipt.jpg',
            'file_path' => 'captures/'.$uuid.'.jpg',
            'mime' => 'image/jpeg',
            'file_size' => 2048,
            'file_hash' => hash('sha256', $uuid),
            'provider' => 'test',
            'raw_text' => "OK MART\nTOTAL 12.50",
            'fields' => ['merchant' => 'OK Mart', 'date' => now()->toDateString(), 'total' => '12.50', 'currency' => 'USD', 'category' => 'other'],
            'created_by' => User::factory(),
            'processed_at' => now(),
        ];
    }

    public function processing(): static
    {
        return $this->state(['status' => 'processing', 'raw_text' => null, 'fields' => null, 'processed_at' => null]);
    }
}
