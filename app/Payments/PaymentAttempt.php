<?php

namespace App\Payments;

/**
 * One try at paying something through a gateway: an invoice payment by a customer, or a
 * platform checkout (a plan or token pack) by a workspace. Gateways only need this much of it.
 */
interface PaymentAttempt
{
    public function attemptUuid(): string;

    public function attemptAmount(): float;

    /** ISO 4217 code of the amount actually charged. */
    public function attemptCurrency(): string;

    public function pollUrl(): ?string;

    public function gatewayReference(): ?string;

    /**
     * Keep what the gateway needs later (poll URL, session id).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function remember(array $attributes): void;

    /** The amount formatted with its currency, for messages. */
    public function money(): string;
}
