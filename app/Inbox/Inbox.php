<?php

namespace App\Inbox;

use App\Inbox\Drivers\EmailDriver;
use App\Inbox\Drivers\FacebookDriver;
use App\Inbox\Drivers\InstagramDriver;
use App\Inbox\Drivers\SmsDriver;
use App\Inbox\Drivers\WhatsAppDriver;
use App\Jobs\SendInboxMessage;
use App\Models\InboxChannel;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Sms\PhoneNumber;
use App\Sms\SmsService;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Contacts\Models\Contact;

/**
 * The unified inbox: every channel's messages land in one list of conversations, one per person
 * per channel, linked to the matching contact. Replies are logged first and sent by a queued job;
 * a channel in test mode records replies without sending them anywhere.
 */
class Inbox
{
    /** Longest message stored or sent. */
    public const MAX_LENGTH = 5000;

    /** @var array<string, ChannelDriver> */
    protected array $drivers = [];

    public function __construct(SmsService $sms)
    {
        foreach ([new EmailDriver, new SmsDriver($sms), new WhatsAppDriver, new FacebookDriver, new InstagramDriver] as $driver) {
            $this->drivers[$driver->key()] = $driver;
        }
    }

    /** @return array<string, ChannelDriver> */
    public function drivers(): array
    {
        return $this->drivers;
    }

    public function driver(string $type): ChannelDriver
    {
        return $this->drivers[$type] ?? throw new InvalidArgumentException("Unknown inbox channel type [{$type}].");
    }

    /**
     * File one incoming message under its conversation. Returns null for empty messages and for
     * repeats of a message already received (providers retry webhooks).
     *
     * @param  array{handle: string, name?: ?string, subject?: ?string, body: string, external_id?: ?string}  $incoming
     */
    public function receive(InboxChannel $channel, array $incoming): ?InboxMessage
    {
        $handle = trim($incoming['handle']);
        $body = mb_substr(trim($incoming['body']), 0, self::MAX_LENGTH);
        $externalId = $incoming['external_id'] ?? null;
        $workspace = Workspace::query()->find($channel->workspace_id);
        if (! $workspace || $handle === '' || $body === '') {
            return null;
        }

        $result = DB::transaction(function () use ($channel, $workspace, $handle, $body, $externalId, $incoming) {
            $conversation = InboxConversation::forWorkspace($workspace)->lockForUpdate()
                ->firstOrNew(['inbox_channel_id' => $channel->id, 'handle' => $handle]);

            if ($externalId && $conversation->exists && $conversation->messages()->withoutGlobalScope('workspace')->where('external_id', $externalId)->exists()) {
                return null;
            }

            $conversation->workspace_id = $workspace->id;
            $conversation->name = $conversation->name ?: ($incoming['name'] ?? null);
            $conversation->subject = $conversation->subject ?: ($incoming['subject'] ?? null);
            $conversation->contact_id ??= $this->matchContact($workspace, $channel->type, $handle)?->id;
            $reopened = $conversation->exists && ! $conversation->isOpen();
            $conversation->status = 'open';
            $conversation->unread_count = $conversation->unread_count + 1;
            $conversation->last_message_preview = Str::limit(preg_replace('/\s+/', ' ', $body) ?? $body, 150);
            $conversation->last_message_at = now();
            $conversation->save();

            $message = InboxMessage::create([
                'workspace_id' => $workspace->id,
                'inbox_conversation_id' => $conversation->id,
                'direction' => 'in',
                'body' => $body,
                'status' => 'received',
                'external_id' => $externalId,
                'sent_at' => now(),
            ]);

            return [$conversation, $message, $reopened];
        });

        if (! $result) {
            return null;
        }
        [$conversation, $message, $reopened] = $result;

        $recipients = $conversation->assigned_to ? User::query()->whereKey($conversation->assigned_to)->get() : Notifier::admins($workspace);
        Notifier::send(
            $recipients, 'messages',
            ($reopened ? 'Reopened: ' : 'New message from ').$conversation->displayName(),
            Str::limit($body, 140), route('inbox.show', $conversation), 'inbox', $workspace,
        );

        return $message;
    }

    /**
     * Log a reply from the team and queue it for sending. The person replying takes the conversation if nobody has it.
     * A test-mode channel sends nothing, so its replies are recorded straight away instead of waiting for the queue.
     */
    public function reply(InboxConversation $conversation, string $body, User $user): InboxMessage
    {
        $message = DB::transaction(function () use ($conversation, $body, $user) {
            $body = mb_substr(trim($body), 0, self::MAX_LENGTH);
            $message = InboxMessage::create([
                'workspace_id' => $conversation->workspace_id,
                'inbox_conversation_id' => $conversation->id,
                'direction' => 'out',
                'body' => $body,
                'status' => 'queued',
                'sent_by' => $user->id,
            ]);

            $conversation->forceFill([
                'assigned_to' => $conversation->assigned_to ?? $user->id,
                'unread_count' => 0,
                'last_message_preview' => Str::limit(preg_replace('/\s+/', ' ', $body) ?? $body, 150),
                'last_message_at' => now(),
            ])->save();

            return $message;
        });

        if ($conversation->channel?->test_mode) {
            $this->deliver($message);
        } else {
            SendInboxMessage::dispatch($message->id)->afterCommit();
        }

        return $message;
    }

    /**
     * Hand a queued reply to its channel. Permanent refusals fail the reply; brief outages are
     * rethrown so the queue retries them.
     *
     * @throws InboxException when the channel is briefly unavailable
     */
    public function deliver(InboxMessage $message): void
    {
        $conversation = InboxConversation::allWorkspaces()->find($message->inbox_conversation_id);
        $channel = $conversation ? InboxChannel::allWorkspaces()->find($conversation->inbox_channel_id) : null;
        if (! $conversation || ! $channel || ! $channel->is_active) {
            $this->fail($message, 'This channel is switched off.');

            return;
        }

        if ($channel->test_mode) {
            Log::info('Inbox reply (test mode, not sent)', ['channel' => $channel->type, 'to' => $conversation->handle, 'body' => $message->body]);
            $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'external_id' => 'test-'.Str::uuid(), 'error' => null])->save();

            return;
        }
        if (! $channel->isConfigured()) {
            $this->fail($message, "Add the channel's keys under Settings › Inbox channels, or switch it to test mode.");

            return;
        }

        try {
            $id = $channel->driver()->send($channel, $conversation, $message->body);
        } catch (InboxException $e) {
            if (! $e->permanent) {
                throw $e;
            }
            $this->fail($message, $e->getMessage());

            return;
        }

        $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'external_id' => $id ?: null, 'error' => null])->save();
    }

    public function fail(InboxMessage $message, string $error): void
    {
        $message->forceFill(['status' => 'failed', 'error' => mb_substr($error, 0, 500)])->save();
    }

    /** The contact this person already is: same email for email, same number for SMS and WhatsApp. */
    public function matchContact(Workspace $workspace, string $type, string $handle): ?Contact
    {
        if ($type === 'email') {
            return Contact::forWorkspace($workspace)->whereRaw('LOWER(email) = ?', [Str::lower($handle)])->orderBy('id')->first();
        }
        if (! in_array($type, ['sms', 'whatsapp'], true)) {
            return null;
        }

        // Narrow by the last nine digits in SQL, then compare the full number as SMS would read it.
        $tail = substr(preg_replace('/\D/', '', $handle) ?? '', -9);
        if (strlen($tail) < 7) {
            return null;
        }
        $digitsOnly = fn (string $column) => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '(', ''), ')', ''), '.', '')";

        return Contact::forWorkspace($workspace)
            ->where(fn ($query) => $query->whereRaw($digitsOnly('mobile').' LIKE ?', ['%'.$tail])->orWhereRaw($digitsOnly('phone').' LIKE ?', ['%'.$tail]))
            ->orderBy('id')->limit(20)->get()
            ->first(function (Contact $contact) use ($workspace, $handle) {
                $country = $contact->country_code ?: $workspace->country_code;

                return in_array($handle, [PhoneNumber::normalize($contact->mobile, $country), PhoneNumber::normalize($contact->phone, $country)], true);
            });
    }
}
