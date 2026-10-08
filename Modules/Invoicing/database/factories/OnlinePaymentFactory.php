<?php

namespace Modules\Invoicing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\OnlinePayment;

/** @extends Factory<OnlinePayment> */
class OnlinePaymentFactory extends Factory
{
    protected $model = OnlinePayment::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->withLines(),
            'workspace_id' => fn (array $attrs) => Invoice::allWorkspaces()->find($attrs['invoice_id'])->workspace_id,
            'gateway' => fake()->randomElement(['paynow', 'stripe']),
            'amount' => 25.00,
            'currency_code' => 'USD',
            'status' => 'pending',
        ];
    }

    public function paynow(): static
    {
        return $this->state(['gateway' => 'paynow', 'poll_url' => 'https://www.paynow.co.zw/Interface/CheckPayment/?guid='.fake()->uuid()]);
    }

    public function stripe(): static
    {
        return $this->state(['gateway' => 'stripe', 'gateway_reference' => 'cs_test_'.fake()->regexify('[A-Za-z0-9]{24}')]);
    }
}
