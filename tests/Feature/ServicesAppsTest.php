<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Services: gym, field service, legal, garage, laundry, security company and photography. */
class ServicesAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_gym_members_must_be_current_to_train_and_freezing_extends_the_membership(): void
    {
        $app = 'gym';
        [$owner, $workspace] = $this->appWorkspace($app);
        $alice = $this->record($workspace, $app, 'members', 'Alice', 'active', ['plan' => 'monthly'], ['occurs_on' => today()]);
        $this->assertTrue($alice->due_on->isSameDay(today()->addMonthNoOverflow()));
        $bob = $this->record($workspace, $app, 'members', 'Bob', 'frozen', ['plan' => 'monthly'], ['occurs_on' => today()]);
        $carl = $this->record($workspace, $app, 'members', 'Carl', 'active', ['plan' => 'monthly'], ['occurs_on' => today()->subMonths(2)]);
        $this->assertSame('expired', $carl->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'check_ins']), ['title' => 'Visit', 'status' => 'checked_in', 'data' => ['member' => $bob->id]])
            ->assertSessionHasErrors(['data.member' => 'Bob\'s membership is frozen.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'check_ins']), ['title' => 'Visit', 'status' => 'checked_in', 'data' => ['member' => $carl->id]])
            ->assertSessionHasErrors(['data.member' => 'Carl\'s membership is expired.']);
        $this->record($workspace, $app, 'pt_sessions', 'Session A', 'booked', ['member' => $alice->id, 'start_time' => '10:00'], ['occurs_on' => today(), 'assignee_id' => $owner->id]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'pt_sessions']), ['title' => 'Session B', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'assignee_id' => $owner->id, 'data' => ['member' => $alice->id, 'start_time' => '10:00']])
            ->assertSessionHasErrors(['data.start_time' => 'The trainer already has Session A at 10:00.']);

        $this->actingAs($owner)->post($alice->url().'/actions/check_in')->assertSessionHas('flash.message', 'Alice checked in.');
        $this->assertSame(1, Record::query()->where('entity', 'check_ins')->count());
        $this->actingAs($owner)->get($alice->url())->assertOk()->assertSee('Attendance')->assertSee('Visits this month');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Active members')->assertSee('Renewals in the next 7 days');

        $due = $alice->fresh()->due_on;
        $this->actingAs($owner)->post($alice->url().'/actions/freeze')->assertSessionHas('flash.message', 'Alice\'s membership is frozen.');
        $this->travel(5)->days();
        $this->actingAs($owner)->post($alice->url().'/actions/unfreeze')->assertSessionHas('flash.message', 'Alice is back; renews on '.$due->copy()->addDays(5)->format('d M Y').'.');
        $this->travel(2)->months();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $alice->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Check-ins by month')->assertSee('Members by plan');
    }

    public function test_field_jobs_are_due_by_priority_and_need_sign_off(): void
    {
        $app = 'field-service';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), ['title' => 'Leak', 'status' => 'scheduled', 'data' => ['priority' => 'normal', 'site_address' => '1 Kabulonga Rd']])
            ->assertSessionHasErrors(['occurs_on' => 'Give the date of the visit.', 'assignee_id' => 'Give the technician.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), ['title' => 'Leak', 'status' => 'completed', 'data' => ['priority' => 'normal', 'site_address' => '1 Kabulonga Rd', 'work_done' => 'Fixed']])
            ->assertSessionHasErrors(['data.customer_signed' => 'The customer must sign off before the job is completed.']);

        $pipe = $this->record($workspace, $app, 'jobs', 'Burst pipe', 'new', ['priority' => 'emergency', 'category' => 'plumbing']);
        $this->assertTrue($pipe->due_on->isToday());
        $this->assertTrue($this->record($workspace, $app, 'jobs', 'Tap', 'new', ['priority' => 'high'])->due_on->isSameDay(today()->addDay()));
        $this->actingAs($owner)->post($pipe->url().'/actions/schedule', ['date' => today()->toDateString()])
            ->assertSessionHas('flash.message', 'Burst pipe scheduled for '.today()->format('d M Y').' with '.$owner->name.'.');
        $this->actingAs($owner)->post($pipe->url().'/actions/start')->assertSessionHas('flash.message', 'Burst pipe in progress.');
        $this->actingAs($owner)->post($pipe->url().'/actions/complete', ['work_done' => 'Replaced pipe'])->assertSessionHasErrors('customer_signed');
        $this->actingAs($owner)->post($pipe->url().'/actions/complete', ['work_done' => 'Replaced pipe', 'hours' => 2, 'customer_signed' => '1'])->assertSessionHas('flash.message', 'Burst pipe completed on time.');
        $this->actingAs($owner)->post($pipe->url().'/actions/invoice')->assertSessionHas('flash.message', 'Burst pipe invoiced.');

        $geyser = $this->record($workspace, $app, 'jobs', 'Geyser', 'in_progress', ['priority' => 'normal', 'category' => 'plumbing'], ['due_on' => today()->subDay()]);
        $this->actingAs($owner)->post($geyser->url().'/actions/complete', ['work_done' => 'New element', 'customer_signed' => '1'])->assertSessionHas('flash.message', 'Geyser completed, 1 day late.');
        $this->record($workspace, $app, 'jobs', 'Fence repair', 'new', ['priority' => 'low'], ['due_on' => today()->subDays(2)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Overdue jobs')->assertSee('Fence repair')->assertSee('Emergencies open');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Jobs by category')->assertSee('Plumbing')->assertSee('50%');
    }

    public function test_legal_time_is_billed_before_a_matter_closes_and_hearings_can_be_postponed(): void
    {
        $app = 'legal';
        [$owner, $workspace] = $this->appWorkspace($app);
        $matter = $this->record($workspace, $app, 'matters', 'Banda v Phiri', 'open');
        $old = $this->record($workspace, $app, 'matters', 'Old case', 'closed');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'time_entries']), ['title' => 'Research', 'status' => 'unbilled', 'data' => ['matter' => $matter->id, 'hours' => 30, 'rate' => 500]])
            ->assertSessionHasErrors(['data.hours' => 'Hours must be more than 0 and no more than 24.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'court_dates']), ['title' => 'Hearing', 'status' => 'scheduled', 'data' => ['matter' => $old->id]])
            ->assertSessionHasErrors(['data.matter' => 'Old case is closed.']);

        $research = $this->record($workspace, $app, 'time_entries', 'Research', 'unbilled', ['matter' => $matter->id, 'hours' => 2, 'rate' => 500], ['assignee_id' => $owner->id]);
        $this->assertEquals(1000, $research->amount);
        $drafting = $this->record($workspace, $app, 'time_entries', 'Drafting', 'unbilled', ['matter' => $matter->id, 'hours' => 1.5, 'rate' => 500]);
        $this->actingAs($owner)->post($matter->url().'/actions/close')->assertSessionHasErrors(['status' => 'Banda v Phiri still has 3.5 hours unbilled; bill or write it off first.']);
        $this->actingAs($owner)->post($drafting->url().'/actions/write_off')->assertSessionHas('flash.message', '1.5 hours on Drafting written off.');
        $this->actingAs($owner)->get($matter->url())->assertOk()->assertSee('Unbilled')->assertSee('2 hours');
        $this->actingAs($owner)->post($matter->url().'/actions/bill')->assertSessionHas('flash.message', 'Billed 2 hours ('.$this->money(1000).') on Banda v Phiri.');
        $this->assertSame('billed', $research->fresh()->status);
        $this->actingAs($owner)->post($matter->url().'/actions/close')->assertSessionHas('flash.message', 'Banda v Phiri closed.');

        $other = $this->record($workspace, $app, 'matters', 'Estate of Tembo', 'open');
        $mention = $this->record($workspace, $app, 'court_dates', 'Mention', 'scheduled', ['matter' => $other->id, 'court' => 'High Court'], ['occurs_on' => today()->addDays(3)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Court dates in the next 14 days')->assertSee('Mention')->assertSee('Estate of Tembo');
        $this->actingAs($owner)->post($mention->url().'/actions/postpone', [])->assertSessionHasErrors(['date' => 'Give the new date.']);
        $this->actingAs($owner)->post($mention->url().'/actions/postpone', ['date' => today()->addDays(20)->toDateString()])
            ->assertSessionHas('flash.message', 'Mention postponed to '.today()->addDays(20)->format('d M Y').'.');
        $this->assertSame('postponed', $mention->fresh()->status);
        $this->assertSame(1, Record::query()->where('entity', 'court_dates')->where('status', 'scheduled')->whereDate('occurs_on', today()->addDays(20)->toDateString())->count());
        $this->actingAs($owner)->get($other->url())->assertOk()->assertSee('Coming hearings')->assertSee('High Court');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Time by matter')->assertSee('Time by lawyer')->assertSee($owner->name);
    }

    public function test_garage_job_cards_follow_the_vehicle_and_its_odometer(): void
    {
        $app = 'garage';
        [$owner, $workspace] = $this->appWorkspace($app);
        $car = $this->record($workspace, $app, 'vehicles', 'ABZ 1234', 'active', ['make' => 'Toyota', 'odometer' => 50000]);
        $scrap = $this->record($workspace, $app, 'vehicles', 'OLD 1', 'scrapped', ['make' => 'Nissan']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'job_cards']), ['title' => 'Service', 'status' => 'booked', 'data' => ['vehicle' => $scrap->id]])
            ->assertSessionHasErrors(['data.vehicle' => 'OLD 1 is scrapped.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'job_cards']), ['title' => 'Service', 'status' => 'booked', 'data' => ['vehicle' => $car->id, 'odometer_in' => 40000]])
            ->assertSessionHasErrors(['data.odometer_in' => 'The odometer can\'t be lower than ABZ 1234\'s last reading of 50,000 km.']);

        $service = $this->record($workspace, $app, 'job_cards', 'Service', 'booked', ['vehicle' => $car->id, 'odometer_in' => 52000]);
        $this->assertEquals(52000, $car->fresh()->value('odometer'));
        $this->actingAs($owner)->post($service->url().'/actions/start')->assertSessionHas('flash.message', 'Service is in the workshop.');
        $this->actingAs($owner)->post($service->url().'/actions/parts')->assertSessionHas('flash.message', 'Service is waiting for parts.');
        $this->actingAs($owner)->post($service->url().'/actions/start')->assertSessionHas('flash.message', 'Service is in the workshop.');
        $this->actingAs($owner)->post($service->url().'/actions/ready', ['labour_hours' => 2, 'amount' => 1500])->assertSessionHas('flash.message', 'Service is ready for collection ('.$this->money(1500).').');
        $this->actingAs($owner)->post($service->url().'/actions/collect')->assertSessionHas('flash.message', 'Service collected.');
        $this->actingAs($owner)->get($car->url())->assertOk()->assertSee('Service history')->assertSee('52,000 km');

        $this->record($workspace, $app, 'job_cards', 'Brakes', 'booked', ['vehicle' => $car->id], ['due_on' => today()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Promised today or late')->assertSee('Brakes')->assertSee('Ready to collect');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Workshop by month')->assertSee($this->money(1500));
    }

    public function test_laundry_orders_are_due_by_service_and_paid_before_collection(): void
    {
        $app = 'laundry';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), ['title' => 'Shirts', 'status' => 'received', 'data' => ['pieces' => 0]])
            ->assertSessionHasErrors(['data.pieces' => 'An order needs at least one piece.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), ['title' => 'Shirts', 'status' => 'collected', 'data' => ['pieces' => 3]])
            ->assertSessionHasErrors(['status' => 'Take payment before the order is collected.']);

        $shirts = $this->record($workspace, $app, 'orders', 'Shirts', 'received', ['pieces' => 5, 'service' => 'dry_clean'], ['amount' => 100]);
        $this->assertTrue($shirts->due_on->isSameDay(today()->addDays(3)));
        $suit = $this->record($workspace, $app, 'orders', 'Suit', 'received', ['pieces' => 2, 'service' => 'dry_clean', 'express' => true], ['amount' => 100]);
        $this->assertEquals(150, $suit->amount);
        $this->assertTrue($suit->due_on->isSameDay(today()->addDay()));

        $this->actingAs($owner)->post($shirts->url().'/actions/wash')->assertSessionHas('flash.message', 'Shirts is being washed.');
        $this->actingAs($owner)->post($shirts->url().'/actions/ready')->assertSessionHas('flash.message', 'Shirts is ready.');
        $this->actingAs($owner)->post($shirts->url().'/actions/collect')->assertSessionHasErrors(['status' => 'Take payment of '.$this->money(100).' before the order is collected.']);
        $this->actingAs($owner)->post($shirts->url().'/actions/pay')->assertSessionHas('flash.message', $this->money(100).' paid for Shirts.');
        $this->actingAs($owner)->post($shirts->url().'/actions/collect')->assertSessionHas('flash.message', 'Shirts collected.');

        $this->record($workspace, $app, 'orders', 'Duvet', 'washing', ['pieces' => 1, 'service' => 'duvet'], ['due_on' => today()->subDay()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Late orders')->assertSee('Duvet')->assertSee('Ready, not collected');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Orders by service')->assertSee('Dry clean');
    }

    public function test_security_occurrences_escalate_incidents_and_patrols_are_logged(): void
    {
        $app = 'security-company';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sites']), ['title' => 'Mall', 'status' => 'active', 'data' => ['address' => 'Cairo Rd', 'guards_required' => 0]])
            ->assertSessionHasErrors(['data.guards_required' => 'An active site needs at least one guard.']);
        $mall = $this->record($workspace, $app, 'sites', 'Mall', 'active', ['address' => 'Cairo Rd', 'guards_required' => 2], ['amount' => 5000]);
        $depot = $this->record($workspace, $app, 'sites', 'Depot', 'suspended', ['address' => 'Industrial', 'guards_required' => 1]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'occurrences']), ['title' => 'Visitor', 'status' => 'logged', 'data' => ['site' => $depot->id, 'type' => 'visitor', 'details' => 'Delivery']])
            ->assertSessionHasErrors(['data.site' => 'Depot is suspended.']);

        $breakIn = $this->record($workspace, $app, 'occurrences', 'Break-in', 'logged', ['site' => $mall->id, 'type' => 'incident', 'details' => 'Window broken']);
        $this->assertSame('escalated', $breakIn->status);
        $this->assertNotEmpty($breakIn->value('time'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Open escalations')->assertSee('Break-in')->assertSee('Not patrolled today');
        $this->actingAs($owner)->post($breakIn->url().'/actions/close')->assertSessionHasErrors(['action_taken' => 'Say what was done before closing an escalated entry.']);
        $this->actingAs($owner)->post($breakIn->url().'/actions/close', ['action_taken' => 'Police called'])->assertSessionHas('flash.message', 'Break-in closed.');
        $this->assertStringContainsString('Action taken: Police called', $breakIn->fresh()->value('details'));

        $this->actingAs($owner)->post($mall->url().'/actions/patrol', ['details' => 'All quiet'])->assertSessionHas('flash.message', 'Patrol logged at Mall.');
        $this->assertSame(1, Record::query()->where('entity', 'occurrences')->where('title', 'Patrol at Mall')->count());
        $this->actingAs($owner)->get($mall->url())->assertOk()->assertSee('Occurrence book')->assertSee('Patrol at Mall')->assertSee('Last patrol');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Occurrences by site')->assertSee('Mall');
    }

    public function test_photography_shoots_need_a_deposit_and_a_gallery_and_photographers_dont_double_book(): void
    {
        $app = 'photography';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shoots']), ['title' => 'Portraits', 'status' => 'booked', 'amount' => 1000, 'data' => ['type' => 'portrait']])
            ->assertSessionHasErrors(['data.deposit_paid' => 'Take a deposit to book the shoot.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shoots']), ['title' => 'Portraits', 'status' => 'enquiry', 'amount' => 1000, 'data' => ['type' => 'portrait', 'deposit_paid' => 2000]])
            ->assertSessionHasErrors(['data.deposit_paid' => 'The deposit can\'t be more than the package price.']);

        $wedding = $this->record($workspace, $app, 'shoots', 'Mwale wedding', 'enquiry', ['type' => 'wedding', 'location' => 'Lusaka'], ['amount' => 10000, 'occurs_on' => today()->addDays(10), 'assignee_id' => $owner->id]);
        $this->assertTrue($wedding->due_on->isSameDay(today()->addDays(52)));
        $this->actingAs($owner)->post($wedding->url().'/actions/book', ['deposit' => 2500])
            ->assertSessionHas('flash.message', 'Mwale wedding booked with a '.$this->money(2500).' deposit; '.$this->money(7500).' to pay.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shoots']), ['title' => 'Headshots', 'status' => 'booked', 'amount' => 500, 'occurs_on' => today()->addDays(10)->toDateString(), 'assignee_id' => $owner->id, 'data' => ['type' => 'corporate', 'deposit_paid' => 100]])
            ->assertSessionHasErrors(['assignee_id' => $owner->name.' is already shooting Mwale wedding that day.']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Shoots in the next 14 days')->assertSee('Mwale wedding');
        $this->actingAs($owner)->get($wedding->url())->assertOk()->assertSee('Balance')->assertSee($this->money(7500));

        $this->actingAs($owner)->post($wedding->url().'/actions/shot')->assertSessionHas('flash.message', 'Mwale wedding shot; gallery due '.today()->addDays(52)->format('d M Y').'.');
        $this->actingAs($owner)->post($wedding->url().'/actions/edit')->assertSessionHas('flash.message', 'Mwale wedding in editing.');
        $this->actingAs($owner)->post($wedding->url().'/actions/deliver')->assertSessionHasErrors(['gallery_link' => 'Add the gallery link to deliver the shoot.']);
        $this->actingAs($owner)->post($wedding->url().'/actions/deliver', ['gallery_link' => 'https://example.com/mwale'])->assertSessionHas('flash.message', 'Mwale wedding delivered on time.');
        $this->record($workspace, $app, 'shoots', 'Banda family', 'delivered', ['type' => 'family', 'deposit_paid' => 300, 'gallery_link' => 'https://example.com/banda'], ['amount' => 800, 'occurs_on' => today()->subDays(5)]);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Shoots by type')->assertSee('Family')->assertSee($this->money(500));
    }

    private function money(float $amount): string
    {
        return Money::format($amount);
    }

    private function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    private function record(Workspace $workspace, string $app, string $entity, string $title, string $status, array $data = [], array $attributes = []): Record
    {
        return Record::factory()->ofEntity($app, $entity, $data)->create([
            'workspace_id' => $workspace->id,
            'title' => $title,
            'status' => $status,
            ...$attributes,
        ]);
    }
}
