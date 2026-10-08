<?php

namespace App\Sms\Providers;

use App\Sms\SmsException;
use App\Sms\SmsProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TwilioProvider implements SmsProvider
{
    public const API = 'https://api.twilio.com/2010-04-01';

    public function key(): string
    {
        return 'twilio';
    }

    public function label(): string
    {
        return 'Twilio';
    }

    public function description(): string
    {
        return 'Worldwide, including Zimbabwe. Pay as you go.';
    }

    public function fields(): array
    {
        return [
            'account_sid' => ['label' => 'Account SID', 'secret' => false, 'required' => true, 'help' => 'Starts with AC.'],
            'auth_token' => ['label' => 'Auth token', 'secret' => true, 'required' => true],
            'from' => ['label' => 'From number or Messaging Service SID', 'secret' => false, 'required' => true, 'help' => 'A Twilio number (+1…), an approved sender name, or MG… for a Messaging Service.'],
        ];
    }

    public function send(string $to, string $body, array $credentials): string
    {
        $from = $credentials['from'];
        $payload = ['To' => $to, 'Body' => $body] + (str_starts_with($from, 'MG') ? ['MessagingServiceSid' => $from] : ['From' => $from]);

        try {
            $response = Http::withBasicAuth($credentials['account_sid'], $credentials['auth_token'])->asForm()->timeout(20)
                ->post(self::API.'/Accounts/'.rawurlencode($credentials['account_sid']).'/Messages.json', $payload);
        } catch (ConnectionException $e) {
            throw new SmsException('Twilio could not be reached.', false, $e);
        }

        if ($response->failed()) {
            throw new SmsException('Twilio: '.($response->json('message') ?? 'HTTP '.$response->status()), $response->status() < 500 && $response->status() !== 429);
        }

        return (string) $response->json('sid');
    }
}
