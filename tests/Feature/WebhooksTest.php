<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Support\Webhooks;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Tasks\Models\Task;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Outgoing webhooks: what fires them, signing, test mode, safety checks and switching off failing endpoints. */
class WebhooksTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Webhooks::resolveHostsUsing(fn (string $host) => $host === 'internal.example.com' ? ['10.0.0.5'] : ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        Webhooks::resolveHostsUsing(null);
        parent::tearDown();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function workspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['contacts', 'tasks', 'invoicing'], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /** @param list<string> $events */
    protected function endpoint(Workspace $workspace, array $events = ['contact.created', 'task.created', 'task.completed']): WebhookEndpoint
    {
        return WebhookEndpoint::factory()->create(['workspace_id' => $workspace->id, 'url' => 'https://hooks.example.com/zonseo', 'events' => $events]);
    }

    public function test_creating_a_contact_posts_a_signed_payload(): void
    {
        [$owner, $workspace] = $this->workspace();
        $endpoint = $this->endpoint($workspace);
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true])]);

        $this->actingAs($owner);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Rudo Banda']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($endpoint, $contact, $workspace) {
            $body = $request->body();
            preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $request->header('X-Zonseo-Signature')[0], $signature);
            $payload = json_decode($body, true);

            return $request->url() === 'https://hooks.example.com/zonseo'
                && $request->header('X-Zonseo-Event')[0] === 'contact.created'
                && hash_equals(hash_hmac('sha256', $signature[1].'.'.$body, $endpoint->secret), $signature[2])
                && $payload['event'] === 'contact.created' && $payload['workspace_id'] === $workspace->id
                && $payload['data']['id'] === $contact->id && $payload['data']['name'] === 'Rudo Banda';
        });

        $delivery = WebhookDelivery::sole();
        $this->assertSame('succeeded', $delivery->status);
        $this->assertSame(200, $delivery->response_status);
        $this->assertNotNull($endpoint->fresh()->last_delivered_at);
    }

    public function test_only_chosen_events_are_sent_and_task_completion_has_its_own_event(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->endpoint($workspace, ['task.completed']);
        Http::fake(['hooks.example.com/*' => Http::response()]);
        $this->actingAs($owner);

        $task = Task::factory()->create(['workspace_id' => $workspace->id, 'status' => 'todo']);
        Contact::factory()->create(['workspace_id' => $workspace->id]);
        Http::assertNothingSent();

        $task->update(['status' => 'done']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->header('X-Zonseo-Event')[0] === 'task.completed');
    }

    public function test_invoice_paid_fires_only_when_the_status_turns_paid(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->endpoint($workspace, ['invoice.paid']);
        Http::fake(['hooks.example.com/*' => Http::response()]);
        $this->actingAs($owner);

        $invoice = Invoice::factory()->create(['workspace_id' => $workspace->id, 'status' => 'sent']);
        $invoice->update(['notes' => 'Thanks']);
        Http::assertNothingSent();

        $invoice->update(['status' => 'paid']);
        Http::assertSentCount(1);
    }

    public function test_events_never_cross_workspaces(): void
    {
        [$owner, $workspace] = $this->workspace();
        [, $otherWorkspace] = $this->ownerWithWorkspace();
        $this->endpoint($otherWorkspace);
        Http::fake();

        $this->actingAs($owner);
        app(WorkspaceContext::class)->set($workspace);
        Contact::factory()->create(['workspace_id' => $workspace->id]);

        Http::assertNothingSent();
        $this->assertSame(0, WebhookDelivery::allWorkspaces()->count());
    }

    public function test_unsafe_addresses_are_refused_when_saving(): void
    {
        [$owner] = $this->workspace();
        $this->actingAs($owner);

        foreach (['http://hooks.example.com/x', 'https://127.0.0.1/x', 'https://internal.example.com/x', 'https://user:pass@hooks.example.com/x', 'ftp://hooks.example.com'] as $url) {
            $this->post(route('settings.webhooks.store'), ['url' => $url, 'events' => ['contact.created']])->assertSessionHasErrors('url');
        }
        $this->post(route('settings.webhooks.store'), ['url' => 'https://hooks.example.com/x', 'events' => []])->assertSessionHasErrors('events');
        $this->post(route('settings.webhooks.store'), ['url' => 'https://hooks.example.com/x', 'events' => ['contact.deleted-forever']])->assertSessionHasErrors('events.0');

        $this->assertSame(0, WebhookEndpoint::allWorkspaces()->count());

        $this->post(route('settings.webhooks.store'), ['url' => 'https://hooks.example.com/x', 'description' => 'Accounts', 'events' => ['contact.created', 'contact.created']])
            ->assertRedirect(route('settings.webhooks.show', WebhookEndpoint::sole()));
        $this->assertSame(['contact.created'], WebhookEndpoint::sole()->events);
        $this->assertStringStartsWith('whsec_', WebhookEndpoint::sole()->secret);
    }

    public function test_a_send_test_ping_is_recorded_whether_it_works_or_not(): void
    {
        [$owner, $workspace] = $this->workspace();
        $endpoint = $this->endpoint($workspace);
        $this->actingAs($owner);

        Http::fake(['hooks.example.com/*' => Http::sequence()->push('ok', 204)->push('Nope', 500)]);
        $this->post(route('settings.webhooks.test', $endpoint))->assertSessionHas('flash.type', 'success');

        $this->post(route('settings.webhooks.test', $endpoint))->assertSessionHas('flash.type', 'danger');

        $deliveries = WebhookDelivery::orderBy('id')->get();
        $this->assertSame(['ping', 'ping'], $deliveries->pluck('event')->all());
        $this->assertSame(['succeeded', 'failed'], $deliveries->pluck('status')->all());
        $this->assertSame('Nope', $deliveries->last()->response_body);
        $this->assertSame(0, $endpoint->fresh()->failure_count, 'Test pings do not count against the endpoint.');

        $this->get(route('settings.webhooks.show', $endpoint))->assertOk()->assertSee('ping')->assertSee('Nope');
    }

    public function test_a_past_delivery_can_be_resent(): void
    {
        [$owner, $workspace] = $this->workspace();
        $endpoint = $this->endpoint($workspace);
        $this->actingAs($owner);
        Http::fake(['hooks.example.com/*' => Http::sequence()->push('down', 503)->push('ok', 200)]);

        Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Farai']);
        $failed = WebhookDelivery::sole();
        $this->assertSame('failed', $failed->status);

        $this->post(route('settings.webhooks.redeliver', [$endpoint, $failed]))->assertSessionHas('flash.type', 'success');

        $resent = WebhookDelivery::latest('id')->first();
        $this->assertNotSame($failed->id, $resent->id);
        $this->assertSame('succeeded', $resent->status);
        $this->assertSame('Farai', $resent->payload['data']['name']);

        $other = $this->endpoint($workspace);
        $this->post(route('settings.webhooks.redeliver', [$other, $failed]))->assertNotFound();
    }

    public function test_an_endpoint_that_keeps_failing_is_switched_off_and_admins_are_told(): void
    {
        [$owner, $workspace] = $this->workspace();
        $endpoint = $this->endpoint($workspace);
        $endpoint->forceFill(['failure_count' => WebhookEndpoint::DISABLE_AFTER_FAILURES - 1])->save();
        Http::fake(['hooks.example.com/*' => Http::response('', 500)]);

        $this->actingAs($owner);
        Contact::factory()->create(['workspace_id' => $workspace->id]);

        $endpoint->refresh();
        $this->assertFalse($endpoint->is_active);
        $this->assertNotNull($endpoint->disabled_at);
        $this->assertTrue($owner->notifications()->get()->contains(fn ($notification) => str_contains($notification->data['title'] ?? '', 'Webhook switched off')));

        Http::fake();
        Contact::factory()->create(['workspace_id' => $workspace->id]);
        Http::assertNothingSent();

        $this->put(route('settings.webhooks.update', $endpoint), ['url' => $endpoint->url, 'events' => $endpoint->events, 'is_active' => '1'])->assertRedirect();
        $endpoint->refresh();
        $this->assertTrue($endpoint->is_active);
        $this->assertSame(0, $endpoint->failure_count);
        $this->assertNull($endpoint->disabled_at);
    }

    public function test_a_new_secret_replaces_the_old_one(): void
    {
        [$owner, $workspace] = $this->workspace();
        $endpoint = $this->endpoint($workspace);
        $oldSecret = $endpoint->secret;

        $this->actingAs($owner)->post(route('settings.webhooks.secret', $endpoint))->assertRedirect();

        $this->assertNotSame($oldSecret, $endpoint->fresh()->secret);
        $this->assertStringNotContainsString($endpoint->fresh()->secret, json_encode($endpoint->fresh()->toArray()));
    }

    public function test_webhooks_of_another_workspace_cannot_be_opened_and_members_are_kept_out(): void
    {
        [$owner, $workspace] = $this->workspace();
        [, $otherWorkspace] = $this->ownerWithWorkspace();
        $foreign = $this->endpoint($otherWorkspace);
        $member = $this->memberOf($workspace);

        $this->actingAs($owner)->get(route('settings.webhooks.show', $foreign))->assertNotFound();
        $this->actingAs($owner)->delete(route('settings.webhooks.destroy', $foreign))->assertNotFound();
        $this->actingAs($member)->get(route('settings.webhooks.show', $this->endpoint($workspace)))->assertForbidden();

        $this->assertSame(2, WebhookEndpoint::allWorkspaces()->count());
    }
}
