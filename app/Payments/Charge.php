<?php

namespace App\Payments;

/** What a gateway is told about a payment: the words on the hosted page and where to send people and notices. */
final readonly class Charge
{
    public function __construct(
        public string $reference,
        public string $description,
        public string $returnUrl,
        public string $cancelUrl,
        public string $webhookUrl,
        public ?string $email = null,
    ) {}
}
