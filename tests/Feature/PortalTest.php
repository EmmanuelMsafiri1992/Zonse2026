<?php

namespace Tests\Feature;

use App\Models\PortalAccess;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PortalSignInLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Modules\Appointments\Models\Appointment;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Modules\Invoicing\Models\Invoice;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Notification::fake();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function workspaceWithApps(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['contacts', 'invoicing', 'appointments', 'helpdesk'], $owner);

        return [$owner, $workspace->fresh()];
    }

    protected function accessFor(Workspace $workspace, array $attributes = []): PortalAccess
    {
        $contact = Contact::factory()->for($workspace)->customer()->create(['email' => $attributes['email'] ?? fake()->unique()->safeEmail()]);

        return PortalAccess::factory()->create(['workspace_id' => $workspace->id, 'contact_id' => $contact->id, 'email' => $contact->email] + $attributes);
    }

    /** Signs the test client in to the portal as the given access. */
    protected function asPortal(PortalAccess $access): static
    {
        auth()->logout();

        return $this->withSession(['portal.'.$access->workspace_id => $access->id]);
    }

    protected function sentLinkTo(string $email): ?string
    {
        $url = null;
        Notification::assertSentOnDemand(PortalSignInLink::class, function (PortalSignInLink $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$url) {
            if ($notifiable->routes['mail'] === $email) {
                $url = $notification->url();

                return true;
            }

            return false;
        });

        return $url;
    }

    public function test_staff_invite_a_contact_who_signs_in_with_the_emailed_link(): void
    {
        [$owner, $workspace] = $this->workspaceWithApps();
        $contact = Contact::factory()->for($workspace)->customer()->create(['kind' => 'person', 'name' => 'Tendai Moyo', 'email' => 'Tendai@Example.com']);

        $this->actingAs($owner)->get(route('settings.portal.index'))->assertOk()->assertSee('No one has portal access yet');
        $this->actingAs($owner)->post(route('settings.portal.invite'), ['contact_id' => $contact->id, 'audience' => 'patient'])
            ->assertSessionHas('flash.type', 'success');

        $access = PortalAccess::forWorkspace($workspace)->firstOrFail();
        $this->assertSame('tendai@example.com', $access->email);
        $this->assertSame('Patient', $access->audienceLabel());
        $url = $this->sentLinkTo('tendai@example.com');
        $this->assertStringContainsString('/portal/'.$workspace->slug.'/enter/', $url);

        auth()->logout();
        $this->get($url)->assertRedirect(route('portal.home', $workspace));
        $this->get(route('portal.home', $workspace))->assertOk()->assertSee('Hello, Tendai Moyo')->assertSee('Support requests');
        $this->assertNotNull($access->fresh()->last_login_at);

        // The link only works once.
        $this->post(route('portal.logout', $workspace))->assertRedirect(route('portal.login', $workspace));
        $this->get($url)->assertRedirect(route('portal.login', $workspace))->assertSessionHas('flash.type', 'danger');
        $this->get(route('portal.home', $workspace))->assertRedirect(route('portal.login', $workspace));
    }

    public function test_the_sign_in_form_never_reveals_who_has_access(): void
    {
        [, $workspace] = $this->workspaceWithApps();
        $access = $this->accessFor($workspace, ['email' => 'rudo@example.com']);
        $this->accessFor($workspace, ['email' => 'off@example.com', 'status' => 'disabled']);

        $this->get(route('portal.login', $workspace))->assertOk()->assertSee('Email me a sign-in link')->assertSee($workspace->name);

        foreach (['stranger@example.com', 'off@example.com', ' Rudo@Example.com '] as $email) {
            $this->post(route('portal.login.send', $workspace), ['email' => trim($email)])
                ->assertRedirect()->assertSessionHas('flash.message', 'If that email has portal access, a sign-in link is on its way. It expires in 30 minutes.');
        }

        Notification::assertSentOnDemandTimes(PortalSignInLink::class, 1);
        $this->assertNotNull($this->sentLinkTo('rudo@example.com'));
        $this->assertNotNull($access->fresh()->login_token_expires_at);
    }

    public function test_links_expire_and_cannot_be_used_on_another_workspace(): void
    {
        [, $workspace] = $this->workspaceWithApps();
        [, $other] = $this->workspaceWithApps();
        $access = $this->accessFor($workspace);

        $token = $access->issueLoginToken();
        $this->get(route('portal.enter', [$other, $token]))->assertRedirect(route('portal.login', $other));

        $this->travel(PortalAccess::LINK_MINUTES + 1)->minutes();
        $this->get(route('portal.enter', [$workspace, $token]))->assertRedirect(route('portal.login', $workspace));
        $this->assertNull($access->fresh()->last_login_at);

        // A session for one workspace is no key to another workspace's portal.
        $this->asPortal($access)->get(route('portal.home', $other))->assertRedirect(route('portal.login', $other));
    }

    public function test_turning_access_off_ends_the_portal_session(): void
    {
        [$owner, $workspace] = $this->workspaceWithApps();
        $access = $this->accessFor($workspace);
        $token = $access->issueLoginToken();

        $this->actingAs($owner)->post(route('settings.portal.toggle', $access))->assertSessionHas('flash.type', 'success');
        $this->assertSame('disabled', $access->fresh()->status);
        $this->assertNull($access->fresh()->login_token_hash);

        $this->asPortal($access)->get(route('portal.home', $workspace))->assertRedirect(route('portal.login', $workspace));
        $this->get(route('portal.enter', [$workspace, $token]))->assertRedirect(route('portal.login', $workspace));

        $this->actingAs($owner)->post(route('settings.portal.resend', $access))->assertStatus(422);
        $this->actingAs($owner)->post(route('settings.portal.toggle', $access));
        $this->actingAs($owner)->post(route('settings.portal.resend', $access))->assertSessionHas('flash.type', 'success');
        $this->assertNotNull($this->sentLinkTo($access->email));

        $this->actingAs($owner)->delete(route('settings.portal.destroy', $access))->assertSessionHas('flash.type', 'success');
        $this->assertFalse(PortalAccess::forWorkspace($workspace)->exists());
    }

    public function test_contacts_only_see_their_own_invoices(): void
    {
        [, $workspace] = $this->workspaceWithApps();
        $access = $this->accessFor($workspace);
        $neighbour = $this->accessFor($workspace);

        $mine = Invoice::factory()->for($workspace)->withLines()->sent()->create(['contact_id' => $access->contact_id]);
        $draft = Invoice::factory()->for($workspace)->withLines()->create(['contact_id' => $access->contact_id]);
        $theirs = Invoice::factory()->for($workspace)->withLines()->sent()->create(['contact_id' => $neighbour->contact_id]);

        $this->asPortal($access)->get(route('portal.invoices', $workspace))->assertOk()
            ->assertSee($mine->number)
            ->assertSee($mine->publicUrl())
            ->assertSee('View &amp; pay', false)
            ->assertDontSee($draft->number)
            ->assertDontSee($theirs->number);

        $this->asPortal($access)->get(route('portal.home', $workspace))->assertOk()->assertSee('unpaid');
    }

    public function test_contacts_open_and_follow_up_support_requests(): void
    {
        [$owner, $workspace] = $this->workspaceWithApps();
        $access = $this->accessFor($workspace);
        $neighbour = $this->accessFor($workspace);
        $theirs = Ticket::factory()->for($workspace)->create(['contact_id' => $neighbour->contact_id, 'subject' => 'Neighbour problem']);

        $this->asPortal($access)->post(route('portal.requests.store', $workspace), ['subject' => 'Leaking tap', 'body' => 'The kitchen tap drips.'])
            ->assertSessionHas('flash.type', 'success');

        $ticket = Ticket::forWorkspace($workspace)->where('subject', 'Leaking tap')->firstOrFail();
        $this->assertSame($access->contact_id, $ticket->contact_id);
        $this->assertSame('web', $ticket->channel);
        $this->assertSame($access->email, $ticket->requester_email);

        $ticket->reply('A plumber comes on Monday.', $owner);
        $ticket->note('Internal: check warranty first', $owner);

        $this->asPortal($access)->get(route('portal.requests.show', [$workspace, $ticket->id]))->assertOk()
            ->assertSee('A plumber comes on Monday.')
            ->assertDontSee('Internal: check warranty first');

        $this->asPortal($access)->post(route('portal.requests.reply', [$workspace, $ticket->id]), ['body' => 'Thank you!'])
            ->assertSessionHas('flash.type', 'success');
        $this->assertTrue($ticket->comments()->where('body', 'Thank you!')->where('is_internal', false)->exists());

        $this->asPortal($access)->get(route('portal.requests', $workspace))->assertOk()->assertSee('Leaking tap')->assertDontSee('Neighbour problem');
        $this->asPortal($access)->get(route('portal.requests.show', [$workspace, $theirs->id]))->assertNotFound();
        $this->asPortal($access)->post(route('portal.requests.reply', [$workspace, $theirs->id]), ['body' => 'Hi'])->assertNotFound();
    }

    public function test_contacts_cancel_their_own_upcoming_appointments(): void
    {
        [, $workspace] = $this->workspaceWithApps();
        $access = $this->accessFor($workspace);
        $neighbour = $this->accessFor($workspace);

        $upcoming = Appointment::factory()->for($workspace)->at(now()->addDays(2)->setTime(10, 0)->toDateTimeString())->create(['contact_id' => $access->contact_id, 'title' => 'Dental check-up']);
        $past = Appointment::factory()->for($workspace)->at(now()->subDays(3)->setTime(10, 0)->toDateTimeString())->completed()->create(['contact_id' => $access->contact_id, 'title' => 'Old cleaning']);
        $theirs = Appointment::factory()->for($workspace)->at(now()->addDays(2)->setTime(12, 0)->toDateTimeString())->create(['contact_id' => $neighbour->contact_id, 'title' => 'Neighbour visit']);

        $this->asPortal($access)->get(route('portal.appointments', $workspace))->assertOk()
            ->assertSee('Dental check-up')->assertSee('Old cleaning')->assertDontSee('Neighbour visit');

        $this->asPortal($access)->post(route('portal.appointments.cancel', [$workspace, $upcoming->id]), ['reason' => 'Travelling'])
            ->assertSessionHas('flash.type', 'success');
        $upcoming->refresh();
        $this->assertSame('cancelled', $upcoming->status);
        $this->assertStringContainsString('Travelling', $upcoming->cancel_reason);

        $this->asPortal($access)->post(route('portal.appointments.cancel', [$workspace, $past->id]))->assertStatus(422);
        $this->asPortal($access)->post(route('portal.appointments.cancel', [$workspace, $theirs->id]))->assertNotFound();
        $this->assertSame('scheduled', $theirs->fresh()->status);
    }

    public function test_documents_waiting_for_the_contacts_signature_are_listed(): void
    {
        [, $workspace] = $this->workspaceWithApps();
        $access = $this->accessFor($workspace, ['email' => 'signer@example.com']);
        $request = SignatureRequest::factory()->for($workspace)->create(['title' => 'Lease agreement']);
        $signer = SignatureSigner::factory()->create(['signature_request_id' => $request->id, 'email' => 'signer@example.com']);
        $otherRequest = SignatureRequest::factory()->for($workspace)->create(['title' => 'Someone else contract']);
        SignatureSigner::factory()->create(['signature_request_id' => $otherRequest->id, 'email' => 'other@example.com']);

        $this->asPortal($access)->get(route('portal.documents', $workspace))->assertOk()
            ->assertSee('Lease agreement')
            ->assertSee($signer->signingUrl())
            ->assertDontSee('Someone else contract');
    }

    public function test_sections_follow_enabled_apps_and_the_owners_choices(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $workspace = $owner->currentWorkspace;
        $workspace->enableModules(['contacts', 'invoicing'], $owner);
        $access = $this->accessFor($workspace);

        $this->asPortal($access)->get(route('portal.home', $workspace))->assertOk()->assertSee('Invoices &amp; quotes', false)->assertDontSee('Support requests');
        $this->asPortal($access)->get(route('portal.requests', $workspace))->assertNotFound();

        $this->actingAs($owner)->put(route('settings.portal.update'), ['sections' => ['documents'], 'welcome' => 'Welcome to Moyo Dental.'])
            ->assertSessionHas('flash.type', 'success');

        $this->asPortal($access)->get(route('portal.invoices', $workspace))->assertNotFound();
        $this->asPortal($access)->get(route('portal.home', $workspace))->assertOk()->assertSee('Welcome to Moyo Dental.')->assertDontSee('Invoices &amp; quotes', false);
        $this->get(route('portal.login', $workspace))->assertRedirect(route('portal.home', $workspace));
    }

    public function test_contacts_update_their_own_details(): void
    {
        [, $workspace] = $this->workspaceWithApps();
        $access = $this->accessFor($workspace);

        $this->asPortal($access)->put(route('portal.profile.update', $workspace), [
            'phone' => '+263 77 123 4567', 'address' => '12 Samora Machel Ave', 'city' => 'Harare', 'name' => 'Hacker',
        ])->assertSessionHas('flash.type', 'success');

        $contact = $access->contact->fresh();
        $this->assertSame('+263 77 123 4567', $contact->phone);
        $this->assertSame('Harare', $contact->city);
        $this->assertNotSame('Hacker', $contact->name);
    }

    public function test_inviting_needs_an_email_and_a_contact_from_this_workspace(): void
    {
        [$owner, $workspace] = $this->workspaceWithApps();
        $noEmail = Contact::factory()->for($workspace)->customer()->create(['email' => null]);
        $foreign = Contact::factory()->for(Workspace::factory()->create())->customer()->create();

        $this->actingAs($owner)->post(route('settings.portal.invite'), ['contact_id' => $noEmail->id, 'audience' => 'tenant'])
            ->assertSessionHasErrors('email');
        $this->actingAs($owner)->post(route('settings.portal.invite'), ['contact_id' => $foreign->id, 'audience' => 'tenant'])
            ->assertSessionHasErrors('contact_id');
        $this->actingAs($owner)->post(route('settings.portal.invite'), ['contact_id' => $noEmail->id, 'audience' => 'pirate', 'email' => 'x@example.com'])
            ->assertSessionHasErrors('audience');

        $this->actingAs($owner)->post(route('settings.portal.invite'), ['contact_id' => $noEmail->id, 'audience' => 'tenant', 'email' => 'Tenant@Example.com'])
            ->assertSessionHasNoErrors();
        $this->assertSame('tenant@example.com', PortalAccess::forWorkspace($workspace)->where('contact_id', $noEmail->id)->value('email'));
        Notification::assertSentOnDemandTimes(PortalSignInLink::class, 1);
    }

    public function test_members_cannot_manage_portal_access(): void
    {
        [, $workspace] = $this->workspaceWithApps();
        $member = $this->memberOf($workspace);
        $access = $this->accessFor($workspace);

        $this->actingAs($member)->get(route('settings.portal.index'))->assertForbidden();
        $this->actingAs($member)->post(route('settings.portal.toggle', $access))->assertForbidden();
        $this->assertTrue($access->fresh()->isActive());
    }
}
