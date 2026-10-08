<?php

namespace Database\Factories;

use App\Models\FiscalDocument;
use App\Models\Workspace;
use App\Support\Fiscal\Fiscaliser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalDocument>
 */
class FiscalDocumentFactory extends Factory
{
    /**
     * A signed invoice document opening its own chain. Use the Fiscaliser for documents that need
     * to sit in a real chain.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $counter = fake()->unique()->numberBetween(1, 99999);
        $fiscalNumber = 'ZIMRA-'.str_pad((string) $counter, 8, '0', STR_PAD_LEFT);
        $payload = [
            'type' => 'invoice', 'authority' => 'zimra', 'mode' => 'test', 'counter' => $counter, 'fiscal_number' => $fiscalNumber,
            'original_fiscal_number' => null, 'issued_at' => now()->toIso8601String(), 'invoice_number' => 'INV-'.fake()->numerify('####'),
            'invoice_date' => today()->toDateString(), 'currency' => 'USD',
            'seller' => ['name' => fake()->company(), 'tax_id' => fake()->numerify('##########'), 'device_id' => null],
            'buyer' => ['name' => fake()->name(), 'tax_id' => null],
            'lines' => [['description' => 'Service', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '0', 'tax_amount' => '0.00', 'line_total' => '100.00']],
            'subtotal' => '100.00', 'discount' => '0.00', 'tax_total' => '0.00', 'total' => '100.00',
        ];
        $hash = Fiscaliser::hashFor($payload, null);

        return [
            'workspace_id' => Workspace::factory(),
            'invoice_id' => null,
            'type' => 'invoice',
            'authority' => 'zimra',
            'counter' => $counter,
            'fiscal_number' => $fiscalNumber,
            'verification_code' => Fiscaliser::verificationCode($hash),
            'status' => 'signed',
            'payload' => $payload,
            'previous_hash' => null,
            'hash' => $hash,
            'authority_reference' => 'TEST-ZIMRA-'.strtoupper(fake()->bothify('??########')),
            'attempts' => 1,
            'signed_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending', 'authority_reference' => null, 'signed_at' => null, 'attempts' => 0]);
    }
}
