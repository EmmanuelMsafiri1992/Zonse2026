<?php

namespace App\Payments;

use App\Models\Checkout;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Lists;
use Illuminate\Support\Facades\Log;

/**
 * Taking money for Zonseob itself: plan periods and AI token packs. Prices are in USD; each
 * gateway charges in its own currency at the platform's exchange rates, so people without
 * foreign currency can pay with EcoCash, local cards or a bank transfer in what they have.
 *
 * Gateway credentials come from the environment (PAYNOW_*, STRIPE_*). A bank-transfer option
 * appears when CHECKOUT_MANUAL_INSTRUCTIONS (or the platform setting) says where to send the money;
 * a platform admin then confirms those by hand.
 */
class PlatformCheckout
{
    public const BASE_CURRENCY = 'USD';

    public const MANUAL = 'manual';

    /** @var array<string, PaymentGateway> */
    protected array $gateways = [];

    public function __construct(protected SubscriptionPayments $subscriptions)
    {
        foreach ([new PaynowGateway, new StripeGateway] as $gateway) {
            $this->gateways[$gateway->key()] = $gateway;
        }
    }

    /** @return array<string, PaymentGateway> */
    public function gateways(): array
    {
        return $this->gateways;
    }

    public function find(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
    }

    /**
     * Gateways with credentials in the environment, so they can be offered.
     *
     * @return array<string, PaymentGateway>
     */
    public function available(): array
    {
        return array_filter($this->gateways, fn (PaymentGateway $gateway) => $this->isConfigured($gateway));
    }

    public function isConfigured(PaymentGateway $gateway): bool
    {
        $credentials = $this->credentials($gateway);
        foreach ($gateway->fields() as $field => $meta) {
            if ($field !== 'webhook_secret' && ($credentials[$field] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    public function credentials(PaymentGateway $gateway): array
    {
        $credentials = [];
        foreach ($gateway->fields() as $field => $meta) {
            $credentials[$field] = trim((string) config('zonseo.checkout.'.$gateway->key().'.'.$field, ''));
        }

        return $credentials;
    }

    /** The currency a gateway charges in. Paynow follows the merchant account; Stripe is charged in USD. */
    public function currencyFor(string $gatewayKey): string
    {
        if ($gatewayKey === 'paynow') {
            $currency = strtoupper(trim((string) config('zonseo.checkout.paynow.currency', self::BASE_CURRENCY)));
            if ($currency !== '' && ($currency === self::BASE_CURRENCY || isset($this->rates()[$currency]))) {
                return $currency;
            }
        }

        return self::BASE_CURRENCY;
    }

    /** Where to send a bank transfer or mobile-money payment that an admin will confirm by hand; blank switches the option off. */
    public function manualInstructions(): string
    {
        return trim((string) (Setting::platform('checkout.manual_instructions') ?? config('zonseo.checkout.manual_instructions', '')));
    }

    public function setManualInstructions(string $instructions): void
    {
        Setting::query()->updateOrCreate(['workspace_id' => null, 'key' => 'checkout.manual_instructions'], ['value' => ['v' => trim($instructions)]]);
    }

    public function manualEnabled(): bool
    {
        return $this->manualInstructions() !== '';
    }

    /**
     * How many units of each local currency one US dollar buys. The platform setting overrides
     * CHECKOUT_RATES ("ZWG=26.5,ZAR=18").
     *
     * @return array<string, float>
     */
    public function rates(): array
    {
        $stored = Setting::platform('checkout.rates');

        return is_array($stored) ? self::cleanRates($stored) : self::parseRates((string) config('zonseo.checkout.rates', ''));
    }

    /** @param  array<string, float|string>  $rates */
    public function setRates(array $rates): void
    {
        Setting::query()->updateOrCreate(['workspace_id' => null, 'key' => 'checkout.rates'], ['value' => ['v' => self::cleanRates($rates)]]);
    }

    /** @return array<string, float> */
    public static function parseRates(string $list): array
    {
        $rates = [];
        foreach (preg_split('/[,\n]+/', $list) ?: [] as $pair) {
            [$currency, $rate] = array_pad(explode('=', $pair, 2), 2, '');
            $rates[trim($currency)] = trim($rate);
        }

        return self::cleanRates($rates);
    }

    /**
     * @param  array<string, float|string>  $rates
     * @return array<string, float>
     */
    public static function cleanRates(array $rates): array
    {
        $clean = [];
        foreach ($rates as $currency => $rate) {
            $currency = strtoupper(trim((string) $currency));
            if ($currency === self::BASE_CURRENCY || ! isset(Lists::CURRENCIES[$currency]) || ! is_numeric($rate) || (float) $rate <= 0) {
                continue;
            }
            $clean[$currency] = round((float) $rate, 4);
        }
        ksort($clean);

        return $clean;
    }

    /** A USD amount in another currency at the platform rate, rounded to cents. */
    public function convert(float $usd, string $currency): float
    {
        if ($currency === self::BASE_CURRENCY) {
            return round($usd, 2);
        }
        $rate = $this->rates()[$currency] ?? null;
        if (! $rate) {
            throw new GatewayException('No exchange rate is set for '.$currency.'.');
        }

        return round($usd * $rate, 2);
    }

    /**
     * The same USD amount in every currency with a rate, for showing what a plan costs locally.
     *
     * @return array<string, float>
     */
    public function localAmounts(float $usd): array
    {
        $amounts = [];
        foreach ($this->rates() as $currency => $rate) {
            $amounts[$currency] = round($usd * $rate, 2);
        }

        return $amounts;
    }

    /**
     * Record what is being bought and for how much, before sending the person to pay.
     *
     * @param  array<string, mixed>  $subject
     */
    public function begin(User $user, ?Workspace $workspace, string $purpose, array $subject, float $usd, string $gatewayKey, ?string $note = null): Checkout
    {
        if ($gatewayKey !== self::MANUAL && ! isset($this->available()[$gatewayKey])) {
            throw new GatewayException('That way of paying is not available.');
        }
        if ($gatewayKey === self::MANUAL && ! $this->manualEnabled()) {
            throw new GatewayException('Bank transfers are not being accepted right now.');
        }
        $currency = $gatewayKey === self::MANUAL ? self::BASE_CURRENCY : $this->currencyFor($gatewayKey);

        return Checkout::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace?->id,
            'purpose' => $purpose,
            'subject' => $subject,
            'gateway' => $gatewayKey,
            'base_amount' => round($usd, 2),
            'base_currency' => self::BASE_CURRENCY,
            'amount' => $this->convert($usd, $currency),
            'currency_code' => $currency,
            'note' => $note ? mb_substr(trim($note), 0, 500) : null,
        ]);
    }

    /**
     * Open the gateway's hosted page for a pending checkout and return its URL.
     *
     * @throws GatewayException when the gateway refuses; the checkout is marked failed first
     */
    public function start(Checkout $checkout): string
    {
        $gateway = $this->find($checkout->gateway);
        if (! $gateway) {
            throw new GatewayException('That way of paying is not available.');
        }

        try {
            return $gateway->start($checkout, $this->charge($checkout), $this->credentials($gateway));
        } catch (GatewayException $e) {
            $checkout->markFailed('failed', $e->getMessage());
            Log::warning('Checkout could not start', ['checkout' => $checkout->uuid, 'gateway' => $checkout->gateway, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    public function charge(Checkout $checkout): Charge
    {
        return new Charge(
            reference: 'ZB-'.strtoupper(substr($checkout->uuid, 0, 8)),
            description: $checkout->description().($checkout->workspace ? ' · '.$checkout->workspace->name : ''),
            returnUrl: route('checkout.return', $checkout->uuid),
            cancelUrl: route('checkout.return', ['checkout' => $checkout->uuid, 'cancelled' => 1]),
            webhookUrl: route('checkout.webhook', ['gateway' => $checkout->gateway, 'attempt' => $checkout->uuid]),
            email: $checkout->user?->email,
        );
    }

    /**
     * Ask the gateway where a pending checkout stands, record the outcome and apply the purchase
     * once it is paid. Returns the checkout's status afterwards. A gateway that cannot be reached
     * leaves it pending for the next check or notification.
     */
    public function settle(Checkout $checkout, ?GatewayResult $result = null): string
    {
        if (! $checkout->isPending()) {
            return $checkout->status;
        }
        $gateway = $this->find($checkout->gateway);
        if (! $gateway) {
            return $checkout->status;
        }

        try {
            $result ??= $gateway->check($checkout, $this->credentials($gateway));
        } catch (GatewayException $e) {
            Log::warning('Checkout check failed', ['checkout' => $checkout->uuid, 'gateway' => $checkout->gateway, 'error' => $e->getMessage()]);

            return $checkout->status;
        }

        if ($result->isPaid()) {
            if ($checkout->markPaid($result->reference)) {
                $this->fulfil($checkout);
            }
        } elseif (! $result->isPending()) {
            $checkout->markFailed($result->status, $result->reason);
        }

        return $checkout->status;
    }

    /** A platform admin has seen the bank transfer arrive. */
    public function confirmManually(Checkout $checkout, User $admin, ?string $reference = null): void
    {
        if ($checkout->markPaid($reference ?: null, $admin)) {
            $this->fulfil($checkout);
        }
    }

    /** Give the person what they paid for. Runs once per checkout. */
    public function fulfil(Checkout $checkout): void
    {
        if ($checkout->fulfilled_at) {
            return;
        }

        match ($checkout->purpose) {
            'subscription' => $this->subscriptions->apply($checkout),
            default => null,
        };

        $checkout->markFulfilled();
    }
}
