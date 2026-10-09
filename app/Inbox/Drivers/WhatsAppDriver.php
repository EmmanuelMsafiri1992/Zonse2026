<?php

namespace App\Inbox\Drivers;

use App\Models\InboxChannel;
use App\Models\InboxConversation;
use App\Sms\PhoneNumber;
use Illuminate\Http\Request;

/** A WhatsApp Business number on Meta's WhatsApp Cloud API. */
class WhatsAppDriver extends MetaDriver
{
    public function key(): string
    {
        return 'whatsapp';
    }

    public function label(): string
    {
        return 'WhatsApp';
    }

    public function icon(): string
    {
        return 'message-circle';
    }

    public function description(): string
    {
        return "A WhatsApp Business number on the Cloud API. Free-form replies are allowed within 24 hours of the customer's last message.";
    }

    public function addressLabel(): string
    {
        return 'WhatsApp number';
    }

    public function fields(): array
    {
        return [
            'phone_number_id' => ['label' => 'Phone number ID', 'secret' => false, 'required' => true, 'help' => 'WhatsApp › API setup in your Meta app.'],
            'access_token' => ['label' => 'Access token', 'secret' => true, 'required' => true, 'help' => 'A permanent system-user token with whatsapp_business_messaging.'],
            'app_secret' => ['label' => 'App secret', 'secret' => true, 'required' => false, 'help' => 'Used to check that webhook calls really come from Meta.'],
        ];
    }

    protected function webhookObject(): string
    {
        return 'whatsapp_business_account';
    }

    public function parse(Request $request, InboxChannel $channel): array
    {
        if (! $this->isForThisChannel($request)) {
            return [];
        }

        $messages = [];
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $names = collect((array) ($value['contacts'] ?? []))
                    ->mapWithKeys(fn ($contact) => [(string) ($contact['wa_id'] ?? '') => $contact['profile']['name'] ?? null]);
                foreach ((array) ($value['messages'] ?? []) as $message) {
                    $from = (string) ($message['from'] ?? '');
                    $handle = PhoneNumber::normalize('+'.ltrim($from, '+'));
                    if (! $handle) {
                        continue;
                    }
                    $messages[] = [
                        'handle' => $handle,
                        'name' => $names[$from] ?? null,
                        'subject' => null,
                        'body' => $this->text((array) $message),
                        'external_id' => $message['id'] ?? null,
                    ];
                }
            }
        }

        return $messages;
    }

    /**
     * Text for any message type; media is noted so the team knows to look in WhatsApp.
     *
     * @param  array<string, mixed>  $message
     */
    protected function text(array $message): string
    {
        $type = (string) ($message['type'] ?? 'text');

        return match ($type) {
            'text' => (string) ($message['text']['body'] ?? ''),
            'button' => (string) ($message['button']['text'] ?? ''),
            'interactive' => (string) ($message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? ''),
            'location' => trim('[Location] '.($message['location']['name'] ?? '').' '.($message['location']['latitude'] ?? '').','.($message['location']['longitude'] ?? '')),
            default => trim('['.ucfirst($type).' received – open WhatsApp to view] '.($message[$type]['caption'] ?? '')),
        };
    }

    public function send(InboxChannel $channel, InboxConversation $conversation, string $body): string
    {
        $response = $this->post('/'.rawurlencode($channel->credential('phone_number_id')).'/messages', $channel->credential('access_token'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => ltrim($conversation->handle, '+'),
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => mb_substr($body, 0, 4096)],
        ]);

        return (string) $response->json('messages.0.id');
    }
}
