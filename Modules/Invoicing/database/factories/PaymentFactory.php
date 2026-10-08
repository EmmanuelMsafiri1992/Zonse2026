<?php

namespace Modules\Invoicing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Payment;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory()->withLines(),
            'workspace_id' => fn (array $attrs) => Invoice::allWorkspaces()->find($attrs['invoice_id'])->workspace_id,
            'contact_id' => fn (array $attrs) => Invoice::allWorkspaces()->find($attrs['invoice_id'])->contact_id,
            'currency_code' => 'USD',
            'amount' => 10.00,
            'paid_on' => today(),
            'method' => 'cash',
        ];
    }
}
