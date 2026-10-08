<?php

namespace Database\Factories;

use App\Models\ImportRun;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRun>
 */
class ImportRunFactory extends Factory
{
    /**
     * A contacts file waiting for its columns to be matched.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => null,
            'target' => 'contacts',
            'source' => null,
            'filename' => 'contacts.csv',
            'status' => 'mapping',
            'headers' => ['Name', 'Email'],
            'rows' => [['line' => 2, 'cells' => [fake()->name(), fake()->safeEmail()]]],
            'total_rows' => 1,
        ];
    }
}
