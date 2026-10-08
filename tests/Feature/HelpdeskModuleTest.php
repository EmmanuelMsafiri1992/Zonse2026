<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class HelpdeskModuleTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function helpdeskWorkspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['helpdesk'], $owner);

        return [$owner, $workspace->fresh()];
    }

    public function test_ticket_routes_require_the_module_to_be_enabled(): void
    {
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('tickets.index'))->assertRedirect(route('settings.modules.index'));
    }

    public function test_a_ticket_is_numbered_and_can_be_linked_to_a_contact_or_a_walk_in(): void
    {
        [$owner, $workspace] = $this->helpdeskWorkspace();
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Rudo Client', 'kind' => 'person', 'company_name' => null, 'email' => 'rudo@example.com']);
        $member = $this->memberOf($workspace, 'member');

        $this->actingAs($owner)->post(route('tickets.store'), [
            'subject' => 'Invoice shows the wrong amount', 'body' => 'I was charged twice.', 'contact_id' => $contact->id,
            'channel' => 'email', 'priority' => 'high', 'assignee_id' => $member->id, 'category' => 'Billing',
        ])->assertRedirect(route('tickets.show', Ticket::query()->first()));

        $ticket = Ticket::query()->first();
        $this->assertSame('TKT-0001', $ticket->number);
        $this->assertSame('open', $ticket->status);
        $this->assertSame($member->id, $ticket->assignee_id);
        $this->assertSame($owner->id, $ticket->created_by);
        $this->assertSame('rudo@example.com', $ticket->requesterEmail());
        $this->assertNotNull($ticket->last_activity_at);

        $this->actingAs($owner)->post(route('tickets.store'), [
            'subject' => 'Walk-in asking about opening hours', 'requester_name' => 'Tendai Moyo', 'channel' => 'walk_in', 'priority' => 'low',
        ])->assertRedirect();

        $walkIn = Ticket::query()->where('subject', 'like', 'Walk-in%')->first();
        $this->assertSame('TKT-0002', $walkIn->number);
        $this->assertSame('Tendai Moyo', $walkIn->requesterName());

        $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('TKT-0001')->assertSee('Rudo Client')->assertSee('I was charged twice.')->assertSee($member->name)->assertSee('Billing');
    }

    public function test_a_ticket_needs_a_subject_and_a_requester(): void
    {
        [$owner] = $this->helpdeskWorkspace();

        $this->actingAs($owner)->from(route('tickets.create'))->post(route('tickets.store'), ['channel' => 'phone', 'priority' => 'normal'])
            ->assertRedirect(route('tickets.create'))->assertSessionHasErrors(['subject', 'requester_name']);

        $this->actingAs($owner)->post(route('tickets.store'), ['subject' => 'x', 'requester_name' => 'A', 'channel' => 'carrier_pigeon', 'priority' => 'meh'])
            ->assertSessionHasErrors(['channel', 'priority']);

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_replies_notes_and_customer_messages_drive_the_ticket_status(): void
    {
        [$owner, $workspace] = $this->helpdeskWorkspace();
        $ticket = Ticket::factory()->for($workspace)->create(['subject' => 'Delivery is late']);

        $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['kind' => 'reply', 'body' => 'It leaves the depot today.'])->assertRedirect();
        $ticket->refresh();
        $this->assertSame('pending', $ticket->status);
        $this->assertNotNull($ticket->first_replied_at);
        $this->assertDatabaseHas('comments', ['commentable_id' => $ticket->id, 'body' => 'It leaves the depot today.', 'is_internal' => 0, 'user_id' => $owner->id]);

        $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['kind' => 'note', 'body' => 'Driver says the van broke down.'])->assertRedirect();
        $this->assertSame('pending', $ticket->refresh()->status);
        $this->assertDatabaseHas('comments', ['commentable_id' => $ticket->id, 'body' => 'Driver says the van broke down.', 'is_internal' => 1]);

        $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['kind' => 'customer', 'body' => 'Still nothing by 5pm.'])->assertRedirect();
        $this->assertSame('open', $ticket->refresh()->status);
        $this->assertDatabaseHas('comments', ['commentable_id' => $ticket->id, 'body' => 'Still nothing by 5pm.', 'is_internal' => 0, 'user_id' => null]);

        $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['kind' => 'sms', 'body' => ''])->assertSessionHasErrors(['kind', 'body']);

        $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk()
            ->assertSee('It leaves the depot today.')->assertSee('Driver says the van broke down.')->assertSee('Not visible to customer')->assertSee('Still nothing by 5pm.');
    }

    public function test_triage_changes_status_priority_and_assignee(): void
    {
        [$owner, $workspace] = $this->helpdeskWorkspace();
        $member = $this->memberOf($workspace, 'member');
        $ticket = Ticket::factory()->for($workspace)->create();

        $this->actingAs($owner)->post(route('tickets.triage', $ticket), ['priority' => 'urgent', 'assignee_id' => $member->id])->assertRedirect();
        $ticket->refresh();
        $this->assertSame('urgent', $ticket->priority);
        $this->assertSame($member->id, $ticket->assignee_id);
        $this->assertSame('open', $ticket->status);

        $this->actingAs($owner)->post(route('tickets.triage', $ticket), ['status' => 'resolved'])->assertRedirect();
        $this->assertSame('resolved', $ticket->refresh()->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertNull($ticket->closed_at);

        $this->actingAs($owner)->post(route('tickets.triage', $ticket), ['status' => 'closed'])->assertRedirect();
        $this->assertNotNull($ticket->refresh()->closed_at);

        $this->actingAs($owner)->post(route('tickets.triage', $ticket), ['status' => 'open'])->assertRedirect();
        $ticket->refresh();
        $this->assertNull($ticket->resolved_at);
        $this->assertNull($ticket->closed_at);

        $this->actingAs($owner)->post(route('tickets.triage', $ticket), ['status' => 'bogus'])->assertSessionHasErrors('status');
    }

    public function test_the_queue_filters_and_orders_by_urgency(): void
    {
        [$owner, $workspace] = $this->helpdeskWorkspace();
        $member = $this->memberOf($workspace, 'member');

        Ticket::factory()->for($workspace)->create(['subject' => 'Normal old one', 'last_activity_at' => now()->subDays(3)]);
        Ticket::factory()->for($workspace)->priority('urgent')->create(['subject' => 'Urgent new one', 'last_activity_at' => now(), 'assignee_id' => $member->id]);
        Ticket::factory()->for($workspace)->pending()->create(['subject' => 'Waiting one', 'last_activity_at' => now()->subDay()]);
        Ticket::factory()->for($workspace)->resolved()->create(['subject' => 'Resolved one']);

        $this->actingAs($owner)->get(route('tickets.index'))->assertOk()
            ->assertSeeInOrder(['Urgent new one', 'Normal old one', 'Waiting one'])->assertDontSee('Resolved one');

        $this->actingAs($owner)->get(route('tickets.index', ['status' => 'pending']))->assertOk()->assertSee('Waiting one')->assertDontSee('Urgent new one');
        $this->actingAs($owner)->get(route('tickets.index', ['status' => 'all']))->assertOk()->assertSee('Resolved one');
        $this->actingAs($owner)->get(route('tickets.index', ['priority' => 'urgent']))->assertOk()->assertSee('Urgent new one')->assertDontSee('Normal old one');
        $this->actingAs($owner)->get(route('tickets.index', ['assignee' => 'unassigned']))->assertOk()->assertSee('Normal old one')->assertDontSee('Urgent new one');
        $this->actingAs($owner)->get(route('tickets.index', ['assignee' => $member->id]))->assertOk()->assertSee('Urgent new one')->assertDontSee('Normal old one');
        $this->actingAs($owner)->get(route('tickets.index', ['q' => 'waiting']))->assertOk()->assertSee('Waiting one')->assertDontSee('Urgent new one');
    }

    public function test_tickets_are_scoped_to_the_workspace_and_viewers_are_read_only(): void
    {
        [$owner, $workspace] = $this->helpdeskWorkspace();
        [$otherOwner, $otherWorkspace] = $this->helpdeskWorkspace();
        $viewer = $this->memberOf($workspace, 'viewer');

        $mine = Ticket::factory()->for($workspace)->create(['subject' => 'Private ticket']);
        $assignedToViewer = Ticket::factory()->for($workspace)->create(['subject' => 'Viewer ticket', 'assignee_id' => $viewer->id]);
        $theirs = Ticket::factory()->for($otherWorkspace)->create(['subject' => 'Someone elses ticket']);

        $this->assertSame('TKT-0001', $mine->number);
        $this->assertSame('TKT-0001', $theirs->number);

        $this->actingAs($otherOwner)->get(route('tickets.index'))->assertOk()->assertSee('Someone elses ticket')->assertDontSee('Private ticket');
        $this->actingAs($otherOwner)->get(route('tickets.show', $mine))->assertNotFound();
        $this->actingAs($otherOwner)->post(route('tickets.triage', $mine), ['status' => 'closed'])->assertNotFound();

        $this->actingAs($viewer)->get(route('tickets.index'))->assertOk()->assertSee('Private ticket');
        $this->actingAs($viewer)->get(route('tickets.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('tickets.replies.store', $mine), ['kind' => 'note', 'body' => 'Nope.'])->assertForbidden();
        // A viewer may still work a ticket that is assigned to them.
        $this->actingAs($viewer)->post(route('tickets.replies.store', $assignedToViewer), ['kind' => 'reply', 'body' => 'On it.'])->assertRedirect();
        $this->assertSame('pending', $assignedToViewer->refresh()->status);
        $this->actingAs($viewer)->delete(route('tickets.destroy', $assignedToViewer))->assertForbidden();

        $this->actingAs($owner)->delete(route('tickets.destroy', $mine))->assertRedirect(route('tickets.index'));
        $this->assertSoftDeleted('tickets', ['id' => $mine->id]);
    }

    public function test_search_widget_contact_page_and_module_sync_include_tickets(): void
    {
        [$owner, $workspace] = $this->helpdeskWorkspace();
        $workspace->enableModules(['contacts'], $owner);
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Highlands Mining', 'kind' => 'company', 'company_name' => 'Highlands Mining']);
        $ticket = Ticket::factory()->for($workspace)->priority('urgent')->create(['subject' => 'Pump stopped working', 'contact_id' => $contact->id]);

        Sequence::configure('ticket', 'HELP-', 100, $workspace->id);
        $renumbered = Ticket::factory()->for($workspace)->create(['subject' => 'Second one']);
        $this->assertSame('HELP-0100', $renumbered->number);

        $this->actingAs($owner)->get(route('search', ['q' => 'pump stopped']))->assertOk()->assertSee('Pump stopped working');
        $this->actingAs($owner)->get(route('search', ['q' => 'highlands']))->assertOk()->assertSee('Pump stopped working');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Support queue')->assertSee('Pump stopped working');
        $this->actingAs($owner)->get(route('contacts.show', $contact))->assertOk()->assertSee(route('tickets.index', ['contact' => $contact->id]), false);
        $this->actingAs($owner)->get(route('tickets.index', ['contact' => $contact->id]))->assertOk()->assertSee('Pump stopped working')->assertDontSee('Second one');
        $this->actingAs($owner)->get(route('tickets.create', ['contact' => $contact->id]))->assertOk()->assertSee('Highlands Mining');

        $this->artisan('zonseo:sync-modules')->assertSuccessful();
        $this->assertTrue(Module::query()->where('key', 'helpdesk')->first()->is_installed);

        $this->assertDatabaseHas('activity_log', ['subject_type' => Ticket::class, 'subject_id' => $ticket->id, 'event' => 'created']);
    }
}
