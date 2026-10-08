<?php

namespace App\Support\Fiscal;

use App\Models\FiscalDocument;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stands in for the tax authority. It checks the document the way an authority would (a taxpayer
 * number, a continuous counter, an intact hash) and answers with a reference, without any network.
 * With "simulate outage" on it refuses to answer, so documents queue up and are sent later.
 */
class TestFiscalGateway
{
    /**
     * @return array{accepted: bool, reference: ?string, message: ?string}
     *
     * @throws RuntimeException when the (simulated) authority cannot be reached
     */
    public function submit(FiscalDocument $document, bool $simulateOutage = false): array
    {
        if ($simulateOutage) {
            throw new RuntimeException('The tax authority could not be reached (test outage). It will be sent again automatically.');
        }

        if (blank($document->payload['seller']['tax_id'] ?? null)) {
            return ['accepted' => false, 'reference' => null, 'message' => 'The document has no taxpayer number.'];
        }
        if (! hash_equals($document->hash, Fiscaliser::hashFor($document->payload, $document->previous_hash))) {
            return ['accepted' => false, 'reference' => null, 'message' => 'The document hash does not match its contents.'];
        }

        return ['accepted' => true, 'reference' => 'TEST-'.strtoupper($document->authority).'-'.strtoupper(Str::random(10)), 'message' => null];
    }
}
