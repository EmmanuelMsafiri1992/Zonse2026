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

/** Transport: taxi dispatch, boda-boda riders, coach operator, staff transport and haulage. */
class TransportAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_rides_go_to_available_licensed_drivers_and_record_the_company_share(): void
    {
        $app = 'taxi';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'drivers']), ['title' => 'Lapsed', 'status' => 'available', 'data' => ['phone' => '0977000000', 'vehicle' => 'Corolla', 'licence_expiry' => today()->subDay()->toDateString()]])
            ->assertSessionHasErrors(['data.licence_expiry' => 'The driver\'s licence has expired.']);
        $sam = $this->record($workspace, $app, 'drivers', 'Sam', 'available', ['phone' => '0977111111', 'vehicle' => 'Corolla ABC 1', 'licence_expiry' => today()->addDays(20)->toDateString(), 'commission_percent' => 20]);
        $old = $this->record($workspace, $app, 'drivers', 'Old', 'off_duty', ['phone' => '0977222222', 'vehicle' => 'Vitz', 'licence_expiry' => today()->subDays(3)->toDateString()]);
        $ride = fn (array $data = []) => ['title' => 'Ann', 'status' => 'assigned', 'data' => ['pickup' => 'Mall', 'dropoff' => 'Airport', ...$data]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'rides']), $ride())->assertSessionHasErrors(['data.driver' => 'Give the driver.']);

        $tom = $this->record($workspace, $app, 'rides', 'Tom', 'requested', ['pickup' => 'Mall', 'dropoff' => 'Airport'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($tom->url().'/actions/assign', ['driver' => $old->id])->assertSessionHasErrors(['driver' => 'Old\'s licence has expired.']);
        $this->actingAs($owner)->post($tom->url().'/actions/assign', ['driver' => $sam->id])->assertSessionHas('flash.message', 'Sam dispatched to Mall.');
        $this->assertSame('on_trip', $sam->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'rides']), $ride(['driver' => $sam->id]))->assertSessionHasErrors(['data.driver' => 'Sam is on trip.']);

        $this->actingAs($owner)->post($tom->url().'/actions/pick_up')->assertSessionHas('flash.message', 'Tom picked up.');
        $this->actingAs($owner)->post($tom->url().'/actions/complete', ['fare' => 200, 'distance' => 12])->assertSessionHas('flash.message', 'Tom dropped off; fare '.$this->money(200).'.');
        $this->assertSame('available', $sam->fresh()->status);
        $this->assertEquals(40, $tom->fresh()->value('_company_share'));

        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('suspended', $old->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Drivers available')->assertSee('Licences expiring in 30 days')->assertSee('Sam');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Rides by driver')->assertSee($this->money(40));
    }

    public function test_rider_remittances_are_checked_against_the_daily_target_and_missed_days_logged(): void
    {
        $app = 'motorbike-boda-boda-delivery-rider';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'riders']), ['title' => 'New', 'status' => 'active', 'data' => ['phone' => '0977000000', 'bike' => 'BAA 1']])
            ->assertSessionHasErrors(['data.helmet_issued' => 'Issue a helmet before the rider starts.']);
        $moses = $this->record($workspace, $app, 'riders', 'Moses', 'active', ['phone' => '0977111111', 'bike' => 'BAA 2', 'daily_target' => 100, 'helmet_issued' => true]);
        $peter = $this->record($workspace, $app, 'riders', 'Peter', 'active', ['phone' => '0977222222', 'bike' => 'BAA 3', 'daily_target' => 100, 'helmet_issued' => true]);
        $ken = $this->record($workspace, $app, 'riders', 'Ken', 'suspended', ['phone' => '0977333333', 'bike' => 'BAA 4']);

        $this->actingAs($owner)->post($moses->url().'/actions/remit', ['amount' => 100, 'method' => 'cash'])->assertSessionHas('flash.message', 'Moses remitted '.$this->money(100).'.');
        $this->actingAs($owner)->post($moses->url().'/actions/remit', ['amount' => 100])->assertSessionHasErrors(['amount' => 'Moses has already remitted today.']);
        $this->travel(1)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $missed = Record::query()->where('entity', 'remittances')->where('status', 'missed')->sole();
        $this->assertSame('Peter', $missed->title);
        $this->assertEquals(100, $missed->value('_shortfall'));
        $this->actingAs($owner)->post($moses->url().'/actions/remit', ['amount' => 60])->assertSessionHas('flash.message', 'Moses remitted '.$this->money(60).', '.$this->money(40).' short.');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), ['title' => 'Box', 'status' => 'requested', 'data' => ['rider' => $ken->id, 'pickup' => 'Town', 'dropoff' => 'Kabwata']])
            ->assertSessionHasErrors(['data.rider' => 'Ken is suspended.']);
        $parcel = $this->record($workspace, $app, 'jobs', 'Parcel', 'requested', ['pickup' => 'Town', 'dropoff' => 'Kabwata']);
        $this->actingAs($owner)->post($parcel->url().'/actions/assign', ['rider' => $moses->id])->assertSessionHas('flash.message', 'Parcel assigned to Moses.');
        $this->actingAs($owner)->post($parcel->url().'/actions/pick_up')->assertSessionHas('flash.message', 'Parcel picked up.');
        $this->actingAs($owner)->post($parcel->url().'/actions/deliver')->assertSessionHas('flash.message', 'Parcel delivered to Kabwata.');

        $this->actingAs($owner)->get($moses->url())->assertOk()->assertSee('This month')->assertSee('Shortfall');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Still to remit today')->assertSee('Peter');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Remittances by rider')->assertSee($this->money(160));
        $this->travelBack();
    }

    public function test_charters_fit_the_coach_and_a_coach_cannot_be_double_booked(): void
    {
        $app = 'bus-coach-booking';
        [$owner, $workspace] = $this->appWorkspace($app);
        $c1 = $this->record($workspace, $app, 'coaches', 'C1', 'available', ['registration' => 'ABC 1', 'seats' => 40, 'cof_expiry' => today()->addDays(60)->toDateString()]);
        $c2 = $this->record($workspace, $app, 'coaches', 'C2', 'maintenance', ['registration' => 'ABC 2', 'seats' => 60]);
        $c3 = $this->record($workspace, $app, 'coaches', 'C3', 'available', ['registration' => 'ABC 3', 'seats' => 60, 'cof_expiry' => today()->addDays(2)->toDateString()]);
        $charter = fn (Record $coach, int $from, int $to, array $data = []) => ['title' => 'Club', 'status' => 'booked', 'occurs_on' => today()->addDays($from)->toDateString(), 'due_on' => today()->addDays($to)->toDateString(), 'data' => ['coach' => $coach->id, 'route' => 'Lusaka - Livingstone', 'passengers' => 30, ...$data]];
        $store = route('apps.records.store', [$app, 'charters']);
        $this->actingAs($owner)->post($store, $charter($c1, 3, 4, ['passengers' => 50]))->assertSessionHasErrors(['data.passengers' => 'C1 only has 40 seats.']);
        $this->actingAs($owner)->post($store, $charter($c1, 4, 3))->assertSessionHasErrors(['due_on' => 'The return date is before the departure.']);
        $this->actingAs($owner)->post($store, $charter($c2, 3, 4))->assertSessionHasErrors(['data.coach' => 'C2 is in maintenance.']);
        $this->actingAs($owner)->post($store, $charter($c3, 5, 6))->assertSessionHasErrors(['data.coach' => 'C3\'s roadworthy certificate runs out on '.today()->addDays(2)->format('d M Y').'.']);

        $choir = $this->record($workspace, $app, 'charters', 'Choir', 'booked', ['coach' => $c1->id, 'route' => 'Lusaka - Ndola', 'passengers' => 35], ['occurs_on' => today()->addDays(3), 'due_on' => today()->addDays(5), 'amount' => 9000]);
        $this->actingAs($owner)->post($store, $charter($c1, 5, 6))->assertSessionHasErrors(['data.coach' => 'C1 is on Choir\'s charter from '.today()->addDays(3)->format('d M').'.']);
        $team = $this->record($workspace, $app, 'charters', 'Team', 'quoted', ['coach' => $c1->id, 'route' => 'Lusaka - Kitwe', 'passengers' => 20], ['occurs_on' => today()->addDays(4), 'due_on' => today()->addDays(4)]);
        $this->actingAs($owner)->post($team->url().'/actions/book')->assertSessionHasErrors('coach');
        $this->actingAs($owner)->get($c1->url())->assertOk()->assertSee('Upcoming charters')->assertSee('Choir');

        $this->actingAs($owner)->post($choir->url().'/actions/depart')->assertSessionHas('flash.message', 'Choir departed on C1.');
        $this->assertSame('on_trip', $c1->fresh()->status);
        $this->actingAs($owner)->post($choir->url().'/actions/complete')->assertSessionHas('flash.message', 'Choir\'s charter completed.');
        $this->assertSame('available', $c1->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Roadworthy certificates due in 30 days')->assertSee('ABC 3');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Charters by coach');
    }

    public function test_trip_logs_are_one_per_run_and_cannot_wind_the_odometer_back(): void
    {
        $app = 'school-bus-staff-transport';
        [$owner, $workspace] = $this->appWorkspace($app);
        $r1 = $this->record($workspace, $app, 'routes', 'R1', 'active', ['stops' => 'Gate, Mall', 'driver' => 'Joe']);
        $r2 = $this->record($workspace, $app, 'routes', 'R2', 'suspended', ['stops' => 'Gate']);
        $this->record($workspace, $app, 'routes', 'R3', 'active', ['stops' => 'Depot', 'driver' => 'Ann']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'passengers']), ['title' => 'Zed', 'status' => 'active', 'data' => ['route' => $r2->id]])
            ->assertSessionHasErrors(['data.route' => 'R2 is suspended.']);
        $this->record($workspace, $app, 'passengers', 'Amy', 'active', ['route' => $r1->id], ['amount' => 300]);
        $this->record($workspace, $app, 'passengers', 'Ben', 'active', ['route' => $r1->id], ['amount' => 300]);

        $this->record($workspace, $app, 'trips', 'Trip', 'completed', ['route' => $r1->id, 'run' => 'morning', 'odometer' => 1000, 'passengers_carried' => 2], ['occurs_on' => today()]);
        $trip = fn (array $data, string $status = 'completed') => ['title' => 'Trip', 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['route' => $r1->id, 'run' => 'afternoon', 'passengers_carried' => 2, ...$data]];
        $store = route('apps.records.store', [$app, 'trips']);
        $this->actingAs($owner)->post($store, $trip(['run' => 'morning']))->assertSessionHasErrors(['data.run' => 'The morning run on R1 is already logged for '.today()->format('d M Y').'.']);
        $this->actingAs($owner)->post($store, $trip(['odometer' => 900]))->assertSessionHasErrors(['data.odometer' => 'The odometer can\'t go below the last reading of 1,000.']);
        $this->actingAs($owner)->post($store, $trip(['passengers_carried' => 3]))->assertSessionHasErrors(['data.passengers_carried' => 'R1 only has 2 passengers.']);
        $this->actingAs($owner)->post($store, $trip(['odometer' => 1040], 'late'))->assertSessionHasNoErrors();
        $this->assertSame(2, Record::query()->where('entity', 'trips')->where('title', 'R1')->count());

        $this->actingAs($owner)->get($r1->url())->assertOk()->assertSee('Recent trips')->assertSee($this->money(600));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('No trip logged today')->assertSee('R3');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Trips by route')->assertSee('R1');
    }

    public function test_loads_need_a_free_truck_and_proof_of_delivery_and_show_their_margin(): void
    {
        $app = 'haulage';
        [$owner, $workspace] = $this->appWorkspace($app);
        $store = route('apps.records.store', [$app, 'loads']);
        $load = fn (string $status, array $data = [], array $extra = []) => ['title' => 'L2', 'status' => $status, 'data' => ['origin' => 'Lusaka', 'destination' => 'Durban', ...$data], ...$extra];
        $this->actingAs($owner)->post($store, $load('booked', [], ['occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString()]))
            ->assertSessionHasErrors(['due_on' => 'The delivery date is before loading.']);
        $this->actingAs($owner)->post($store, $load('loading'))->assertSessionHasErrors(['data.truck' => 'Give the truck.', 'data.driver' => 'Give the driver.']);

        $l1 = $this->record($workspace, $app, 'loads', 'L1', 'in_transit', ['origin' => 'Lusaka', 'destination' => 'Durban', 'truck' => 'ABC 123', 'driver' => 'Joe', 'weight' => 30], ['occurs_on' => today()->subDays(5), 'due_on' => today()->subDays(2), 'amount' => 1000]);
        $this->actingAs($owner)->post($store, $load('loading', ['truck' => 'abc 123', 'driver' => 'Ann']))->assertSessionHasErrors(['data.truck' => 'This truck is already carrying L1.']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('On the road')->assertSee('L1');

        $this->actingAs($owner)->post($l1->url().'/actions/advance')->assertSessionHasErrors(['pod_url' => 'Attach the proof of delivery.']);
        $this->actingAs($owner)->post($l1->url().'/actions/advance', ['pod_url' => 'https://files.example.com/pod.pdf'])->assertSessionHas('flash.message', 'L1 is delivered, 2 days late.');

        $fuel = $this->record($workspace, $app, 'trip_expenses', 'Fuel', 'claimed', ['load' => $l1->id, 'type' => 'fuel'], ['amount' => 300]);
        $this->actingAs($owner)->post($fuel->url().'/actions/approve')->assertSessionHas('flash.message', 'Fuel approved.');
        $this->actingAs($owner)->get($l1->url())->assertOk()->assertSee('Trip margin')->assertSee($this->money(700));
        $this->actingAs($owner)->post($l1->url().'/actions/advance')->assertSessionHas('flash.message', 'L1 is invoiced.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trip_expenses']), ['title' => 'Tolls', 'status' => 'claimed', 'amount' => 50, 'data' => ['load' => $l1->id, 'type' => 'tolls']])
            ->assertSessionHasErrors(['data.load' => 'L1 has been invoiced.']);
        $this->actingAs($owner)->get(route('apps.reports', $app).'?from='.today()->subMonth()->toDateString().'&to='.today()->toDateString())->assertOk()->assertSee('Lanes')->assertSee('Lusaka → Durban')->assertSee($this->money(700));
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
