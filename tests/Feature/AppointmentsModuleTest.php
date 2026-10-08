<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\Service;
use Modules\Contacts\Models\Contact;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class AppointmentsModuleTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace, 2: Contact} */
    protected function bookingWorkspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['appointments'], $owner);
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Rudo Client', 'kind' => 'person', 'company_name' => null]);

        return [$owner, $workspace->fresh(), $contact];
    }

    /** @return array<string, mixed> */
    protected function bookingPayload(Contact $contact, array $overrides = []): array
    {
        return array_merge([
            'contact_id' => $contact->id,
            'date' => today()->addDay()->format('Y-m-d'),
            'time' => '10:00',
            'duration_minutes' => 45,
            'status' => 'scheduled',
            'notes' => 'First visit',
        ], $overrides);
    }

    public function test_appointment_routes_require_the_module_to_be_enabled(): void
    {
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('appointments.calendar'))
            ->assertRedirect(route('settings.modules.index'))
            ->assertSessionHas('flash');
    }

    public function test_a_booking_is_created_with_its_end_time_and_service_price(): void
    {
        [$owner, $workspace, $contact] = $this->bookingWorkspace();
        $service = Service::factory()->for($workspace)->create(['name' => 'Consultation', 'duration_minutes' => 30, 'price' => 25]);

        $response = $this->actingAs($owner)->post(route('appointments.store'), $this->bookingPayload($contact, [
            'service_id' => $service->id, 'staff_id' => $owner->id, 'price' => null,
        ]));

        $appointment = Appointment::query()->first();
        $response->assertRedirect(route('appointments.show', $appointment));

        $this->assertSame(today()->addDay()->format('Y-m-d').' 10:00:00', $appointment->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(today()->addDay()->format('Y-m-d').' 10:45:00', $appointment->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(25.0, $appointment->price);
        $this->assertSame('scheduled', $appointment->status);
        $this->assertNotEmpty($appointment->uuid);
        $this->assertSame($owner->id, $appointment->created_by);

        $this->actingAs($owner)->get(route('appointments.show', $appointment))
            ->assertOk()->assertSee('Rudo Client')->assertSee('Consultation')->assertSee('10:00');
    }

    public function test_validation_rejects_missing_customer_and_bad_times(): void
    {
        [$owner, $workspace, $contact] = $this->bookingWorkspace();

        $this->actingAs($owner)->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->bookingPayload($contact, ['contact_id' => '', 'time' => '25:99', 'duration_minutes' => 2]))
            ->assertRedirect(route('appointments.create'))
            ->assertSessionHasErrors(['contact_id', 'time', 'duration_minutes']);

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_double_booking_a_staff_member_is_blocked_unless_overlap_is_allowed(): void
    {
        [$owner, $workspace, $contact] = $this->bookingWorkspace();
        $other = Contact::factory()->for($workspace)->create(['name' => 'Tendai Busy', 'kind' => 'person', 'company_name' => null]);
        Appointment::factory()->for($workspace)->for($other)->at(today()->addDay()->format('Y-m-d').' 10:15', 30)->create(['staff_id' => $owner->id]);

        $this->actingAs($owner)->from(route('appointments.create'))
            ->post(route('appointments.store'), $this->bookingPayload($contact, ['staff_id' => $owner->id]))
            ->assertSessionHasErrors('time');
        $this->assertSame(1, Appointment::query()->count());

        // Adjacent slot (10:45 onwards) is fine.
        $this->actingAs($owner)->post(route('appointments.store'), $this->bookingPayload($contact, ['staff_id' => $owner->id, 'time' => '10:45']))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Appointment::query()->count());

        // Cancelled bookings no longer block, and allow_overlap overrides the check.
        $this->actingAs($owner)->post(route('appointments.store'), $this->bookingPayload($contact, ['staff_id' => $owner->id, 'allow_overlap' => 1]))
            ->assertSessionHasNoErrors();
        $this->assertSame(3, Appointment::query()->count());

        // Unassigned bookings never clash.
        $this->actingAs($owner)->post(route('appointments.store'), $this->bookingPayload($contact, ['staff_id' => null]))
            ->assertSessionHasNoErrors();
        $this->assertSame(4, Appointment::query()->count());
    }

    public function test_status_transitions_record_timestamps_and_reasons(): void
    {
        [$owner, $workspace, $contact] = $this->bookingWorkspace();
        $appointment = Appointment::factory()->for($workspace)->for($contact)->create();

        $this->actingAs($owner)->post(route('appointments.status', $appointment), ['status' => 'confirmed'])->assertRedirect();
        $this->assertSame('confirmed', $appointment->refresh()->status);
        $this->assertNotNull($appointment->confirmed_at);

        $this->actingAs($owner)->post(route('appointments.status', $appointment), ['status' => 'completed'])->assertRedirect();
        $this->assertSame('completed', $appointment->refresh()->status);
        $this->assertNotNull($appointment->completed_at);

        // Completed bookings cannot be confirmed again.
        $this->actingAs($owner)->post(route('appointments.status', $appointment), ['status' => 'confirmed'])->assertStatus(422);

        $this->actingAs($owner)->post(route('appointments.status', $appointment), ['status' => 'scheduled'])->assertRedirect();
        $this->actingAs($owner)->post(route('appointments.status', $appointment), ['status' => 'cancelled', 'reason' => 'Customer travelling'])->assertRedirect();
        $appointment->refresh();
        $this->assertSame('cancelled', $appointment->status);
        $this->assertSame('Customer travelling', $appointment->cancel_reason);
        $this->assertNull($appointment->completed_at);
        $this->assertNotNull($appointment->cancelled_at);

        $this->actingAs($owner)->get(route('appointments.show', $appointment))->assertOk()->assertSee('Customer travelling');
    }

    public function test_bookings_are_scoped_to_the_workspace_and_viewers_are_read_only(): void
    {
        [$owner, $workspace, $contact] = $this->bookingWorkspace();
        $mine = Appointment::factory()->for($workspace)->for($contact)->create();

        [$otherOwner, $otherWorkspace] = $this->ownerWithWorkspace();
        $otherWorkspace->enableModules(['appointments'], $otherOwner);
        $this->actingAs($otherOwner)->get(route('appointments.show', $mine))->assertNotFound();
        $this->actingAs($otherOwner)->get(route('appointments.index'))->assertOk()->assertDontSee('Rudo Client');

        $viewer = $this->memberOf($workspace, 'viewer');
        $this->actingAs($viewer)->get(route('appointments.show', $mine))->assertOk();
        $this->actingAs($viewer)->get(route('appointments.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('appointments.status', $mine), ['status' => 'confirmed'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('appointments.destroy', $mine))->assertForbidden();

        $member = $this->memberOf($workspace, 'member');
        $this->actingAs($member)->delete(route('appointments.destroy', $mine))->assertForbidden();
        $this->actingAs($owner)->delete(route('appointments.destroy', $mine))->assertRedirect(route('appointments.index'));
        $this->assertSoftDeleted('appointments', ['id' => $mine->id]);
    }

    public function test_the_calendar_shows_the_week_and_links_to_booking_slots(): void
    {
        [$owner, $workspace, $contact] = $this->bookingWorkspace();
        $monday = today()->startOfWeek();
        Appointment::factory()->for($workspace)->for($contact)->at($monday->format('Y-m-d').' 09:00')->create(['title' => 'Monday visit']);
        Appointment::factory()->for($workspace)->for($contact)->at($monday->copy()->addWeek()->format('Y-m-d').' 09:00')->create(['title' => 'Next week visit']);

        $this->actingAs($owner)->get(route('appointments.calendar'))
            ->assertOk()->assertSee('Monday visit')->assertDontSee('Next week visit')
            ->assertSee(route('appointments.create', ['date' => $monday->format('Y-m-d')]), false);

        $this->actingAs($owner)->get(route('appointments.calendar', ['week' => $monday->copy()->addWeek()->format('Y-m-d')]))
            ->assertOk()->assertSee('Next week visit')->assertDontSee('Monday visit');
    }

    public function test_the_list_filters_by_range_status_and_contact(): void
    {
        [$owner, $workspace, $contact] = $this->bookingWorkspace();
        $other = Contact::factory()->for($workspace)->create(['name' => 'Other Person', 'kind' => 'person', 'company_name' => null]);
        Appointment::factory()->for($workspace)->for($contact)->at(today()->subDays(3)->format('Y-m-d').' 09:00')->completed()->create(['title' => 'Old visit']);
        Appointment::factory()->for($workspace)->for($contact)->at(today()->addDays(2)->format('Y-m-d').' 09:00')->create(['title' => 'Future visit']);
        Appointment::factory()->for($workspace)->for($other)->at(today()->addDays(3)->format('Y-m-d').' 09:00')->confirmed()->create(['title' => 'Other visit']);

        $this->actingAs($owner)->get(route('appointments.index'))->assertOk()->assertSee('Future visit')->assertSee('Other visit')->assertDontSee('Old visit');
        $this->actingAs($owner)->get(route('appointments.index', ['range' => 'past']))->assertOk()->assertSee('Old visit')->assertDontSee('Future visit');
        $this->actingAs($owner)->get(route('appointments.index', ['range' => 'all', 'status' => 'confirmed']))->assertOk()->assertSee('Other visit')->assertDontSee('Future visit');
        $this->actingAs($owner)->get(route('appointments.index', ['contact' => $contact->id]))->assertOk()->assertSee('Old visit')->assertSee('Future visit')->assertDontSee('Other visit');
        $this->actingAs($owner)->get(route('appointments.index', ['q' => 'Other Person']))->assertOk()->assertSee('Other visit')->assertDontSee('Future visit');
    }

    public function test_services_can_be_managed_and_feed_the_booking_form(): void
    {
        [$owner, $workspace] = $this->bookingWorkspace();

        $this->actingAs($owner)->post(route('services.store'), ['name' => 'Haircut', 'duration_minutes' => 40, 'price' => 12.5, 'color' => '#2563eb'])
            ->assertRedirect(route('services.index'));
        $service = Service::query()->first();
        $this->assertSame(40, $service->duration_minutes);
        $this->assertTrue($service->is_active);

        $this->actingAs($owner)->post(route('services.store'), ['name' => '', 'duration_minutes' => 1, 'price' => -1, 'color' => 'blue'])
            ->assertSessionHasErrors(['name', 'duration_minutes', 'price', 'color']);

        $this->actingAs($owner)->get(route('appointments.create'))->assertOk()->assertSee('Haircut · 40min');

        $this->actingAs($owner)->put(route('services.update', $service), ['name' => 'Haircut', 'duration_minutes' => 40, 'price' => 15, 'is_active' => 0])->assertRedirect();
        $this->assertFalse($service->refresh()->is_active);
        $this->actingAs($owner)->get(route('appointments.create'))->assertOk()->assertDontSee('Haircut · 40min');

        $this->actingAs($owner)->get(route('services.index', ['status' => 'inactive']))->assertOk()->assertSee('Haircut');

        $this->actingAs($owner)->delete(route('services.destroy', $service))->assertRedirect(route('services.index'));
        $this->assertSoftDeleted('services', ['id' => $service->id]);
    }

    public function test_booking_settings_change_defaults_for_new_bookings(): void
    {
        [$owner, $workspace, $contact] = $this->bookingWorkspace();

        $this->actingAs($owner)->get(route('settings.appointments.edit'))->assertOk()->assertSee('Opening hours');

        $this->actingAs($owner)->put(route('settings.appointments.update'), [
            'slot_minutes' => 15, 'default_duration' => 20, 'day_start' => '07:30', 'day_end' => '18:00',
            'working_days' => [1, 2, 3, 4, 5, 6], 'auto_confirm' => 1, 'booking_note' => 'Ask for the medical aid number.',
        ])->assertRedirect()->assertSessionHas('flash');

        $workspace->refresh();
        $this->assertSame('07:30', $workspace->setting('appointments.day_start'));
        $this->assertSame([1, 2, 3, 4, 5, 6], $workspace->setting('appointments.working_days'));
        $this->assertTrue((bool) $workspace->setting('appointments.auto_confirm'));

        $this->actingAs($owner)->get(route('appointments.create'))->assertOk()
            ->assertSee('Ask for the medical aid number.')
            ->assertSee('07:30 – 18:00');

        $this->actingAs($owner)->put(route('settings.appointments.update'), ['slot_minutes' => 15, 'default_duration' => 20, 'day_start' => '18:00', 'day_end' => '09:00', 'working_days' => []])
            ->assertSessionHasErrors(['day_end', 'working_days']);

        $member = $this->memberOf($workspace, 'member');
        $this->actingAs($member)->get(route('settings.appointments.edit'))->assertForbidden();
    }

    public function test_search_widget_contact_page_and_module_sync_include_appointments(): void
    {
        [$owner, $workspace, $contact] = $this->bookingWorkspace();
        $todayAt = Appointment::localNow()->setTime(23, 30)->format('Y-m-d H:i');
        Appointment::factory()->for($workspace)->for($contact)->at($todayAt, 20)->create(['title' => 'Evening check-up']);

        $this->actingAs($owner)->get(route('search', ['q' => 'Evening']))->assertOk()->assertSee('Evening check-up');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee("Today's bookings", false)->assertSee('Evening check-up');
        $this->actingAs($owner)->get(route('contacts.show', $contact))->assertOk()
            ->assertSee(route('appointments.create', ['contact' => $contact->id]), false)
            ->assertSee(route('appointments.index', ['contact' => $contact->id]), false);

        $this->artisan('zonseo:sync-modules')->assertSuccessful();
        $this->assertTrue(Module::query()->where('key', 'appointments')->first()->is_installed);
    }

    public function test_notes_and_activity_are_recorded_for_bookings(): void
    {
        [$owner, $workspace, $contact] = $this->bookingWorkspace();
        $appointment = Appointment::factory()->for($workspace)->for($contact)->create();

        $this->actingAs($owner)->post(route('appointments.comments.store', $appointment), ['body' => 'Patient prefers mornings.'])->assertRedirect();
        $this->assertSame(1, $appointment->comments()->count());
        $this->actingAs($owner)->get(route('appointments.show', $appointment))->assertOk()->assertSee('Patient prefers mornings.');

        $this->actingAs($owner)->post(route('appointments.status', $appointment), ['status' => 'confirmed']);

        $this->assertDatabaseHas('activity_log', ['subject_type' => Appointment::class, 'subject_id' => $appointment->id, 'event' => 'created']);
        $this->assertDatabaseHas('activity_log', ['subject_type' => Appointment::class, 'subject_id' => $appointment->id, 'event' => 'updated']);
    }
}
