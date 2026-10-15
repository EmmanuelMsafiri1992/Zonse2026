<?php

namespace App\Payments;

use Illuminate\Http\Request;

/**
 * A way to take money online. Each driver talks to its provider over plain HTTP and never trusts
 * the browser: money is only recorded once the provider itself confirms it. The same drivers serve
 * customers paying a workspace's invoices and workspaces paying for their Zonseob plan.
 */
interface PaymentGateway
{
    public function key(): string;

    public function label(): string;

    /** What the payer can pay with, shown under the button. */
    public function methods(): string;

    /**
     * The settings fields this gateway needs, keyed by field name.
     *
     * @return array<string, array{label: string, secret: bool, help?: string}>
     */
    public function fields(): array;

    /**
     * Open a session with the provider for this attempt and return the URL to send the payer to.
     * Stores whatever the gateway needs later (session id, poll URL) on the attempt.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws GatewayException when the provider refuses or cannot be reached
     */
    public function start(PaymentAttempt $attempt, Charge $charge, array $credentials): string;

    /**
     * Ask the provider where the attempt stands.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws GatewayException when the provider cannot be reached or its answer cannot be trusted
     */
    public function check(PaymentAttempt $attempt, array $credentials): GatewayResult;

    /**
     * Read a server-to-server notification. Returns the attempt reference the notification is about
     * and its result, or null when the notification is not one this gateway acts on.
     *
     * @param  array<string, string>  $credentials
     * @return array{reference: string, result: GatewayResult|null}|null result null means "look it up with check()"
     *
     * @throws GatewayException when the notification fails verification
     */
    public function notification(Request $request, array $credentials): ?array;
}
