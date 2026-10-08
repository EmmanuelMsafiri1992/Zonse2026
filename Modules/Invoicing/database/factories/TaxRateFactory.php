<?php

namespace Modules\Invoicing\Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoicing\Models\TaxRate;

/** @extends Factory<TaxRate> */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => 'VAT',
            'rate' => 15,
            'is_default' => true,
            'is_active' => true,
        ];
    }
}
