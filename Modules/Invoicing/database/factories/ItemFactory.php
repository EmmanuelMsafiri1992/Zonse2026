<?php

namespace Modules\Invoicing\Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoicing\Models\Item;

/** @extends Factory<Item> */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'type' => fake()->randomElement(['service', 'product']),
            'name' => ucfirst(fake()->words(2, true)),
            'sku' => strtoupper(fake()->bothify('??-###')),
            'unit' => 'each',
            'price' => fake()->randomFloat(2, 5, 500),
            'is_active' => true,
        ];
    }
}
