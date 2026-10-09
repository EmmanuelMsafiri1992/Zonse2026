<?php

namespace App\Inbox\Drivers;

use App\Models\InboxChannel;
use App\Models\InboxConversation;
use Illuminate\Http\Request;

/** Messages to a Facebook page through the Messenger Platform. */
class FacebookDriver extends MetaDriver
{
    public function key(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook Messenger';
    }

    public function icon(): string
    {
        return 'facebook';
    }

    public function description(): string
    {
        return "Private messages to your Facebook page. Replies are allowed within 24 hours of the customer's last message.";
    }

    public function addressLabel(): string
    {
        return 'Page name or ID';
    }

    public function fields(): array
    {
        return [
            'page_access_token' => ['label' => 'Page access token', 'secret' => true, 'required' => true, 'help' => 'With the pages_messaging permission.'],
            'app_secret' => ['label' => 'App secret', 'secret' => true, 'required' => false, 'help' => 'Used to check that webhook calls really come from Meta.'],
        ];
    }

    protected function webhookObject(): string
    {
        return 'page';
    }

    public function parse(Request $request, InboxChannel $channel): array
    {
        if (! $this->isForThisChannel($request)) {
            return [];
        }

        $messages = [];
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                $message = $event['message'] ?? null;
                $sender = (string) ($event['sender']['id'] ?? '');
                if (! is_array($message) || ! empty($message['is_echo']) || $sender === '') {
                    continue;
                }
                $messages[] = [
                    'handle' => $sender,
                    'name' => null,
                    'subject' => null,
                    'body' => (string) ($message['text'] ?? (empty($message['attachments']) ? '' : '[Attachment received – open '.$this->label().' to view]')),
                    'external_id' => $message['mid'] ?? null,
                ];
            }
        }

        return $messages;
    }

    public function send(InboxChannel $channel, InboxConversation $conversation, string $body): string
    {
        $response = $this->post('/me/messages', $channel->credential('page_access_token'), [
            'recipient' => ['id' => $conversation->handle],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => mb_substr($body, 0, 2000)],
        ]);

        return (string) $response->json('message_id');
    }
}
