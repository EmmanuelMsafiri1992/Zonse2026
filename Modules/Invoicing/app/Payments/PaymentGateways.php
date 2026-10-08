<?php

namespace Modules\Invoicing\Payments;

use App\Models\Workspace;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\OnlinePayment;

/**
 * The online payment gateways and each workspace's credentials for them. Credentials live in the
 * workspace settings under payments.{gateway}; secret fields are stored encrypted with the app key.
 */
class PaymentGateways
{
    /** @var array<string, PaymentGateway> */
    protected array $gateways = [];

    public function __construct()
    {
        foreach ([new PaynowGateway, new StripeGateway] as $gateway) {
            $this->gateways[$gateway->key()] = $gateway;
        }
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }

    public function find(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
    }

    /**
     * Gateways the workspace has switched on and fully set up.
     *
     * @return array<string, PaymentGateway>
     */
    public function enabledFor(Workspace $workspace): array
    {
        return array_filter($this->gateways, fn (PaymentGateway $gateway) => $this->isEnabled($workspace, $gateway));
    }

    public function isEnabled(Workspace $workspace, PaymentGateway $gateway): bool
    {
        return (bool) $workspace->setting('payments.'.$gateway->key().'.enabled') && $this->isConfigured($workspace, $gateway);
    }

    /** Every field a gateway needs is filled in (optional ones like Stripe's webhook secret aside). */
    public function isConfigured(Workspace $workspace, PaymentGateway $gateway): bool
    {
        $credentials = $this->credentials($workspace, $gateway);

        foreach ($gateway->fields() as $field => $meta) {
            if ($field !== 'webhook_secret' && ($credentials[$field] ?? '') === '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    public function credentials(Workspace $workspace, PaymentGateway $gateway): array
    {
        $credentials = [];
        foreach ($gateway->fields() as $field => $meta) {
            $value = (string) $workspace->setting('payments.'.$gateway->key().'.'.$field, '');
            if ($meta['secret'] && $value !== '') {
                try {
                    $value = Crypt::decryptString($value);
                } catch (DecryptException) {
                    $value = '';
                }
            }
            $credentials[$field] = $value;
        }

        return $credentials;
    }

    /**
     * Save a gateway's settings. Blank secret fields keep the stored value, so secrets never need to be shown again.
     *
     * @param  array<string, string|null>  $values
     */
    public function configure(Workspace $workspace, PaymentGateway $gateway, bool $enabled, array $values): void
    {
        $settings = $workspace->settings ?? [];
        data_set($settings, 'payments.'.$gateway->key().'.enabled', $enabled);

        foreach ($gateway->fields() as $field => $meta) {
            $value = trim((string) ($values[$field] ?? ''));
            if ($meta['secret'] && $value === '') {
                continue;
            }
            data_set($settings, 'payments.'.$gateway->key().'.'.$field, $meta['secret'] ? Crypt::encryptString($value) : $value);
        }

        $workspace->settings = $settings;
        $workspace->save();
    }

    /** Remove a stored secret (for example to switch off Stripe webhooks). */
    public function forget(Workspace $workspace, PaymentGateway $gateway, string $field): void
    {
        $settings = $workspace->settings ?? [];
        data_forget($settings, 'payments.'.$gateway->key().'.'.$field);
        $workspace->settings = $settings;
        $workspace->save();
    }

    /** A new pending attempt for the invoice's whole balance. */
    public function begin(Invoice $invoice, PaymentGateway $gateway): OnlinePayment
    {
        return OnlinePayment::create([
            'workspace_id' => $invoice->workspace_id,
            'invoice_id' => $invoice->id,
            'gateway' => $gateway->key(),
            'amount' => $invoice->balance,
            'currency_code' => $invoice->currency_code,
        ]);
    }

    /**
     * Ask the gateway about a pending attempt and record the outcome. Returns the attempt's status afterwards.
     * A gateway that cannot be reached leaves the attempt pending, to be settled by the next check or notification.
     */
    public function settle(OnlinePayment $attempt, ?GatewayResult $result = null): string
    {
        if (! $attempt->isPending()) {
            return $attempt->status;
        }

        $gateway = $this->find($attempt->gateway);
        $workspace = $attempt->invoice()->firstOrFail()->workspace;
        if (! $gateway || ! $workspace) {
            return $attempt->status;
        }

        try {
            $result ??= $gateway->check($attempt, $this->credentials($workspace, $gateway));
        } catch (GatewayException $e) {
            Log::warning('Online payment check failed', ['attempt' => $attempt->uuid, 'gateway' => $attempt->gateway, 'error' => $e->getMessage()]);

            return $attempt->status;
        }

        if ($result->isPaid()) {
            $attempt->markPaid($result->reference);
        } elseif (! $result->isPending()) {
            $attempt->markFailed($result->status, $result->reason);
        }

        return $attempt->status;
    }
}
