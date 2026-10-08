<?php

namespace Database\Factories;

use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a farm field; use ofEntity() for any other blueprint entity.
 *
 * @extends Factory<Record>
 */
class RecordFactory extends Factory
{
    protected $model = Record::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'blueprint' => 'farm',
            'entity' => 'fields',
            'title' => fake()->words(2, true),
            'data' => [],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function ofEntity(string $blueprint, string $entity, array $data = []): static
    {
        return $this->state(['blueprint' => $blueprint, 'entity' => $entity, 'data' => $data]);
    }
}
