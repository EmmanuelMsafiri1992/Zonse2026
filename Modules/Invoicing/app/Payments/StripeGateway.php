<?php

namespace Modules\Invoicing\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\OnlinePayment;

/**
 * Stripe Checkout for international cards, Apple Pay and Google Pay. Webhooks are verified with the
 * endpoint's signing secret; without one, payments are still confirmed when the customer comes back.
 */
class StripeGateway implements PaymentGateway
{
    public const API = 'https://api.stripe.com/v1';

    /** Seconds a webhook signature stays valid, as Stripe's own libraries allow. */
    public const SIGNATURE_TOLERANCE = 300;

    /** Currencies Stripe charges in whole units rather than cents. */
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe';
    }

    public function methods(): string
    {
        return 'Visa, Mastercard, American Express, Apple Pay or Google Pay';
    }

    public function fields(): array
    {
        return [
            'secret_key' => ['label' => 'Secret key', 'secret' => true, 'help' => 'Starts with sk_live_ or sk_test_ (or a restricted rk_ key).'],
            'webhook_secret' => ['label' => 'Webhook signing secret', 'secret' => true, 'help' => 'Optional. Starts with whsec_.'],
        ];
    }

    public function start(OnlinePayment $attempt, Invoice $invoice, array $credentials): string
    {
        $session = $this->call($credentials, 'post', '/checkout/sessions', array_filter([
            'mode' => 'payment',
            'success_url' => route('online-payments.return', $attempt->uuid),
            'cancel_url' => route('online-payments.return', ['attempt' => $attempt->uuid, 'cancelled' => 1]),
            'client_reference_id' => $attempt->uuid,
            'customer_email' => $invoice->contact?->email,
            'metadata' => ['online_payment' => $attempt->uuid, 'invoice' => $invoice->number],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($attempt->currency_code),
                    'unit_amount' => self::minorUnits($attempt->amount, $attempt->currency_code),
                    'product_data' => ['name' => 'Invoice '.$invoice->number.' · '.$invoice->workspace->name],
                ],
            ]],
        ]));

        if (empty($session['id']) || empty($session['url'])) {
            throw new GatewayException('Stripe did not return a checkout page.');
        }
        $attempt->forceFill(['gateway_reference' => $session['id']])->save();

        return $session['url'];
    }

    public function check(OnlinePayment $attempt, array $credentials): GatewayResult
    {
        if (! $attempt->gateway_reference) {
            throw new GatewayException('This Stripe payment has no checkout session to check.');
        }

        return $this->resultFor($this->call($credentials, 'get', '/checkout/sessions/'.rawurlencode($attempt->gateway_reference)), $attempt);
    }

    public function notification(Request $request, array $credentials): ?array
    {
        if (empty($credentials['webhook_secret'])) {
            throw new GatewayException('No webhook signing secret is set for Stripe.');
        }
        self::verifySignature($request->getContent(), (string) $request->header('Stripe-Signature'), $credentials['webhook_secret']);

        $event = json_decode($request->getContent(), true);
        $session = $event['data']['object'] ?? [];
        if (! in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'], true)
            || empty($session['client_reference_id'])) {
            return null;
        }

        // The event is signed, but the session is still read back so a replayed or reordered event cannot move money.
        return ['reference' => $session['client_reference_id'], 'result' => null];
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function resultFor(array $session, OnlinePayment $attempt): GatewayResult
    {
        if (($session['payment_status'] ?? null) === 'paid') {
            $expected = self::minorUnits($attempt->amount, $attempt->currency_code);
            if ((int) ($session['amount_total'] ?? 0) < $expected || strtoupper($session['currency'] ?? '') !== strtoupper($attempt->currency_code)) {
                return GatewayResult::failed('Stripe\'s amount or currency does not match this payment.');
            }

            return GatewayResult::paid(is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : ($session['id'] ?? null));
        }
        if (($session['status'] ?? null) === 'expired') {
            return GatewayResult::failed('The checkout page expired before payment.', 'cancelled');
        }

        return GatewayResult::pending();
    }

    /**
     * Check a Stripe-Signature header ("t=...,v1=...") against the raw request body.
     *
     * @throws GatewayException when the signature is missing, wrong or too old
     */
    public static function verifySignature(string $payload, string $header, string $secret, ?int $now = null): void
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't') {
                $timestamp = (int) $value;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || $signatures === []) {
            throw new GatewayException('The Stripe signature header is missing or malformed.');
        }
        if (abs(($now ?? time()) - $timestamp) > self::SIGNATURE_TOLERANCE) {
            throw new GatewayException('The Stripe signature is too old.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new GatewayException('The Stripe signature does not match.');
    }

    public static function minorUnits(float $amount, string $currency): int
    {
        return (int) round(in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? $amount : $amount * 100);
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function call(array $credentials, string $method, string $path, array $payload = []): array
    {
        try {
            /** @var Response $response */
            $response = Http::withToken($credentials['secret_key'])->asForm()->timeout(20)->{$method}(self::API.$path, $payload);
        } catch (ConnectionException $e) {
            throw new GatewayException('Stripe could not be reached. Try again in a moment.', 0, $e);
        }
        if ($response->failed()) {
            throw new GatewayException('Stripe refused the request: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return (array) $response->json();
    }
}
