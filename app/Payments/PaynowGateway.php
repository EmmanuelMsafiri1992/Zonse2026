<?php

namespace App\Payments;

use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Paynow (Zimbabwe): EcoCash, OneMoney, InnBucks, ZimSwitch, Visa and Mastercard through one hosted page.
 * Every message is signed with a SHA-512 hash of its values plus the integration key. Paynow's status
 * notifications are not trusted on their own: they only trigger a poll of the transaction's poll URL.
 */
class PaynowGateway implements PaymentGateway
{
    public const INITIATE_URL = 'https://www.paynow.co.zw/interface/initiatetransaction';

    /** Paynow statuses that mean the money has been taken. */
    public const PAID_STATUSES = ['paid', 'awaiting delivery', 'delivered'];

    public const FAILED_STATUSES = ['failed', 'disputed', 'refunded'];

    public function key(): string
    {
        return 'paynow';
    }

    public function label(): string
    {
        return 'Paynow';
    }

    public function methods(): string
    {
        return 'EcoCash, OneMoney, InnBucks, ZimSwitch, Visa or Mastercard';
    }

    public function fields(): array
    {
        return [
            'integration_id' => ['label' => 'Integration ID', 'secret' => false],
            'integration_key' => ['label' => 'Integration key', 'secret' => true, 'help' => 'From Paynow → Receive payments → 3rd party shopping cart or link.'],
        ];
    }

    public function start(PaymentAttempt $attempt, Charge $charge, array $credentials): string
    {
        $fields = [
            'id' => $credentials['integration_id'],
            'reference' => $charge->reference,
            'amount' => number_format($attempt->attemptAmount(), 2, '.', ''),
            'additionalinfo' => $charge->description,
            'returnurl' => $charge->returnUrl,
            'resulturl' => $charge->webhookUrl,
        ];
        if ($charge->email) {
            $fields['authemail'] = $charge->email;
        }
        $fields['status'] = 'Message';
        $fields['hash'] = self::hash($fields, $credentials['integration_key']);

        $data = $this->send(self::INITIATE_URL, $fields, $credentials['integration_key']);
        if (strtolower($data['status'] ?? '') !== 'ok' || empty($data['browserurl']) || empty($data['pollurl'])) {
            throw new GatewayException('Paynow did not accept the payment: '.($data['error'] ?? 'no reason given').'.');
        }

        $attempt->remember(['poll_url' => $data['pollurl']]);

        return $data['browserurl'];
    }

    public function check(PaymentAttempt $attempt, array $credentials): GatewayResult
    {
        $pollUrl = $attempt->pollUrl();
        if (! $pollUrl || ! self::isPaynowUrl($pollUrl)) {
            throw new GatewayException('This Paynow payment has no poll URL to check.');
        }

        $data = $this->send($pollUrl, [], $credentials['integration_key']);
        $status = strtolower(trim($data['status'] ?? ''));

        if (in_array($status, self::PAID_STATUSES, true)) {
            if (Money::round((float) ($data['amount'] ?? 0)) < Money::round($attempt->attemptAmount())) {
                return GatewayResult::failed('Paynow reported '.($data['amount'] ?? '0').' paid, less than the '.$attempt->money().' due.');
            }

            return GatewayResult::paid($data['paynowreference'] ?? null);
        }
        if ($status === 'cancelled') {
            return GatewayResult::failed('The payment was cancelled.', 'cancelled');
        }
        if (in_array($status, self::FAILED_STATUSES, true)) {
            return GatewayResult::failed('Paynow reports the payment as '.$status.'.');
        }

        return GatewayResult::pending();
    }

    /** Paynow posts the status to the result URL; the attempt is then re-checked against the poll URL. */
    public function notification(Request $request, array $credentials): ?array
    {
        $attempt = (string) $request->query('attempt', '');

        return $attempt === '' ? null : ['reference' => $attempt, 'result' => null];
    }

    /**
     * Paynow's signature: SHA-512 of every value except the hash itself, in order, followed by the integration key.
     *
     * @param  array<string, mixed>  $values
     */
    public static function hash(array $values, string $integrationKey): string
    {
        $string = '';
        foreach ($values as $key => $value) {
            if (strtolower((string) $key) !== 'hash') {
                $string .= $value;
            }
        }

        return strtoupper(hash('sha512', $string.$integrationKey));
    }

    public static function isPaynowUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https' && ($host === 'paynow.co.zw' || str_ends_with($host, '.paynow.co.zw'));
    }

    /**
     * Post to Paynow and return its verified, url-encoded reply.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    protected function send(string $url, array $fields, string $integrationKey): array
    {
        try {
            $response = Http::asForm()->timeout(20)->post($url, $fields);
        } catch (ConnectionException $e) {
            throw new GatewayException('Paynow could not be reached. Try again in a moment.', 0, $e);
        }
        if ($response->failed()) {
            throw new GatewayException('Paynow returned an error (HTTP '.$response->status().').');
        }

        parse_str(trim($response->body()), $data);
        if (strtolower($data['status'] ?? '') === 'error') {
            return $data;
        }
        if (empty($data['hash']) || ! hash_equals(self::hash($data, $integrationKey), strtoupper($data['hash']))) {
            throw new GatewayException('Paynow\'s reply failed signature verification. Check the integration key.');
        }

        return $data;
    }
}
