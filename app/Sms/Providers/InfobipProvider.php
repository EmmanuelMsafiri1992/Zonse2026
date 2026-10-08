<?php

namespace App\Sms\Providers;

use App\Sms\SmsException;
use App\Sms\SmsProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class InfobipProvider implements SmsProvider
{
    public function key(): string
    {
        return 'infobip';
    }

    public function label(): string
    {
        return 'Infobip';
    }

    public function description(): string
    {
        return 'Worldwide with strong African carrier coverage. Each account has its own API address.';
    }

    public function fields(): array
    {
        return [
            'base_url' => ['label' => 'API base URL', 'secret' => false, 'required' => true, 'help' => 'From your Infobip dashboard, e.g. xxxxx.api.infobip.com'],
            'api_key' => ['label' => 'API key', 'secret' => true, 'required' => true],
            'from' => ['label' => 'Sender ID', 'secret' => false, 'required' => false, 'help' => 'Optional. An approved sender name or number.'],
        ];
    }

    public function send(string $to, string $body, array $credentials): string
    {
        $host = strtolower((string) preg_replace('#^https?://#i', '', rtrim($credentials['base_url'], '/')));
        if (! preg_match('/^[a-z0-9-]+\.api\.infobip\.com$/', $host)) {
            throw new SmsException('Infobip: the API base URL should look like xxxxx.api.infobip.com.');
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'App '.$credentials['api_key']])->acceptJson()->timeout(20)
                ->post('https://'.$host.'/sms/2/text/advanced', ['messages' => [array_filter([
                    'destinations' => [['to' => ltrim($to, '+')]],
                    'from' => $credentials['from'] ?? null,
                    'text' => $body,
                ])]]);
        } catch (ConnectionException $e) {
            throw new SmsException('Infobip could not be reached.', false, $e);
        }

        if ($response->failed()) {
            throw new SmsException('Infobip: '.($response->json('requestError.serviceException.text') ?? 'HTTP '.$response->status()), $response->status() < 500 && $response->status() !== 429);
        }

        $message = $response->json('messages.0');
        if (! $message || in_array($message['status']['groupName'] ?? '', ['REJECTED', 'UNDELIVERABLE'], true)) {
            throw new SmsException('Infobip: '.($message['status']['description'] ?? 'message not accepted'));
        }

        return (string) ($message['messageId'] ?? '');
    }
}
