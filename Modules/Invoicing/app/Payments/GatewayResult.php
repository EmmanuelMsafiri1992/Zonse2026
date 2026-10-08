<?php

namespace Modules\Invoicing\Payments;

/** Where an online payment stands according to the provider. */
final readonly class GatewayResult
{
    public function __construct(
        public string $status,
        public ?string $reference = null,
        public ?string $reason = null,
    ) {}

    public static function paid(?string $reference = null): self
    {
        return new self('paid', $reference);
    }

    public static function pending(): self
    {
        return new self('pending');
    }

    public static function failed(string $reason, string $status = 'failed'): self
    {
        return new self($status, null, $reason);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
