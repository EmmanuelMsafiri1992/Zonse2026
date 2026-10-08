<?php

namespace Tests\Feature;

use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Notifications\SignatureCompleted;
use App\Notifications\SignatureRequested;
use App\Notifications\WorkspaceAlert;
use App\Support\Signatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Invoicing\Models\Quote;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Sending a PDF for signature, signing it from the emailed link, and the certificate at the end. */
class SignaturesTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF";

    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Storage::fake('local');
        Notification::fake();
        Queue::fake();
    }

    /** @return array{0: User, 1: Workspace, 2: User} */
    protected function workspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        return [$owner, $workspace, $this->memberOf($workspace)];
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Lease agreement',
            'message' => 'Please sign by Friday.',
            'signing_order' => 'parallel',
            'expires_in_days' => 14,
            'document' => UploadedFile::fake()->createWithContent('lease.pdf', self::PDF),
            'signers' => [
                ['name' => 'Tendai Moyo', 'email' => 'tendai@example.com'],
                ['name' => 'Rudo Banda', 'email' => 'Rudo@Example.com'],
            ],
        ];
    }

    protected function send(User $user, array $overrides = []): SignatureRequest
    {
        $this->actingAs($user)->post(route('signatures.store'), $this->payload($overrides))->assertSessionHasNoErrors();
        auth()->guard('web')->logout();

        return SignatureRequest::allWorkspaces()->latest('id')->firstOrFail();
    }

    protected function signer(SignatureRequest $request, int $position): SignatureSigner
    {
        return SignatureSigner::allWorkspaces()->where('signature_request_id', $request->id)->where('position', $position)->firstOrFail();
    }

    /** @return array<string, string> */
    protected function signature(string $type = 'type', ?string $value = null): array
    {
        return [
            'signature_type' => $type,
            'signature' => $value ?? ($type === 'draw' ? 'data:image/png;base64,'.self::PNG : 'Tendai Moyo'),
            'signed_name' => 'Tendai Moyo',
            'agree' => '1',
        ];
    }

    public function test_a_member_sends_a_pdf_and_each_signer_is_emailed_a_private_link(): void
    {
        [$owner, $workspace, $member] = $this->workspace();

        $this->actingAs($member)->get(route('signatures.create'))->assertOk()->assertSee('Send for signing');
        $response = $this->post(route('signatures.store'), $this->payload());

        $request = SignatureRequest::query()->firstOrFail();
        $response->assertRedirect(route('signatures.show', $request));
        $this->assertSame('pending', $request->status);
        $this->assertSame(hash('sha256', self::PDF), $request->document_hash);
        $this->assertSame('lease.pdf', $request->document_name);
        $this->assertTrue($request->expires_at->isSameDay(now()->addDays(14)));
        Storage::disk('local')->assertExists($request->document_path);
        $this->assertStringStartsWith('signatures/'.$workspace->id.'/', $request->document_path);

        $signers = $request->signers;
        $this->assertSame(['tendai@example.com', 'rudo@example.com'], $signers->pluck('email')->all());
        $this->assertSame(48, strlen($signers[0]->token));
        $this->assertNotNull($signers[0]->notified_at);
        foreach ($signers as $signer) {
            Notification::assertSentOnDemand(SignatureRequested::class, fn ($notification, $channels, $notifiable) => array_key_exists($signer->email, $notifiable->routes['mail'])
                && str_contains($notification->toMail($notifiable)->actionUrl, $signer->token));
        }
        $this->assertDatabaseHas('signature_events', ['signature_request_id' => $request->id, 'event' => 'created']);
        $this->assertDatabaseHas('activity_log', ['event' => 'signature-requested']);

        $this->get(route('signatures.index'))->assertOk()->assertSee('Lease agreement')->assertSee('0 of 2 signed');
        $this->get(route('signatures.show', $request))->assertOk()->assertSee('Tendai Moyo')->assertSee($request->document_hash)
            ->assertSee('File unchanged since it was sent');
        $this->assertSame(self::PDF, $this->get(route('signatures.document', $request))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent());
    }

    public function test_the_request_is_validated_and_viewers_cannot_send(): void
    {
        [$owner, $workspace] = $this->workspace();

        $this->actingAs($owner)->post(route('signatures.store'), $this->payload([
            'document' => UploadedFile::fake()->create('notes.txt', 2, 'text/plain'),
            'signers' => [['name' => 'A', 'email' => 'same@example.com'], ['name' => 'B', 'email' => 'SAME@example.com']],
            'signing_order' => 'random',
        ]))->assertSessionHasErrors(['document', 'signers.1.email', 'signing_order']);

        $this->post(route('signatures.store'), $this->payload([
            'signers' => collect(range(1, Signatures::MAX_SIGNERS + 1))->map(fn ($i) => ['name' => 'P'.$i, 'email' => 'p'.$i.'@example.com'])->all(),
        ]))->assertSessionHasErrors('signers');

        $this->post(route('signatures.store'), $this->payload(['document' => UploadedFile::fake()->createWithContent('fake.pdf', 'not really a pdf')]))
            ->assertSessionHasErrors('document');
        $this->assertSame(0, SignatureRequest::query()->count());

        $viewer = $this->memberOf($workspace, 'viewer');
        $this->actingAs($viewer)->get(route('signatures.index'))->assertOk()->assertDontSee(route('signatures.create'));
        $this->get(route('signatures.create'))->assertForbidden();
        $this->post(route('signatures.store'), $this->payload())->assertForbidden();
    }

    public function test_everyone_signs_and_a_certificate_is_issued(): void
    {
        [$owner, $workspace] = $this->workspace();
        WebhookEndpoint::factory()->create(['workspace_id' => $workspace->id, 'events' => ['signature.completed']]);
        $request = $this->send($owner);
        $first = $this->signer($request, 1);
        $second = $this->signer($request, 2);

        $this->get(route('signing.show', $first->token))->assertOk()->assertSee('Lease agreement')->assertSee('Your signature')
            ->assertSee(route('signing.document', $first->token))->assertSee('Please sign by Friday.');
        $this->assertSame('viewed', $first->fresh()->status);
        $this->assertSame(self::PDF, $this->get(route('signing.document', $first->token))->assertOk()->getContent());
        $this->get(route('signing.certificate', $first->token))->assertNotFound();

        $this->post(route('signing.sign', $first->token), $this->signature('draw'))
            ->assertRedirect(route('signing.show', $first->token))->assertSessionHas('flash.message', 'Thank you, your signature has been recorded.');
        $first->refresh();
        $this->assertSame('signed', $first->status);
        $this->assertSame(self::PNG, $first->signature_data);
        $this->assertSame('127.0.0.1', $first->ip_address);
        $this->assertSame('pending', $request->fresh()->status);
        Notification::assertSentTo($owner, WorkspaceAlert::class, fn (WorkspaceAlert $alert) => $alert->kind === 'signatures' && str_contains($alert->title, 'Tendai Moyo signed'));

        $this->post(route('signing.sign', $first->token), $this->signature())->assertSessionHasErrors('signature');

        $this->post(route('signing.sign', $second->token), ['signed_name' => 'Rudo Banda'] + $this->signature('type', 'Rudo Banda'));
        $request->refresh();
        $this->assertSame('completed', $request->status);
        $this->assertNotNull($request->completed_at);
        Storage::disk('local')->assertExists($request->certificate_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($request->certificate_path));
        $this->assertStringStartsWith('%PDF', $this->get(route('signing.certificate', $second->token))->assertOk()->getContent());
        $this->get(route('signing.show', $second->token))->assertOk()->assertSee('Signed by everyone')->assertDontSee('Your signature');

        Notification::assertSentOnDemandTimes(SignatureCompleted::class, 2);
        $this->assertDatabaseHas('webhook_deliveries', ['workspace_id' => $workspace->id, 'event' => 'signature.completed']);
        $this->assertDatabaseHas('activity_log', ['event' => 'signature-completed']);
        $this->assertSame(['created', 'sent', 'sent', 'viewed', 'signed', 'signed', 'completed'], $request->events()->pluck('event')->all());

        $this->actingAs($owner)->get(route('signatures.show', $request))->assertOk()->assertSee('as “Rudo Banda”', false)->assertSee('Certificate');
        $this->assertStringStartsWith('%PDF', $this->get(route('signatures.certificate', $request))->assertOk()->getContent());
    }

    public function test_in_sequential_order_each_signer_waits_for_the_one_before(): void
    {
        [$owner] = $this->workspace();
        $request = $this->send($owner, ['signing_order' => 'sequential']);
        $first = $this->signer($request, 1);
        $second = $this->signer($request, 2);

        Notification::assertSentOnDemandTimes(SignatureRequested::class, 1);
        $this->assertNull($second->notified_at);
        $this->get(route('signing.show', $second->token))->assertOk()->assertSee('It is not your turn yet')->assertSee('Tendai Moyo signs first')->assertDontSee('Your signature');
        $this->post(route('signing.sign', $second->token), $this->signature())->assertSessionHasErrors('signature');
        $this->assertNull($second->fresh()->signed_at);

        $this->post(route('signing.sign', $first->token), $this->signature())->assertSessionHasNoErrors();
        Notification::assertSentOnDemandTimes(SignatureRequested::class, 2);
        $this->assertNotNull($second->fresh()->notified_at);
        $this->post(route('signing.sign', $second->token), $this->signature())->assertSessionHasNoErrors();
        $this->assertSame('completed', $request->fresh()->status);
    }

    public function test_a_signer_can_decline_and_the_request_closes(): void
    {
        [$owner, $workspace] = $this->workspace();
        WebhookEndpoint::factory()->create(['workspace_id' => $workspace->id, 'events' => ['signature.declined']]);
        $request = $this->send($owner);
        $first = $this->signer($request, 1);
        $second = $this->signer($request, 2);

        $this->post(route('signing.decline', $first->token), ['reason' => 'The rent is wrong.'])->assertRedirect(route('signing.show', $first->token));

        $this->assertSame('declined', $request->fresh()->status);
        $this->assertSame('The rent is wrong.', $first->fresh()->decline_reason);
        Notification::assertSentTo($owner, WorkspaceAlert::class, fn (WorkspaceAlert $alert) => str_contains($alert->title, 'declined') && $alert->body === 'The rent is wrong.');
        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'signature.declined']);
        $this->get(route('signing.show', $second->token))->assertOk()->assertSee('can no longer be signed');
        $this->post(route('signing.sign', $second->token), $this->signature())->assertSessionHasErrors('signature');
    }

    public function test_signatures_are_checked_before_they_are_kept(): void
    {
        [$owner] = $this->workspace();
        $request = $this->send($owner);
        $signer = $this->signer($request, 1);

        $this->post(route('signing.sign', $signer->token), ['agree' => null] + $this->signature())->assertSessionHasErrors('agree');
        $this->post(route('signing.sign', $signer->token), $this->signature('draw', 'data:image/png;base64,'.base64_encode('<svg></svg>')))->assertSessionHasErrors('signature');
        $this->post(route('signing.sign', $signer->token), $this->signature('draw', 'data:image/svg+xml;base64,'.self::PNG))->assertSessionHasErrors('signature');
        $this->post(route('signing.sign', $signer->token), $this->signature('type', str_repeat('x', 121)))->assertSessionHasErrors('signature');
        $this->assertSame('pending', $signer->fresh()->status);
        $this->assertNull($signer->fresh()->signed_at);
    }

    public function test_a_changed_file_cannot_be_signed(): void
    {
        [$owner] = $this->workspace();
        $request = $this->send($owner);
        Storage::disk('local')->put($request->document_path, self::PDF.'% tampered');

        $this->post(route('signing.sign', $this->signer($request, 1)->token), $this->signature())
            ->assertSessionHasErrors(['signature' => 'The document has changed since it was sent, so it cannot be signed. Ask the sender for a new link.']);
        $this->actingAs($owner)->get(route('signatures.show', $request))->assertSee('no longer matches its fingerprint');
    }

    public function test_a_quote_sent_for_signing_is_accepted_once_signed(): void
    {
        [$owner, $workspace] = $this->workspace();
        $workspace->enableModules(['invoicing', 'quotes'], $owner);
        $quote = Quote::factory()->for($workspace)->withLines()->create(['currency_code' => 'USD', 'status' => 'sent'])->fresh();

        $this->actingAs($owner)->get(route('quotes.show', $quote))->assertSee(route('signatures.create', ['quote' => $quote->id]));
        $this->get(route('signatures.create', ['quote' => $quote->id]))->assertOk()->assertSee('Quote '.$quote->number);
        $this->post(route('signatures.store'), [
            'title' => 'Quote '.$quote->number, 'signing_order' => 'parallel', 'quote_id' => $quote->id,
            'signers' => [['name' => 'Tendai Moyo', 'email' => 'tendai@example.com']],
        ])->assertSessionHasNoErrors();
        auth()->guard('web')->logout();

        $request = SignatureRequest::allWorkspaces()->firstOrFail();
        $this->assertTrue($request->signable->is($quote));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($request->document_path));
        $this->assertNull($request->expires_at);

        $this->post(route('signing.sign', $this->signer($request, 1)->token), $this->signature())->assertSessionHasNoErrors();
        $this->assertSame('accepted', $quote->fresh()->status);
        $this->assertDatabaseHas('signature_events', ['signature_request_id' => $request->id, 'event' => 'quote-accepted']);
    }

    public function test_the_sender_or_an_admin_can_remind_and_cancel(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        $request = $this->send($member);
        $colleague = $this->memberOf($workspace);

        $this->actingAs($colleague)->post(route('signatures.remind', $request))->assertForbidden();
        $this->post(route('signatures.cancel', $request))->assertForbidden();

        $this->actingAs($member)->post(route('signatures.remind', $request))->assertSessionHas('flash.message', 'Reminder sent to 2 signers.');
        Notification::assertSentOnDemand(SignatureRequested::class, fn (SignatureRequested $notification) => $notification->reminder);

        $this->actingAs($owner)->post(route('signatures.cancel', $request))->assertSessionHas('flash.type', 'success');
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['event' => 'signature-cancelled']);
        auth()->guard('web')->logout();
        $this->get(route('signing.show', $this->signer($request, 1)->token))->assertOk()->assertSee('can no longer be signed');
        $this->post(route('signing.sign', $this->signer($request, 1)->token), $this->signature())->assertSessionHasErrors('signature');
        $this->actingAs($owner)->post(route('signatures.cancel', $request))->assertForbidden();
    }

    public function test_requests_past_their_deadline_expire(): void
    {
        [$owner] = $this->workspace();
        $request = $this->send($owner);
        $this->travel(15)->days();

        $this->post(route('signing.sign', $this->signer($request, 1)->token), $this->signature())->assertSessionHasErrors('signature');
        $this->assertSame(1, Signatures::expireOverdue());
        $this->assertSame('expired', $request->fresh()->status);
        $this->assertDatabaseHas('signature_events', ['signature_request_id' => $request->id, 'event' => 'expired']);
        $this->assertSame(0, Signatures::expireOverdue());
    }

    public function test_requests_and_links_stay_private(): void
    {
        [$owner] = $this->workspace();
        $request = $this->send($owner);
        [$otherOwner] = $this->ownerWithWorkspace();

        $this->actingAs($otherOwner)->get(route('signatures.show', $request))->assertNotFound();
        $this->get(route('signatures.document', $request))->assertNotFound();
        $this->get(route('signatures.index'))->assertDontSee('Lease agreement');
        auth()->guard('web')->logout();

        $this->get(route('signing.show', str_repeat('a', 48)))->assertNotFound();
        $this->get(route('signing.show', 'short'))->assertNotFound();
        $this->get(route('signatures.show', $request))->assertRedirect(route('login'));
        $this->get(route('signing.show', $this->signer($request, 1)->token))->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertSee('noindex', false);
    }
}
