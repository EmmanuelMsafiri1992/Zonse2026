<?php

namespace App\Sms\Providers;

use App\Sms\SmsException;
use App\Sms\SmsProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AfricasTalkingProvider implements SmsProvider
{
    public const API = 'https://api.africastalking.com/version1/messaging';

    public const SANDBOX_API = 'https://api.sandbox.africastalking.com/version1/messaging';

    /** Per-recipient status codes that mean the message was accepted (Processed, Sent, Queued). */
    public const ACCEPTED = [100, 101, 102];

    public function key(): string
    {
        return 'africastalking';
    }

    public function label(): string
    {
        return "Africa's Talking";
    }

    public function description(): string
    {
        return 'East, Southern and West Africa (Kenya, Uganda, Tanzania, Zambia, Malawi, Nigeria and more).';
    }

    public function fields(): array
    {
        return [
            'username' => ['label' => 'Username', 'secret' => false, 'required' => true, 'help' => 'Use "sandbox" to test without sending.'],
            'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
            'from' => ['label' => 'Sender ID', 'secret' => false, 'required' => false, 'help' => 'Optional. An approved alphanumeric sender name or short code.'],
        ];
    }

    public function send(string $to, string $body, array $credentials): string
    {
        $url = $credentials['username'] === 'sandbox' ? self::SANDBOX_API : self::API;

        try {
            $response = Http::withHeaders(['apiKey' => $credentials['api_key'], 'Accept' => 'application/json'])->asForm()->timeout(20)
                ->post($url, array_filter(['username' => $credentials['username'], 'to' => $to, 'message' => $body, 'from' => $credentials['from'] ?? null]));
        } catch (ConnectionException $e) {
            throw new SmsException("Africa's Talking could not be reached.", false, $e);
        }

        if ($response->failed()) {
            throw new SmsException("Africa's Talking: ".(trim($response->body()) ?: 'HTTP '.$response->status()), $response->status() < 500);
        }

        $recipient = $response->json('SMSMessageData.Recipients.0');
        if (! $recipient || ! in_array((int) ($recipient['statusCode'] ?? 0), self::ACCEPTED, true)) {
            throw new SmsException("Africa's Talking: ".($recipient['status'] ?? $response->json('SMSMessageData.Message') ?? 'message not accepted'));
        }

        return (string) ($recipient['messageId'] ?? '');
    }
}
