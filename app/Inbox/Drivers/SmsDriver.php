<?php

namespace App\Inbox\Drivers;

use App\Inbox\ChannelDriver;
use App\Inbox\InboxException;
use App\Models\InboxChannel;
use App\Models\InboxConversation;
use App\Sms\PhoneNumber;
use App\Sms\SmsException;
use App\Sms\SmsService;
use Illuminate\Http\Request;

/**
 * Text messages to the workspace's SMS number. Incoming texts arrive in Twilio (From / Body) or
 * Africa's Talking (from / text) format; replies go out through the provider chosen under SMS settings.
 */
class SmsDriver implements ChannelDriver
{
    public function __construct(protected SmsService $sms) {}

    public function key(): string
    {
        return 'sms';
    }

    public function label(): string
    {
        return 'SMS';
    }

    public function icon(): string
    {
        return 'message-square';
    }

    public function description(): string
    {
        return 'Texts to your SMS number. Replies use the provider set up under Text messages › SMS settings.';
    }

    public function addressLabel(): string
    {
        return 'Phone number';
    }

    public function setupHelp(): string
    {
        return 'In Twilio set this as the number\'s "A message comes in" webhook (HTTP POST); in Africa\'s Talking set it as the incoming messages callback.';
    }

    public function fields(): array
    {
        return [];
    }

    public function authorize(Request $request, InboxChannel $channel): bool
    {
        return true;
    }

    public function parse(Request $request, InboxChannel $channel): array
    {
        $handle = PhoneNumber::normalize((string) ($request->input('From') ?? $request->input('from')), $channel->workspace?->country_code);
        if (! $handle) {
            return [];
        }

        return [[
            'handle' => $handle,
            'name' => null,
            'subject' => null,
            'body' => (string) ($request->input('Body') ?? $request->input('text') ?? ''),
            'external_id' => ($request->input('MessageSid') ?? $request->input('id')) ?: null,
        ]];
    }

    public function send(InboxChannel $channel, InboxConversation $conversation, string $body): string
    {
        $workspace = $channel->workspace;
        $provider = $workspace ? $this->sms->provider($workspace) : null;
        if (! $provider) {
            throw new InboxException('SMS is not set up. Choose a provider under Text messages › SMS settings.');
        }

        try {
            return $provider->send($conversation->handle, mb_substr($body, 0, SmsService::MAX_LENGTH), $this->sms->credentials($workspace, $provider));
        } catch (SmsException $e) {
            throw new InboxException($e->getMessage(), $e->permanent, $e);
        }
    }
}
