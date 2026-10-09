<?php

namespace App\Inbox\Drivers;

use App\Inbox\ChannelDriver;
use App\Inbox\InboxException;
use App\Models\InboxChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shared by the Meta channels (WhatsApp, Facebook Messenger, Instagram): webhook signatures,
 * the subscription handshake and Graph API error handling.
 */
abstract class MetaDriver implements ChannelDriver
{
    public const API = 'https://graph.facebook.com/v21.0';

    /** The "object" Meta puts on this channel's webhook payloads. */
    abstract protected function webhookObject(): string;

    public function setupHelp(): string
    {
        return 'In your Meta app open Webhooks, paste this URL as the callback URL and the verify token shown here, then subscribe to messages.';
    }

    /** Once the app secret is saved, every call must carry Meta's X-Hub-Signature-256 for the raw body. */
    public function authorize(Request $request, InboxChannel $channel): bool
    {
        $secret = $channel->credential('app_secret');
        if ($secret === '') {
            return true;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string) $request->header('X-Hub-Signature-256'));
    }

    protected function isForThisChannel(Request $request): bool
    {
        return $request->input('object') === $this->webhookObject();
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InboxException
     */
    protected function post(string $path, string $token, array $payload): Response
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(20)->post(self::API.$path, $payload);
        } catch (ConnectionException $e) {
            throw new InboxException($this->label().' could not be reached.', false, $e);
        }

        if ($response->failed()) {
            throw new InboxException($this->label().': '.($response->json('error.message') ?? 'HTTP '.$response->status()), $response->status() < 500 && $response->status() !== 429);
        }

        return $response;
    }
}
