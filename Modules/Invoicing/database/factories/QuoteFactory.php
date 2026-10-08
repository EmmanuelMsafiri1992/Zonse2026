<?php

namespace Modules\Invoicing\Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Quote;

/** @extends Factory<Quote> */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'contact_id' => fn (array $attrs) => Contact::factory()->for(Workspace::find($attrs['workspace_id']))->customer(),
            'status' => 'draft',
            'issue_date' => today(),
            'valid_until' => today()->addDays(30),
            'currency_code' => 'USD',
        ];
    }

    /** @param  list<array{description: string, quantity: float, unit_price: float, tax_rate?: float}>  $lines */
    public function withLines(array $lines = [['description' => 'Website design', 'quantity' => 1, 'unit_price' => 800.00, 'tax_rate' => 15]]): static
    {
        return $this->afterCreating(fn (Quote $quote) => $quote->syncLines($lines));
    }

    public function sent(): static
    {
        return $this->afterCreating(fn (Quote $quote) => $quote->markSent());
    }
}
