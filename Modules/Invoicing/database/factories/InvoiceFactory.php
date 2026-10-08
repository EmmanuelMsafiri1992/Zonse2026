<?php

namespace Modules\Invoicing\Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'contact_id' => fn (array $attrs) => Contact::factory()->for(Workspace::find($attrs['workspace_id']))->customer(),
            'status' => 'draft',
            'issue_date' => today(),
            'due_date' => today()->addDays(14),
            'currency_code' => 'USD',
        ];
    }

    /** @param  list<array{description: string, quantity: float, unit_price: float, tax_rate?: float}>  $lines */
    public function withLines(array $lines = [['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 50.00, 'tax_rate' => 0]]): static
    {
        return $this->afterCreating(fn (Invoice $invoice) => $invoice->syncLines($lines));
    }

    public function sent(): static
    {
        return $this->afterCreating(fn (Invoice $invoice) => $invoice->markSent());
    }

    public function overdue(): static
    {
        return $this->state(['issue_date' => today()->subDays(30), 'due_date' => today()->subDays(10)])->sent();
    }
}
