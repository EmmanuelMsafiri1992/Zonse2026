<?php

namespace Tests\Feature;

use App\Models\ApprovalRule;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\PublicPageConfirmation;
use App\Notifications\WorkspaceAlert;
use App\Support\PublicPage;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\Service;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Payments\PaymentGateways;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class PublicPageTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Notification::fake();
        // Monday 12 Oct 2026, 08:00 in Harare (UTC+2), the factory workspace's timezone.
        Carbon::setTestNow('2026-10-12 06:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function liveWorkspace(array $page = []): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['contacts', 'invoicing', 'appointments'], $owner);
        $workspace = $workspace->fresh();
        app(PublicPage::class)->save($workspace, ['enabled' => true, 'blocks' => array_keys(PublicPage::BLOCKS)] + $page);

        return [$owner, $workspace->fresh()];
    }

    protected function service(Workspace $workspace, int $minutes = 30): Service
    {
        return Service::factory()->for($workspace)->create(['name' => 'Consultation', 'duration_minutes' => $minutes, 'price' => 40]);
    }

    protected function enablePaynow(Workspace $workspace): void
    {
        $gateways = app(PaymentGateways::class);
        $gateways->configure($workspace, $gateways->find('paynow'), true, ['integration_id' => '12345', 'integration_key' => 'paynow-test-key']);
    }

    /** @return array<string, string> */
    protected function visitor(array $overrides = []): array
    {
        return $overrides + ['name' => 'Chipo Banda', 'email' => 'chipo@example.com', 'phone' => '+263 77 000 0000'];
    }

    protected function assertVisitorEmailed(string $email, string $subjectContains): void
    {
        Notification::assertSentOnDemand(PublicPageConfirmation::class, fn (PublicPageConfirmation $n, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === $email && str_contains($n->subject, $subjectContains));
    }

    public function test_owner_sets_up_the_page_and_it_is_hidden_until_switched_on(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['contacts', 'invoicing', 'appointments'], $owner);
        $this->service($workspace);

        $this->get(route('public.show', $workspace))->assertNotFound();

        $this->actingAs($owner)->get(route('settings.public-page.edit'))->assertOk()
            ->assertSee(route('public.show', $workspace))
            ->assertSee('at least one priced item in stock', false)
            ->assertDontSee('Needs the Appointments app');
        $this->actingAs($owner)->put(route('settings.public-page.update'), [
            'enabled' => '1', 'headline' => 'Book with Sunrise', 'bio' => 'Family clinic in Harare.',
            'links' => [['label' => 'Instagram', 'url' => 'https://instagram.com/sunrise'], ['label' => '', 'url' => '']],
            'blocks' => ['booking', 'ordering'], 'min_notice_hours' => 2, 'max_days_ahead' => 14, 'capacity' => 1,
        ])->assertSessionHas('flash.type', 'success');

        $settings = app(PublicPage::class)->settings($workspace->fresh());
        $this->assertTrue($settings['enabled']);
        $this->assertSame([['label' => 'Instagram', 'url' => 'https://instagram.com/sunrise']], $settings['links']);

        auth()->logout();
        $this->get(route('public.show', $workspace))->assertOk()
            ->assertSee('Book with Sunrise')->assertSee('Family clinic in Harare.')->assertSee('https://instagram.com/sunrise')
            ->assertSee(route('public.booking', $workspace))
            ->assertDontSee(route('public.order', $workspace))
            ->assertDontSee(route('public.payment', $workspace));
        $this->get(route('public.order', $workspace))->assertNotFound();
        $this->get(route('public.payment', $workspace))->assertNotFound();
    }

    public function test_settings_are_validated_and_only_managers_can_change_them(): void
    {
        [$owner, $workspace] = $this->liveWorkspace();
        $member = User::factory()->create();
        $workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
        $member->switchWorkspace($workspace);

        $this->actingAs($owner)->put(route('settings.public-page.update'), [
            'links' => [['label' => 'Bad', 'url' => 'javascript:alert(1)']], 'blocks' => ['nope'],
            'min_notice_hours' => -1, 'max_days_ahead' => 0, 'capacity' => 0,
        ])->assertSessionHasErrors(['links.0.url', 'blocks.0', 'min_notice_hours', 'max_days_ahead', 'capacity']);

        $this->actingAs($member)->get(route('settings.public-page.edit'))->assertForbidden();
        $this->actingAs($member)->put(route('settings.public-page.update'), ['min_notice_hours' => 1, 'max_days_ahead' => 5, 'capacity' => 1])->assertForbidden();
    }

    public function test_visitor_books_a_free_time_and_becomes_a_contact(): void
    {
        [$owner, $workspace] = $this->liveWorkspace();
        $service = $this->service($workspace);

        $this->get(route('public.booking', [$workspace, 'service' => $service->id, 'date' => '2026-10-13']))->assertOk()
            ->assertSee('value="08:00"', false)->assertSee('value="16:30"', false)->assertDontSee('value="17:00"', false);

        $this->post(route('public.booking.store', $workspace), $this->visitor([
            'service_id' => $service->id, 'date' => '2026-10-13', 'time' => '09:00', 'notes' => 'First visit',
        ]))->assertRedirect()->assertSessionHas('flash.type', 'success');

        $appointment = Appointment::forWorkspace($workspace)->sole();
        $this->assertSame('2026-10-13 09:00', $appointment->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-13 09:30', $appointment->ends_at->format('Y-m-d H:i'));
        $this->assertSame('scheduled', $appointment->status);
        $this->assertEquals(40, $appointment->price);
        $this->assertStringContainsString('First visit', $appointment->notes);
        $this->assertSame('chipo@example.com', $appointment->contact->email);
        $this->assertSame('customer', $appointment->contact->type);

        $this->assertVisitorEmailed('chipo@example.com', 'Booking received');
        Notification::assertSentTo($owner, WorkspaceAlert::class);

        $this->get(route('public.booking.show', [$workspace, $appointment->uuid]))->assertOk()
            ->assertSee('Waiting for confirmation')->assertSee('Cancel booking');

        // The same visitor (any letter case) books again: no duplicate contact, and 09:00 is now taken.
        $this->get(route('public.booking', [$workspace, 'service' => $service->id, 'date' => '2026-10-13']))->assertDontSee('value="09:00"', false);
        $this->post(route('public.booking.store', $workspace), $this->visitor(['email' => 'CHIPO@example.com', 'service_id' => $service->id, 'date' => '2026-10-13', 'time' => '09:00']))
            ->assertSessionHasErrors('time');
        $this->post(route('public.booking.store', $workspace), $this->visitor(['email' => 'CHIPO@example.com', 'service_id' => $service->id, 'date' => '2026-10-13', 'time' => '10:00']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Contact::forWorkspace($workspace)->count());
        $this->assertSame(2, Appointment::forWorkspace($workspace)->count());
    }

    public function test_booking_respects_hours_notice_window_capacity_and_auto_confirm(): void
    {
        [, $workspace] = $this->liveWorkspace(['capacity' => 2, 'max_days_ahead' => 7]);
        $workspace->putSetting('appointments.auto_confirm', true);
        $service = $this->service($workspace, 60);
        $slots = fn (string $day) => app(PublicPage::class)->slots($workspace->fresh(), $service, CarbonImmutable::parse($day));

        // Today: 08:00 local now plus 2 hours notice; last hour-long slot ends at 17:00.
        $this->assertSame('10:00', $slots('2026-10-12')[0]);
        $this->assertSame('16:00', last($slots('2026-10-12')));
        $this->assertSame([], $slots('2026-10-17'), 'Saturday is not a working day');
        $this->assertSame([], $slots('2026-10-20'), 'Beyond the booking window');
        $this->assertSame([], $slots('2026-10-11'), 'In the past');

        // Two people fit at once; the third booking for 11:00 is refused.
        Appointment::factory()->for($workspace)->create(['service_id' => $service->id, 'status' => 'confirmed', 'starts_at' => '2026-10-13 11:00', 'ends_at' => '2026-10-13 12:00']);
        $this->assertContains('11:00', $slots('2026-10-13'));
        Appointment::factory()->for($workspace)->create(['service_id' => $service->id, 'status' => 'scheduled', 'starts_at' => '2026-10-13 11:30', 'ends_at' => '2026-10-13 12:00']);
        $this->assertNotContains('11:00', $slots('2026-10-13'));
        $this->assertNotContains('11:30', $slots('2026-10-13'));
        $this->assertContains('12:00', $slots('2026-10-13'));
        Appointment::factory()->for($workspace)->create(['service_id' => $service->id, 'status' => 'cancelled', 'starts_at' => '2026-10-13 12:00', 'ends_at' => '2026-10-13 13:00']);
        Appointment::factory()->for($workspace)->create(['service_id' => $service->id, 'status' => 'cancelled', 'starts_at' => '2026-10-13 12:00', 'ends_at' => '2026-10-13 13:00']);
        $this->assertContains('12:00', $slots('2026-10-13'), 'Cancelled bookings free the time');

        $this->post(route('public.booking.store', $workspace), $this->visitor(['service_id' => $service->id, 'date' => '2026-10-12', 'time' => '08:30']))->assertSessionHasErrors('time');
        $this->post(route('public.booking.store', $workspace), $this->visitor(['service_id' => $service->id, 'date' => '2026-10-13', 'time' => '12:00']))->assertSessionHasNoErrors();
        $this->assertSame('confirmed', Appointment::forWorkspace($workspace)->latest('id')->first()->status);
        $this->assertVisitorEmailed('chipo@example.com', 'Booking confirmed');
    }

    public function test_bots_inactive_services_and_other_workspaces_services_are_rejected(): void
    {
        [, $workspace] = $this->liveWorkspace();
        $service = $this->service($workspace);
        $inactive = Service::factory()->for($workspace)->inactive()->create();
        $foreign = Service::factory()->create();

        $this->post(route('public.booking.store', $workspace), $this->visitor(['service_id' => $service->id, 'date' => '2026-10-13', 'time' => '09:00', 'website' => 'http://spam.test']))
            ->assertSessionHasErrors('website');
        $this->post(route('public.booking.store', $workspace), $this->visitor(['service_id' => $inactive->id, 'date' => '2026-10-13', 'time' => '09:00']))->assertSessionHasErrors('service_id');
        $this->post(route('public.booking.store', $workspace), $this->visitor(['service_id' => $foreign->id, 'date' => '2026-10-13', 'time' => '09:00']))->assertSessionHasErrors('service_id');
        $this->assertSame(0, Appointment::allWorkspaces()->count());
        $this->assertSame(0, Contact::forWorkspace($workspace)->count());
    }

    public function test_visitor_cancels_an_upcoming_booking_from_its_link_but_not_a_past_one(): void
    {
        [$owner, $workspace] = $this->liveWorkspace();
        $service = $this->service($workspace);
        $upcoming = Appointment::factory()->for($workspace)->create(['service_id' => $service->id, 'status' => 'scheduled', 'starts_at' => '2026-10-14 10:00', 'ends_at' => '2026-10-14 10:30']);
        $past = Appointment::factory()->for($workspace)->create(['service_id' => $service->id, 'status' => 'confirmed', 'starts_at' => '2026-10-09 10:00', 'ends_at' => '2026-10-09 10:30']);

        $this->post(route('public.booking.cancel', [$workspace, $upcoming->uuid]))->assertRedirect();
        $this->assertSame('cancelled', $upcoming->fresh()->status);
        Notification::assertSentTo($owner, WorkspaceAlert::class);

        $this->get(route('public.booking.show', [$workspace, $past->uuid]))->assertOk()->assertDontSee('Cancel booking');
        $this->post(route('public.booking.cancel', [$workspace, $past->uuid]))->assertStatus(422);
        $this->assertSame('confirmed', $past->fresh()->status);

        $other = Workspace::factory()->create();
        app(PublicPage::class)->save($other, ['enabled' => true]);
        $this->get(route('public.booking.show', [$other, $upcoming->uuid]))->assertNotFound();
    }

    public function test_visitor_orders_items_and_is_sent_to_the_invoice_to_pay(): void
    {
        [$owner, $workspace] = $this->liveWorkspace();
        $bread = Item::factory()->for($workspace)->create(['type' => 'product', 'name' => 'Bread', 'price' => 2.50, 'stock_qty' => 10]);
        $cake = Item::factory()->for($workspace)->create(['type' => 'product', 'name' => 'Cake', 'price' => 12, 'stock_qty' => null]);
        $soldOut = Item::factory()->for($workspace)->create(['type' => 'product', 'name' => 'Pies', 'price' => 3, 'stock_qty' => 0]);
        $foreign = Item::factory()->create(['name' => 'Elsewhere', 'price' => 99]);

        $this->get(route('public.show', $workspace))->assertOk()->assertSee(route('public.order', $workspace));
        $this->get(route('public.order', $workspace))->assertOk()->assertSee('Bread')->assertSee('Cake')->assertDontSee('Pies')->assertDontSee('Elsewhere');

        $this->post(route('public.order.store', $workspace), $this->visitor(['quantities' => [$bread->id => 0]]))->assertSessionHasErrors('quantities');
        $this->post(route('public.order.store', $workspace), $this->visitor(['quantities' => [$bread->id => 11]]))->assertSessionHasErrors('quantities.'.$bread->id);

        $response = $this->post(route('public.order.store', $workspace), $this->visitor([
            'quantities' => [$bread->id => 4, $cake->id => 1, $soldOut->id => 2, $foreign->id => 1], 'notes' => 'Collect at 5pm',
        ]));

        $invoice = Invoice::forWorkspace($workspace)->with('lines')->sole();
        $response->assertRedirect($invoice->publicUrl());
        $this->assertSame('sent', $invoice->status);
        $this->assertSame('Online order', $invoice->reference);
        $this->assertEquals(22, $invoice->total);
        $this->assertSame(['Bread', 'Cake'], $invoice->lines->pluck('description')->all());
        $this->assertSame('Collect at 5pm', $invoice->notes);
        $this->assertVisitorEmailed('chipo@example.com', 'Your order');
        Notification::assertSentTo($owner, WorkspaceAlert::class);
        $this->get($invoice->publicUrl())->assertOk()->assertSee($invoice->number);
    }

    public function test_visitor_pays_an_amount_only_when_a_payment_method_is_on(): void
    {
        [, $workspace] = $this->liveWorkspace();
        $this->get(route('public.payment', $workspace))->assertNotFound();

        $this->enablePaynow($workspace);
        $this->get(route('public.show', $workspace))->assertSee(route('public.payment', $workspace));
        $this->get(route('public.payment', $workspace))->assertOk();

        $this->post(route('public.payment.store', $workspace), $this->visitor(['amount' => 0, 'purpose' => '']))->assertSessionHasErrors(['amount', 'purpose']);
        $response = $this->post(route('public.payment.store', $workspace), $this->visitor(['amount' => '75.50', 'purpose' => 'Deposit']));

        $invoice = Invoice::forWorkspace($workspace)->sole();
        $response->assertRedirect($invoice->publicUrl());
        $this->assertSame('sent', $invoice->status);
        $this->assertEquals(75.5, $invoice->balance);
        $this->get($invoice->publicUrl())->assertOk()->assertSee('Paynow');
    }

    public function test_an_approval_rule_holds_the_invoice_and_the_visitor_sees_a_received_page(): void
    {
        [, $workspace] = $this->liveWorkspace();
        $this->enablePaynow($workspace);
        ApprovalRule::factory()->create(['workspace_id' => $workspace->id, 'min_amount' => 100]);

        $response = $this->post(route('public.payment.store', $workspace), $this->visitor(['amount' => 500, 'purpose' => 'Wedding deposit']));

        $invoice = Invoice::forWorkspace($workspace)->sole();
        $response->assertRedirect(route('public.received', [$workspace, $invoice->uuid]));
        $this->assertSame('draft', $invoice->status);
        $this->get(route('public.received', [$workspace, $invoice->uuid]))->assertOk()->assertSee($invoice->number);
        $this->get($invoice->publicUrl())->assertNotFound();
        $this->assertVisitorEmailed('chipo@example.com', 'Your payment');
    }
}
