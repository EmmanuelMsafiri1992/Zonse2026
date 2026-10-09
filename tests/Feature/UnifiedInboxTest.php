<?php

namespace Tests\Feature;

use App\Inbox\Drivers\MetaDriver;
use App\Models\InboxChannel;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Contacts\Models\Contact;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class UnifiedInboxTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    /** @var array{status: int, body: array<string, mixed>} what the fake Graph API answers; tests change it */
    protected array $graph = ['status' => 200, 'body' => ['messages' => [['id' => 'wamid.OUT1']], 'message_id' => 'm_OUT1']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Http::preventStrayRequests();
        Http::fake([MetaDriver::API.'/*' => fn () => Http::response($this->graph['body'], $this->graph['status'])]);
    }

    /** @return array{0: User, 1: Workspace} */
    protected function inboxWorkspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->update(['country_code' => 'MW']);

        return [$owner, $workspace->fresh()];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function whatsappPayload(string $from = '265991112233', string $text = 'Is the shop open on Sunday?', string $id = 'wamid.IN1'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Chikondi Banda']]],
            'messages' => [['from' => $from, 'id' => $id, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }

    /** @return array<string, mixed> */
    protected function messengerPayload(string $object = 'page', string $sender = '7001', string $text = 'Do you deliver?', string $mid = 'm_IN1'): array
    {
        return ['object' => $object, 'entry' => [['messaging' => [
            ['sender' => ['id' => $sender], 'message' => ['mid' => $mid, 'text' => $text]],
            ['sender' => ['id' => '1122334455'], 'message' => ['mid' => 'm_ECHO', 'text' => 'Our own reply', 'is_echo' => true]],
        ]]]];
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: string, 5: ?string}> */
    public static function incomingPayloads(): array
    {
        return [
            'postmark email' => ['email', 'form', ['FromFull' => ['Email' => 'Grace@Example.com'], 'FromName' => 'Grace Phiri', 'Subject' => 'Order 1042', 'TextBody' => 'Where is my order?', 'MessageID' => 'pm-1'], 'grace@example.com', 'Where is my order?', 'Grace Phiri'],
            'mailgun email' => ['email', 'form', ['sender' => 'tom@example.com', 'from' => 'Tom Gondwe <tom@example.com>', 'subject' => 'Refund', 'stripped-text' => 'I want a refund', 'Message-Id' => '<mg-1@example.com>'], 'tom@example.com', 'I want a refund', null],
            'generic html email' => ['email', 'form', ['from' => '"Ann Zulu" <ann@example.com>', 'subject' => 'Hi', 'html' => '<p>Hello&nbsp;there</p>'], 'ann@example.com', "Hello\u{a0}there", 'Ann Zulu'],
            'twilio sms' => ['sms', 'form', ['From' => '+265991112233', 'Body' => 'Price of cement?', 'MessageSid' => 'SM1'], '+265991112233', 'Price of cement?', null],
            'africas talking sms' => ['sms', 'form', ['from' => '0991112233', 'text' => 'Stock check', 'id' => 'AT1'], '+265991112233', 'Stock check', null],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('incomingPayloads')]
    public function test_incoming_email_and_sms_webhooks_open_a_conversation(string $type, string $format, array $payload, string $handle, string $body, ?string $name): void
    {
        [, $workspace] = $this->inboxWorkspace();
        $channel = InboxChannel::factory()->for($workspace)->create(['type' => $type]);

        $this->post(route('inbox.webhook', $channel->token), $payload)->assertOk()->assertJson(['received' => 1]);

        $conversation = InboxConversation::allWorkspaces()->sole();
        $this->assertSame($workspace->id, $conversation->workspace_id);
        $this->assertSame($handle, $conversation->handle);
        $this->assertSame($name, $conversation->name);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame('open', $conversation->status);
        $message = InboxMessage::allWorkspaces()->sole();
        $this->assertSame($body, $message->body);
        $this->assertSame('in', $message->direction);
        $this->assertSame('received', $message->status);
    }

    public function test_whatsapp_messages_are_received_and_repeats_are_ignored(): void
    {
        [, $workspace] = $this->inboxWorkspace();
        $channel = InboxChannel::factory()->whatsapp()->for($workspace)->create();

        $this->postJson(route('inbox.webhook', $channel->token), $this->whatsappPayload())->assertOk()->assertJson(['received' => 1]);
        $this->postJson(route('inbox.webhook', $channel->token), $this->whatsappPayload())->assertOk()->assertJson(['received' => 0]);
        $this->postJson(route('inbox.webhook', $channel->token), $this->whatsappPayload(text: 'Hello?', id: 'wamid.IN2'))->assertOk()->assertJson(['received' => 1]);

        $conversation = InboxConversation::allWorkspaces()->sole();
        $this->assertSame('+265991112233', $conversation->handle);
        $this->assertSame('Chikondi Banda', $conversation->name);
        $this->assertSame(2, $conversation->unread_count);
        $this->assertSame('Hello?', $conversation->last_message_preview);
        $this->assertSame(2, InboxMessage::allWorkspaces()->count());
    }

    public function test_facebook_and_instagram_messages_skip_echoes_and_other_objects(): void
    {
        [, $workspace] = $this->inboxWorkspace();
        $facebook = InboxChannel::factory()->facebook()->for($workspace)->create();
        $instagram = InboxChannel::factory()->facebook()->for($workspace)->create(['type' => 'instagram', 'name' => 'Instagram']);

        $this->postJson(route('inbox.webhook', $facebook->token), $this->messengerPayload())->assertJson(['received' => 1]);
        $this->postJson(route('inbox.webhook', $instagram->token), $this->messengerPayload('instagram', '9001', 'Love the dress', 'ig_1'))->assertJson(['received' => 1]);
        $this->postJson(route('inbox.webhook', $instagram->token), $this->messengerPayload('page', '9002'))->assertJson(['received' => 0]);

        $this->assertSame(['7001'], InboxConversation::allWorkspaces()->where('inbox_channel_id', $facebook->id)->pluck('handle')->all());
        $this->assertSame(['9001'], InboxConversation::allWorkspaces()->where('inbox_channel_id', $instagram->id)->pluck('handle')->all());
        $this->assertFalse(InboxMessage::allWorkspaces()->where('body', 'Our own reply')->exists());
    }

    public function test_unknown_tokens_bad_signatures_and_switched_off_channels_are_refused(): void
    {
        [, $workspace] = $this->inboxWorkspace();
        $channel = InboxChannel::factory()->whatsapp('app-secret')->for($workspace)->create();
        $payload = json_encode($this->whatsappPayload());

        $this->post(route('inbox.webhook', 'short'), [])->assertNotFound();
        $this->post(route('inbox.webhook', str_repeat('x', 48)), [])->assertNotFound();
        $this->call('POST', route('inbox.webhook', $channel->token), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=wrong'], $payload)->assertForbidden();
        $this->assertSame(0, InboxMessage::allWorkspaces()->count());

        $signature = 'sha256='.hash_hmac('sha256', $payload, 'app-secret');
        $this->call('POST', route('inbox.webhook', $channel->token), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $payload)
            ->assertOk()->assertJson(['received' => 1]);

        $channel->update(['is_active' => false]);
        $this->call('POST', route('inbox.webhook', $channel->token), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', json_encode($this->whatsappPayload(id: 'wamid.IN9')), 'app-secret')], json_encode($this->whatsappPayload(id: 'wamid.IN9')))
            ->assertOk()->assertJson(['received' => 0]);
    }

    public function test_meta_verification_handshake_echoes_the_challenge_only_for_the_right_token(): void
    {
        [, $workspace] = $this->inboxWorkspace();
        $channel = InboxChannel::factory()->facebook()->for($workspace)->create();

        $this->get(route('inbox.webhook.verify', ['token' => $channel->token, 'hub_mode' => 'subscribe', 'hub_verify_token' => $channel->token, 'hub_challenge' => '12345']))
            ->assertOk()->assertSeeText('12345');
        $this->get(route('inbox.webhook.verify', ['token' => $channel->token, 'hub_mode' => 'subscribe', 'hub_verify_token' => 'nope', 'hub_challenge' => '12345']))
            ->assertForbidden();
    }

    public function test_incoming_messages_link_the_matching_contact_and_reopen_closed_conversations(): void
    {
        [, $workspace] = $this->inboxWorkspace();
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Chikondi Banda', 'mobile' => '0991 112 233', 'phone' => null, 'country_code' => 'MW']);
        $emailContact = Contact::factory()->for($workspace)->create(['name' => 'Grace Phiri', 'email' => 'Grace@Example.com']);
        $whatsapp = InboxChannel::factory()->whatsapp()->for($workspace)->create();
        $email = InboxChannel::factory()->for($workspace)->create();

        $this->postJson(route('inbox.webhook', $whatsapp->token), $this->whatsappPayload())->assertOk();
        $this->post(route('inbox.webhook', $email->token), ['from' => 'grace@example.com', 'text' => 'Hi'])->assertOk();

        $conversation = InboxConversation::allWorkspaces()->where('inbox_channel_id', $whatsapp->id)->sole();
        $this->assertSame($contact->id, $conversation->contact_id);
        $this->assertSame($emailContact->id, InboxConversation::allWorkspaces()->where('inbox_channel_id', $email->id)->value('contact_id'));

        $conversation->update(['status' => 'closed']);
        $this->postJson(route('inbox.webhook', $whatsapp->token), $this->whatsappPayload(text: 'One more thing', id: 'wamid.IN3'))->assertOk();
        $this->assertSame('open', $conversation->fresh()->status);
    }

    public function test_new_messages_notify_the_assignee_or_the_admins(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->inboxWorkspace();
        $agent = $this->memberOf($workspace);
        $channel = InboxChannel::factory()->whatsapp()->for($workspace)->create();

        $this->postJson(route('inbox.webhook', $channel->token), $this->whatsappPayload())->assertOk();
        Notification::assertSentTo($owner, WorkspaceAlert::class, fn (WorkspaceAlert $alert) => $alert->kind === 'messages' && $alert->title === 'New message from Chikondi Banda');
        Notification::assertNotSentTo($agent, WorkspaceAlert::class);

        InboxConversation::allWorkspaces()->sole()->update(['assigned_to' => $agent->id]);
        $this->postJson(route('inbox.webhook', $channel->token), $this->whatsappPayload(id: 'wamid.IN4'))->assertOk();
        Notification::assertSentTo($agent, WorkspaceAlert::class);
        Notification::assertSentToTimes($owner, WorkspaceAlert::class, 1);
    }

    public function test_the_inbox_lists_conversations_and_opening_one_marks_it_read(): void
    {
        [$owner, $workspace] = $this->inboxWorkspace();
        $channel = InboxChannel::factory()->whatsapp()->for($workspace)->create();
        $conversation = InboxConversation::factory()->for($channel, 'channel')->create(['workspace_id' => $workspace->id, 'name' => 'Chikondi Banda', 'handle' => '+265991112233', 'unread_count' => 3]);
        InboxMessage::factory()->for($conversation, 'conversation')->create(['workspace_id' => $workspace->id, 'body' => 'Is the shop open on Sunday?']);
        InboxConversation::factory()->for($channel, 'channel')->create(['workspace_id' => $workspace->id, 'name' => 'Closed Person', 'status' => 'closed']);

        $this->actingAs($owner)->get(route('inbox.index'))
            ->assertOk()->assertSee('Chikondi Banda')->assertDontSee('Closed Person');
        $this->actingAs($owner)->get(route('inbox.index', ['view' => 'closed']))
            ->assertOk()->assertSee('Closed Person')->assertDontSee('Chikondi Banda');
        $this->actingAs($owner)->get(route('inbox.index', ['q' => 'nobody-matches']))
            ->assertOk()->assertDontSee('Chikondi Banda');

        $this->actingAs($owner)->get(route('inbox.show', $conversation))
            ->assertOk()->assertSee('Is the shop open on Sunday?')->assertSee('Send reply');
        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    public function test_a_reply_in_test_mode_is_recorded_without_sending_and_assigns_the_replier(): void
    {
        [$owner, $workspace] = $this->inboxWorkspace();
        $channel = InboxChannel::factory()->whatsapp()->for($workspace)->create(['test_mode' => true]);
        $conversation = InboxConversation::factory()->for($channel, 'channel')->create(['workspace_id' => $workspace->id, 'handle' => '+265991112233']);

        $this->actingAs($owner)->post(route('inbox.reply', $conversation), ['body' => 'Yes, 9 to 1.'])
            ->assertRedirect(route('inbox.show', $conversation))->assertSessionHas('flash.message', 'Reply recorded (test mode: nothing was sent).');

        $reply = InboxMessage::allWorkspaces()->where('direction', 'out')->sole();
        $this->assertSame('sent', $reply->status);
        $this->assertStringStartsWith('test-', $reply->external_id);
        $this->assertSame($owner->id, $reply->sent_by);
        $this->assertSame($owner->id, $conversation->fresh()->assigned_to);
        $this->assertSame(0, $conversation->fresh()->unread_count);
        Http::assertNothingSent();
    }

    public function test_live_whatsapp_and_messenger_replies_go_through_the_graph_api(): void
    {
        [$owner, $workspace] = $this->inboxWorkspace();
        $whatsapp = InboxChannel::factory()->whatsapp()->for($workspace)->create();
        $facebook = InboxChannel::factory()->facebook()->for($workspace)->create();
        $waConversation = InboxConversation::factory()->for($whatsapp, 'channel')->create(['workspace_id' => $workspace->id, 'handle' => '+265991112233']);
        $fbConversation = InboxConversation::factory()->for($facebook, 'channel')->create(['workspace_id' => $workspace->id, 'handle' => '7001']);

        $this->actingAs($owner)->post(route('inbox.reply', $waConversation), ['body' => 'Yes we are open'])->assertSessionHas('flash.message', 'Reply sent.');
        $this->actingAs($owner)->post(route('inbox.reply', $fbConversation), ['body' => 'We deliver in town'])->assertSessionHas('flash.message', 'Reply sent.');

        Http::assertSent(fn (HttpRequest $request) => $request->url() === MetaDriver::API.'/1098765432/messages'
            && $request->hasHeader('Authorization', 'Bearer wa-token')
            && $request['to'] === '265991112233' && $request['text']['body'] === 'Yes we are open');
        Http::assertSent(fn (HttpRequest $request) => $request->url() === MetaDriver::API.'/me/messages'
            && $request->hasHeader('Authorization', 'Bearer fb-token')
            && $request['recipient']['id'] === '7001' && $request['message']['text'] === 'We deliver in town');
        $this->assertSame(['wamid.OUT1', 'm_OUT1'], InboxMessage::allWorkspaces()->where('direction', 'out')->orderBy('id')->pluck('external_id')->all());
    }

    public function test_a_refused_reply_is_marked_failed_with_the_providers_reason(): void
    {
        $this->graph = ['status' => 400, 'body' => ['error' => ['message' => 'Re-engagement window has closed']]];
        [$owner, $workspace] = $this->inboxWorkspace();
        $channel = InboxChannel::factory()->whatsapp()->for($workspace)->create();
        $conversation = InboxConversation::factory()->for($channel, 'channel')->create(['workspace_id' => $workspace->id, 'handle' => '+265991112233']);

        $this->actingAs($owner)->post(route('inbox.reply', $conversation), ['body' => 'Hello again'])
            ->assertSessionHas('flash.type', 'danger');

        $reply = InboxMessage::allWorkspaces()->sole();
        $this->assertSame('failed', $reply->status);
        $this->assertSame('WhatsApp: Re-engagement window has closed', $reply->error);
    }

    public function test_replies_fail_clearly_when_keys_or_sms_setup_are_missing(): void
    {
        [$owner, $workspace] = $this->inboxWorkspace();
        $whatsapp = InboxChannel::factory()->for($workspace)->create(['type' => 'whatsapp', 'credentials' => [], 'test_mode' => false]);
        $sms = InboxChannel::factory()->for($workspace)->create(['type' => 'sms', 'test_mode' => false]);
        $waConversation = InboxConversation::factory()->for($whatsapp, 'channel')->create(['workspace_id' => $workspace->id]);
        $smsConversation = InboxConversation::factory()->for($sms, 'channel')->create(['workspace_id' => $workspace->id, 'handle' => '+265991112233']);

        $this->actingAs($owner)->post(route('inbox.reply', $waConversation), ['body' => 'Hi']);
        $this->actingAs($owner)->post(route('inbox.reply', $smsConversation), ['body' => 'Hi']);

        $this->assertSame([
            "Add the channel's keys under Settings › Inbox channels, or switch it to test mode.",
            'SMS is not set up. Choose a provider under Text messages › SMS settings.',
        ], InboxMessage::allWorkspaces()->orderBy('id')->pluck('error')->all());
        Http::assertNothingSent();
    }

    public function test_live_email_replies_are_mailed_from_the_channel_address(): void
    {
        Mail::fake();
        [$owner, $workspace] = $this->inboxWorkspace();
        $channel = InboxChannel::factory()->live()->for($workspace)->create(['address' => 'help@shop.test']);
        $conversation = InboxConversation::factory()->for($channel, 'channel')->create(['workspace_id' => $workspace->id, 'handle' => 'grace@example.com', 'subject' => 'Order 1042']);

        $this->actingAs($owner)->post(route('inbox.reply', $conversation), ['body' => 'It ships today.'])->assertSessionHas('flash.message', 'Reply sent.');

        $this->assertSame('sent', InboxMessage::allWorkspaces()->sole()->status);
    }

    public function test_conversations_can_be_assigned_closed_and_linked_to_contacts(): void
    {
        [$owner, $workspace] = $this->inboxWorkspace();
        $agent = $this->memberOf($workspace);
        $outsider = User::factory()->create();
        $channel = InboxChannel::factory()->whatsapp()->for($workspace)->create();
        $conversation = InboxConversation::factory()->for($channel, 'channel')->create(['workspace_id' => $workspace->id, 'handle' => '+265991112233', 'name' => 'Chikondi Banda']);
        $contact = Contact::factory()->for($workspace)->create();
        [, $otherWorkspace] = $this->ownerWithWorkspace();
        $foreignContact = Contact::factory()->for($otherWorkspace)->create();

        $this->actingAs($owner)->patch(route('inbox.update', $conversation), ['assigned_to' => $agent->id])->assertSessionHas('flash.message', "Assigned to {$agent->name}.");
        $this->actingAs($owner)->patch(route('inbox.update', $conversation), ['assigned_to' => $outsider->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($owner)->patch(route('inbox.update', $conversation), ['contact_id' => $foreignContact->id])->assertSessionHasErrors('contact_id');
        $this->actingAs($owner)->patch(route('inbox.update', $conversation), ['contact_id' => $contact->id])->assertSessionHasNoErrors();
        $this->actingAs($owner)->patch(route('inbox.update', $conversation), ['status' => 'closed'])->assertSessionHas('flash.message', 'Conversation closed.');

        $conversation->refresh();
        $this->assertSame($agent->id, $conversation->assigned_to);
        $this->assertSame($contact->id, $conversation->contact_id);
        $this->assertSame('closed', $conversation->status);

        $this->actingAs($agent)->get(route('inbox.index', ['view' => 'mine']))->assertOk()->assertDontSee($contact->displayName());
        $this->actingAs($agent)->get(route('inbox.index', ['view' => 'closed']))->assertOk()->assertSee($contact->displayName());
    }

    public function test_the_person_writing_in_can_be_saved_as_a_new_contact(): void
    {
        [$owner, $workspace] = $this->inboxWorkspace();
        $channel = InboxChannel::factory()->whatsapp()->for($workspace)->create();
        $conversation = InboxConversation::factory()->for($channel, 'channel')->create(['workspace_id' => $workspace->id, 'handle' => '+265991112233', 'name' => 'Chikondi Banda', 'contact_id' => null]);

        $this->actingAs($owner)->post(route('inbox.contact', $conversation))->assertSessionHas('flash.message', 'Chikondi Banda saved as a contact.');

        $contact = Contact::forWorkspace($workspace)->sole();
        $this->assertSame('+265991112233', $contact->mobile);
        $this->assertSame('customer', $contact->type);
        $this->assertSame($contact->id, $conversation->fresh()->contact_id);
    }

    public function test_viewers_cannot_use_the_inbox_and_other_workspaces_conversations_are_hidden(): void
    {
        [$owner, $workspace] = $this->inboxWorkspace();
        $viewer = $this->memberOf($workspace, 'viewer');
        [$otherOwner, $otherWorkspace] = $this->ownerWithWorkspace();
        $foreignChannel = InboxChannel::factory()->for($otherWorkspace)->create();
        $foreign = InboxConversation::factory()->for($foreignChannel, 'channel')->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Secret Customer']);

        $this->actingAs($viewer)->get(route('inbox.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('inbox.index', ['view' => 'all']))->assertOk()->assertDontSee('Secret Customer');
        $this->actingAs($owner)->get(route('inbox.show', $foreign))->assertNotFound();
        $this->actingAs($owner)->post(route('inbox.reply', $foreign), ['body' => 'Hi'])->assertNotFound();
        $this->assertSame(0, InboxMessage::allWorkspaces()->count());
    }

    public function test_admins_add_edit_and_remove_channels_and_secrets_are_kept_when_left_blank(): void
    {
        [$owner, $workspace] = $this->inboxWorkspace();

        $this->actingAs($owner)->get(route('settings.inbox.index'))->assertOk()->assertSee('Add a channel')->assertSee('WhatsApp');

        $this->actingAs($owner)->post(route('settings.inbox.store'), [
            'type' => 'whatsapp', 'name' => 'Shop WhatsApp', 'address' => '+265991234567', 'test_mode' => '0',
            'credentials' => ['whatsapp' => ['phone_number_id' => '555', 'access_token' => 'tok-1', 'app_secret' => ''], 'email' => ['ignored' => 'x']],
        ])->assertRedirect(route('settings.inbox.index'));

        $channel = InboxChannel::forWorkspace($workspace)->sole();
        $this->assertSame(['phone_number_id' => '555', 'access_token' => 'tok-1', 'app_secret' => ''], $channel->credentials);
        $this->assertFalse($channel->test_mode);
        $this->assertTrue($channel->isConfigured());
        $this->assertSame(48, strlen($channel->token));

        $this->actingAs($owner)->get(route('settings.inbox.index'))->assertOk()->assertSee($channel->webhookUrl())->assertDontSee('tok-1');

        $this->actingAs($owner)->put(route('settings.inbox.update', $channel), [
            'name' => 'Main WhatsApp', 'test_mode' => '1', 'is_active' => '1',
            'credentials' => ['whatsapp' => ['phone_number_id' => '777', 'access_token' => '', 'app_secret' => 'new-secret']],
        ])->assertSessionHasNoErrors();
        $channel->refresh();
        $this->assertSame('Main WhatsApp', $channel->name);
        $this->assertSame(['phone_number_id' => '777', 'access_token' => 'tok-1', 'app_secret' => 'new-secret'], $channel->credentials);
        $this->assertTrue($channel->test_mode);

        $this->actingAs($owner)->put(route('settings.inbox.update', $channel), ['type' => 'email', 'name' => 'X'])->assertSessionHasErrors('type');

        $this->actingAs($owner)->delete(route('settings.inbox.destroy', $channel))->assertRedirect(route('settings.inbox.index'));
        $this->assertSame(0, InboxChannel::allWorkspaces()->count());
    }

    public function test_test_mode_channels_can_simulate_an_incoming_message(): void
    {
        [$owner, $workspace] = $this->inboxWorkspace();
        $member = $this->memberOf($workspace);
        $channel = InboxChannel::factory()->for($workspace)->create(['type' => 'sms']);

        $this->actingAs($member)->post(route('settings.inbox.simulate', $channel), ['from' => '0991112233', 'body' => 'Hi'])->assertForbidden();
        $this->actingAs($owner)->post(route('settings.inbox.simulate', $channel), ['from' => '12', 'body' => 'Hi'])->assertSessionHasErrors('from');

        $response = $this->actingAs($owner)->post(route('settings.inbox.simulate', $channel), ['from' => '0991112233', 'name' => 'Test Person', 'body' => 'Is this working?']);
        $conversation = InboxConversation::forWorkspace($workspace)->sole();
        $response->assertRedirect(route('inbox.show', $conversation));
        $this->assertSame('+265991112233', $conversation->handle);

        $channel->update(['test_mode' => false]);
        $this->actingAs($owner)->post(route('settings.inbox.simulate', $channel), ['from' => '0991112233', 'body' => 'Hi'])->assertForbidden();
    }
}
