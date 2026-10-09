<?php

namespace App\Inbox;

use App\Models\InboxChannel;
use App\Models\InboxConversation;
use Illuminate\Http\Request;

/**
 * One kind of inbox channel. A driver reads the provider's webhook into plain incoming messages
 * and sends replies back over the provider's API.
 */
interface ChannelDriver
{
    public function key(): string;

    public function label(): string;

    public function icon(): string;

    /** One line on what the channel is, shown when adding it. */
    public function description(): string;

    /** What the channel's address is called: "Email address", "Phone number", "Page ID". */
    public function addressLabel(): string;

    /** Where in the provider's dashboard the webhook URL goes. */
    public function setupHelp(): string;

    /**
     * The keys this channel needs to send real replies, keyed by field name.
     *
     * @return array<string, array{label: string, secret: bool, required: bool, help?: string}>
     */
    public function fields(): array;

    /** The request really comes from the provider (signature checks). The token in the URL is checked before this. */
    public function authorize(Request $request, InboxChannel $channel): bool;

    /**
     * The messages in one webhook call. Handles are normalised (lower-case email, +E.164 number, user id).
     *
     * @return list<array{handle: string, name: ?string, subject: ?string, body: string, external_id: ?string}>
     */
    public function parse(Request $request, InboxChannel $channel): array;

    /**
     * Send one reply and return the provider's id for it.
     *
     * @throws InboxException when the provider refuses the reply or cannot be reached
     */
    public function send(InboxChannel $channel, InboxConversation $conversation, string $body): string;
}
