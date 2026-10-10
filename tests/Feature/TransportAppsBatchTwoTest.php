<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Transport: airport transfers, parking and toll shifts, driving school and GPS tracking. */
class TransportAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_transfers_need_flight_details_fit_the_vehicle_and_keep_drivers_two_hours_apart(): void
    {
        $app = 'airport-shuttles-chauffeur-services';
        [$owner, $workspace] = $this->appWorkspace($app);
        $other = User::factory()->create(['name' => 'Zed Driver']);
        $transfer = fn (array $data, string $status = 'booked') => ['title' => 'Guest', 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'point_to_point', 'pickup_time' => '10:00', 'pickup' => 'Hotel', 'dropoff' => 'Office', ...$data]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transfers']), $transfer(['type' => 'airport_pickup']))
            ->assertSessionHasErrors(['data.flight_number' => 'Give the flight number for an airport pick-up.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transfers']), $transfer(['vehicle_class' => 'sedan', 'passengers' => 5]))
            ->assertSessionHasErrors(['data.passengers' => 'A sedan carries 3 passengers at most.']);

        $ann = $this->record($workspace, $app, 'transfers', 'Ann', 'assigned', ['type' => 'airport_pickup', 'flight_number' => 'qf 12', 'pickup_time' => '09:00', 'pickup' => 'Airport', 'dropoff' => 'Hotel', 'driver' => $owner->id], ['occurs_on' => today(), 'amount' => 300]);
        $this->assertSame('QF12', $ann->value('flight_number'));
        $this->assertSame('Ann', $ann->value('name_board'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transfers']), $transfer(['driver' => $owner->id], 'assigned'))
            ->assertSessionHasErrors(['data.driver' => 'The driver has Ann at 09:00.']);

        $bob = $this->record($workspace, $app, 'transfers', 'Bob', 'booked', ['type' => 'point_to_point', 'pickup_time' => '10:30', 'pickup' => 'Mall', 'dropoff' => 'Office'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($bob->url().'/actions/assign', ['driver' => $owner->id])->assertSessionHasErrors(['driver' => 'The driver has Ann at 09:00.']);
        $this->actingAs($owner)->post($bob->url().'/actions/assign', ['driver' => $other->id])->assertSessionHas('flash.message', 'Bob\'s transfer assigned to Zed Driver.');

        $this->actingAs($owner)->post($ann->url().'/actions/on_route')->assertSessionHas('flash.message', 'Driver on route to Airport.');
        $this->actingAs($owner)->post($ann->url().'/actions/complete')->assertSessionHas('flash.message', 'Ann\'s transfer completed.');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Today\'s transfers')->assertSee('Bob')->assertSee('Zed Driver');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Transfers by type')->assertSee('Airport pickup')->assertSee($this->money(300));
    }

    public function test_cashier_shifts_are_reconciled_against_expected_takings(): void
    {
        $app = 'parking-toll-management';
        [$owner, $workspace] = $this->appWorkspace($app);
        $plaza = $this->record($workspace, $app, 'sites', 'Plaza', 'open', ['type' => 'toll_plaza', 'bays' => 4]);
        $old = $this->record($workspace, $app, 'sites', 'Old lot', 'closed', ['type' => 'parking_lot']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shifts']), ['title' => 'Kim', 'status' => 'open', 'data' => ['site' => $old->id]])
            ->assertSessionHasErrors(['data.site' => 'Old lot is closed.']);

        $mary = $this->record($workspace, $app, 'shifts', 'Mary', 'open', ['site' => $plaza->id, 'expected' => 1000], ['assignee_id' => $owner->id, 'occurs_on' => today()]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shifts']), ['title' => 'Mary again', 'status' => 'open', 'assignee_id' => $owner->id, 'data' => ['site' => $plaza->id]])
            ->assertSessionHasErrors(['assignee_id' => 'This cashier already has Mary open.']);
        $this->actingAs($owner)->post($mary->url().'/actions/close', ['vehicles' => 120, 'cash' => 900, 'card' => 50])
            ->assertSessionHas('flash.message', 'Mary\'s shift closed with '.$this->money(950).', '.$this->money(50).' short.');
        $this->assertSame('short', $mary->fresh()->status);

        $john = $this->record($workspace, $app, 'shifts', 'John', 'open', ['site' => $plaza->id, 'expected' => 500], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($john->url().'/actions/close', ['cash' => 520])
            ->assertSessionHas('flash.message', 'John\'s shift closed with '.$this->money(520).', '.$this->money(20).' over.');
        $this->assertEquals(20, $john->fresh()->value('_variance'));

        $this->actingAs($owner)->get($plaza->url())->assertOk()->assertSee('Short shifts')->assertSee($this->money(1470));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Takings by site')->assertSee('Differences by cashier')->assertSee($this->money(-50));
        $this->actingAs($owner)->post($plaza->url().'/actions/close_site')->assertSessionHas('flash.message', 'Plaza closed.');
    }

    public function test_lessons_use_up_the_package_and_tests_move_the_learner_along(): void
    {
        $app = 'driving-school-16-9';
        [$owner, $workspace] = $this->appWorkspace($app);
        $lina = $this->record($workspace, $app, 'learners', 'Lina', 'enrolled', ['phone' => '0977111111', 'licence_code' => 'b', 'learners_licence_expiry' => today()->addDays(60)->toDateString(), 'lessons_remaining' => 2]);
        $expired = $this->record($workspace, $app, 'learners', 'Eli', 'learning', ['phone' => '0977222222', 'licence_code' => 'b', 'learners_licence_expiry' => today()->subDay()->toDateString(), 'lessons_remaining' => 5]);
        $lesson = fn (Record $learner, array $data = []) => ['title' => $learner->title, 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['learner' => $learner->id, 'start_time' => '09:30', ...$data]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), $lesson($expired))
            ->assertSessionHasErrors(['data.learner' => 'Eli\'s learner licence expires on '.today()->subDay()->format('d M Y').'.']);

        $first = $this->record($workspace, $app, 'lessons', 'Lina', 'booked', ['learner' => $lina->id, 'start_time' => '09:00', 'instructor' => $owner->id], ['occurs_on' => today()]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), $lesson($lina, ['instructor' => $owner->id]))
            ->assertSessionHasErrors(['data.instructor' => 'The instructor has Lina at 09:00.']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Today\'s lessons')->assertSee('Two lessons or fewer left');
        $this->actingAs($owner)->post($first->url().'/actions/complete', ['feedback' => 'Good clutch control'])->assertSessionHas('flash.message', 'Lina\'s lesson completed, 1 lesson left.');
        $this->assertSame('learning', $lina->fresh()->status);

        $yesterday = $this->record($workspace, $app, 'lessons', 'Lina', 'booked', ['learner' => $lina->id, 'start_time' => '14:00'], ['occurs_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('missed', $yesterday->fresh()->status);
        $last = $this->record($workspace, $app, 'lessons', 'Lina', 'booked', ['learner' => $lina->id, 'start_time' => '15:00'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($last->url().'/actions/complete')->assertSessionHas('flash.message', 'Lina\'s lesson completed, 0 lessons left.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), $lesson($lina))
            ->assertSessionHasErrors(['data.learner' => 'Lina has no lessons left on the package.']);

        $test = $this->record($workspace, $app, 'tests', 'Lina', 'booked', ['learner' => $lina->id, 'testing_centre' => 'Lusaka RTSA'], ['occurs_on' => today()->addDays(3)]);
        $this->assertSame('test_booked', $lina->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tests']), ['title' => 'Lina', 'status' => 'booked', 'occurs_on' => today()->addDays(9)->toDateString(), 'data' => ['learner' => $lina->id]])
            ->assertSessionHasErrors(['data.learner' => 'Lina already has a test booked on '.today()->addDays(3)->format('d M Y').'.']);
        $this->actingAs($owner)->post($test->url().'/actions/fail')->assertSessionHas('flash.message', 'Lina failed the driving test.');
        $this->assertSame('learning', $lina->fresh()->status);
        $retest = $this->record($workspace, $app, 'tests', 'Lina', 'booked', ['learner' => $lina->id, 'testing_centre' => 'Lusaka RTSA'], ['occurs_on' => today()->addDays(3)]);
        $this->actingAs($owner)->post($retest->url().'/actions/pass')->assertSessionHas('flash.message', 'Lina passed the driving test.');
        $this->assertSame('passed', $lina->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), $lesson($lina))->assertSessionHasErrors(['data.learner' => 'Lina has passed.']);

        $this->actingAs($owner)->get($lina->url())->assertOk()->assertSee('Lessons taken')->assertSee('Test attempts');
        $this->actingAs($owner)->get(route('apps.reports', $app).'?from='.today()->toDateString().'&to='.today()->addDays(5)->toDateString())->assertOk()->assertSee('Pass rate by testing centre')->assertSee('Lusaka RTSA')->assertSee('50%');
    }

    public function test_speeding_trips_raise_alerts_and_lapsed_trackers_go_offline(): void
    {
        $app = 'gps-tracking';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trackers']), ['title' => 'Van', 'status' => 'online', 'data' => ['imei' => '12345']])
            ->assertSessionHasErrors(['data.imei' => 'An IMEI has 15 digits.']);
        $truck = $this->record($workspace, $app, 'trackers', 'Truck 1', 'online', ['imei' => '356938035643809', 'subscription_until' => today()->subDay()->toDateString()]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trackers']), ['title' => 'Van', 'status' => 'online', 'data' => ['imei' => '356 938 035 643 809']])
            ->assertSessionHasErrors(['data.imei' => 'This IMEI is already on Truck 1.']);
        $removed = $this->record($workspace, $app, 'trackers', 'Old van', 'removed', ['imei' => '356938035643810']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trips']), ['title' => 'Old van', 'status' => 'logged', 'data' => ['tracker' => $removed->id]])
            ->assertSessionHasErrors(['data.tracker' => 'The tracker on Old van has been removed.']);

        $trip = $this->record($workspace, $app, 'trips', 'Trip', 'logged', ['tracker' => $truck->id, 'from' => 'Lusaka', 'to' => 'Kabwe', 'distance' => 220, 'max_speed' => 135, 'idle_minutes' => 30], ['occurs_on' => today()]);
        $this->assertSame('Truck 1', $trip->title);
        $alert = Record::query()->where('entity', 'alerts')->sole();
        $this->assertSame(['Speeding: Truck 1', 'new', 'speeding'], [$alert->title, $alert->status, $alert->value('type')]);
        $trip->fresh()->update(['status' => 'reviewed']);
        $this->assertSame(1, Record::query()->where('entity', 'alerts')->count());
        $this->record($workspace, $app, 'trips', 'Trip', 'logged', ['tracker' => $truck->id, 'distance' => 30, 'max_speed' => 80], ['occurs_on' => today()]);
        $this->assertSame(1, Record::query()->where('entity', 'alerts')->count());

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'alerts']), ['title' => 'Panic', 'status' => 'acknowledged', 'data' => ['tracker' => $truck->id, 'type' => 'panic']])
            ->assertSessionHasErrors(['assignee_id' => 'Say who acknowledged the alert.']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Open alerts')->assertSee('Speeding: Truck 1');
        $this->actingAs($owner)->post($alert->url().'/actions/acknowledge')->assertSessionHas('flash.message', 'Speeding: Truck 1 acknowledged.');
        $this->assertSame($owner->id, $alert->fresh()->assignee_id);
        $this->actingAs($owner)->post($alert->url().'/actions/close')->assertSessionHas('flash.message', 'Speeding: Truck 1 closed.');

        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('offline', $truck->fresh()->status);
        $this->actingAs($owner)->get($truck->url())->assertOk()->assertSee('Last 30 days')->assertSee('250 km')->assertSee('135 km/h');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Distance by vehicle')->assertSee('Truck 1')->assertSee('Speeding');
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
