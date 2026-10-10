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

/** Utilities: utility billing, ISP billing, solar PAYG and refuse collection. */
class UtilitiesAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_readings_follow_the_last_reading_and_post_paid_meters_are_billed_at_the_tariff(): void
    {
        $app = 'utility-billing';
        [$owner, $workspace] = $this->appWorkspace($app);
        $meter = $this->record($workspace, $app, 'meters', 'W-100', 'active', ['type' => 'water', 'account_holder' => 'Banda', 'tariff' => 'K2.50 per unit']);
        $this->record($workspace, $app, 'meters', 'E-200', 'active', ['type' => 'electricity', 'account_holder' => 'Mwale']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'meters']), ['title' => 'w-100', 'status' => 'active', 'data' => ['type' => 'water', 'account_holder' => 'Zulu']])
            ->assertSessionHasErrors(['title' => 'Meter W-100 is already registered.']);

        $august = $this->record($workspace, $app, 'readings', 'August', 'billed', ['meter' => $meter->id, 'current' => 100], ['occurs_on' => today()->subMonths(2)]);
        $this->record($workspace, $app, 'readings', 'September', 'billed', ['meter' => $meter->id, 'current' => 150], ['occurs_on' => today()->subMonth()]);
        $this->assertEquals(100, $august->fresh()->value('consumption'));
        $this->assertEquals(250, $august->fresh()->amount);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'readings']), ['title' => 'October', 'status' => 'read', 'data' => ['meter' => $meter->id, 'current' => 120]])
            ->assertSessionHasErrors(['data.current' => 'The reading is below the previous reading of 150.']);

        $october = $this->record($workspace, $app, 'readings', 'October', 'read', ['meter' => $meter->id, 'current' => 600], ['occurs_on' => today()]);
        $this->assertEquals(150, $october->value('previous'));
        $this->assertTrue((bool) $october->value('_high_usage'));
        $this->assertSame(today()->addDays(14)->toDateString(), $october->due_on->toDateString());
        $this->actingAs($owner)->post($october->url().'/actions/bill')->assertSessionHas('flash.message', 'October billed for 450 units, '.$this->money(1125).'.');
        $this->actingAs($owner)->post($october->url().'/actions/dispute')->assertSessionHas('flash.message', 'October\'s bill is disputed.');
        $this->actingAs($owner)->post($october->url().'/actions/bill', ['current' => 100])->assertSessionHasErrors('current');
        $this->actingAs($owner)->post($october->url().'/actions/bill', ['current' => 300])->assertSessionHas('flash.message', 'October billed for 150 units, '.$this->money(375).'.');
        $this->assertFalse((bool) $october->fresh()->value('_high_usage'));

        $this->actingAs($owner)->post($meter->url().'/actions/disconnect')->assertSessionHas('flash.message', 'Meter W-100 disconnected.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'readings']), ['title' => 'November', 'status' => 'read', 'data' => ['meter' => $meter->id, 'current' => 700]])
            ->assertSessionHasErrors(['data.meter' => 'Meter W-100 is disconnected.']);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Not read this month')->assertSee('E-200');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Consumption by type')->assertSee('Water');
    }

    public function test_subscribers_get_unique_logins_and_are_suspended_after_the_grace_period(): void
    {
        $app = 'isp-billing';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'subscribers']), ['title' => 'New', 'status' => 'active', 'data' => ['package' => '10mbps', 'ip_address' => '300.1.1.1']])
            ->assertSessionHasErrors(['data.username' => 'Give the PPPoE username.', 'data.ip_address' => 'This isn\'t a valid IP address.']);
        $ann = $this->record($workspace, $app, 'subscribers', 'Ann', 'active', ['package' => '20mbps', 'username' => 'Ann', 'ip_address' => '10.0.0.5'], ['amount' => 500, 'occurs_on' => today()->subMonths(2)]);
        $this->assertSame('ann', $ann->fresh()->value('username'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'subscribers']), ['title' => 'Copy', 'status' => 'pending_install', 'data' => ['package' => '10mbps', 'username' => 'ANN', 'ip_address' => '10.0.0.5']])
            ->assertSessionHasErrors(['data.username' => 'This username is already used by Ann.', 'data.ip_address' => 'This IP address is already used by Ann.']);

        $bob = $this->record($workspace, $app, 'subscribers', 'Bob', 'pending_install', ['package' => '10mbps'], ['amount' => 300]);
        $this->actingAs($owner)->post($bob->url().'/actions/activate', ['username' => 'ann'])->assertSessionHasErrors(['username' => 'This username is already used by Ann.']);
        $this->actingAs($owner)->post($bob->url().'/actions/activate', ['username' => 'bob'])
            ->assertSessionHas('flash.message', 'Bob is connected, paid until '.today()->addMonthNoOverflow()->format('d M Y').'.');

        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('suspended', $ann->fresh()->status);
        $this->assertSame('active', $bob->fresh()->status);
        $this->actingAs($owner)->post($ann->url().'/actions/pay', ['months' => 2])
            ->assertSessionHas('flash.message', 'Ann paid until '.today()->addMonthsNoOverflow(2)->format('d M Y').'.');
        $this->assertSame('active', $ann->fresh()->status);

        $fault = $this->record($workspace, $app, 'faults', 'No signal', 'logged', ['subscriber' => $ann->id, 'type' => 'no_connection']);
        $this->actingAs($owner)->post($fault->url().'/actions/investigate')->assertSessionHas('flash.message', 'No signal is being investigated.');
        $this->actingAs($owner)->post($fault->url().'/actions/send', ['technician' => $owner->id])->assertSessionHas('flash.message', 'Technician sent for No signal.');
        $this->assertSame($owner->id, $fault->fresh()->assignee_id);
        $this->actingAs($owner)->post($fault->url().'/actions/resolve')->assertSessionHas('flash.message', 'No signal resolved.');
        $this->assertSame(today()->toDateString(), $fault->fresh()->value('_resolved_on'));

        $this->actingAs($owner)->post($bob->url().'/actions/cancel')->assertSessionHas('flash.message', 'Bob\'s service cancelled.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'faults']), ['title' => 'Slow', 'status' => 'logged', 'data' => ['subscriber' => $bob->id, 'type' => 'slow']])
            ->assertSessionHasErrors(['data.subscriber' => 'Bob has cancelled.']);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Network')->assertSee($this->money(500));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Active subscribers by package')->assertSee('No connection');
    }

    public function test_payg_payments_buy_days_and_lapsed_systems_lock_then_can_be_repossessed(): void
    {
        $app = 'solar-energy-systems-monitoring';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'systems']), ['title' => 'New', 'status' => 'active', 'amount' => 1000, 'data' => ['kit' => '200W home', 'payg' => '1', 'balance' => 2000]])
            ->assertSessionHasErrors(['data.daily_rate' => 'Give the daily rate for a pay-as-you-go system.', 'data.balance' => 'The balance is more than the contract value.']);
        $phiri = $this->record($workspace, $app, 'systems', 'Phiri', 'active', ['kit' => '200W home', 'serial_number' => 'ctl-1', 'payg' => true, 'daily_rate' => 10, 'balance' => 500], ['amount' => 1000, 'due_on' => today()->subDay()]);
        $this->assertSame('CTL-1', $phiri->fresh()->value('serial_number'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'systems']), ['title' => 'Copy', 'status' => 'active', 'data' => ['kit' => '100W', 'serial_number' => 'ctl-1']])
            ->assertSessionHasErrors(['data.serial_number' => 'This controller is already on Phiri.']);

        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('locked', $phiri->fresh()->status);
        $this->assertSame(today()->toDateString(), $phiri->fresh()->value('_locked_on'));

        $this->actingAs($owner)->post($phiri->url().'/actions/pay', ['amount' => 100])
            ->assertSessionHas('flash.message', 'Phiri paid '.$this->money(100).' for 10 days, code '.$phiri->fresh()->value('unlock_code').'.');
        $phiri = $phiri->fresh();
        $this->assertSame('active', $phiri->status);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $phiri->value('unlock_code'));
        $this->assertSame(today()->addDays(10)->toDateString(), $phiri->due_on->toDateString());
        $this->assertEquals(400, $phiri->value('balance'));
        $this->assertNull($phiri->value('_locked_on'));

        $old = $this->record($workspace, $app, 'systems', 'Old', 'locked', ['kit' => '100W', 'payg' => true, 'daily_rate' => 5, 'balance' => 300, '_locked_on' => today()->subDays(31)->toDateString()], ['amount' => 600]);
        $this->actingAs($owner)->post($old->url().'/actions/repossess')->assertSessionHas('flash.message', 'Old\'s system repossessed.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'readings']), ['title' => 'Old', 'status' => 'logged', 'data' => ['system' => $old->id, 'battery_health' => 120]])
            ->assertSessionHasErrors(['data.system' => 'Old\'s system has been repossessed.', 'data.battery_health' => 'Battery health is a percentage from 0 to 100.']);

        $reading = $this->record($workspace, $app, 'readings', 'x', 'logged', ['system' => $phiri->id, 'kwh' => 4.5, 'battery_health' => 50]);
        $this->assertSame('Phiri', $reading->fresh()->title);
        $this->assertSame('Battery health low', $reading->fresh()->value('alerts'));

        $this->actingAs($owner)->post($phiri->url().'/actions/pay', ['amount' => 400])->assertSessionHas('flash.message', 'Phiri has paid off the system; it is unlocked for good.');
        $this->assertSame('paid_off', $phiri->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Portfolio')->assertSee('Locked systems');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Portfolio by kit')->assertSee('50%');
    }

    public function test_runs_are_scheduled_per_route_each_morning_and_completed_with_a_landfill_ticket(): void
    {
        $app = 'waste-management-refuse-collection';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'customers']), ['title' => 'Empty', 'status' => 'active', 'data' => ['address' => 'Plot 1', 'bins' => 0]])
            ->assertSessionHasErrors(['data.bins' => 'An active collection point needs at least one bin.', 'data.collection_day' => 'Give the collection day.', 'data.route' => 'Give the route.']);
        $today = strtolower(today()->englishDayOfWeek);
        $first = $this->record($workspace, $app, 'customers', 'Plot 2', 'active', ['address' => 'Plot 2', 'bins' => 2, 'collection_day' => $today, 'route' => 'North'], ['amount' => 100]);
        $this->record($workspace, $app, 'customers', 'Plot 3', 'active', ['address' => 'Plot 3', 'bins' => 3, 'collection_day' => $today, 'route' => ' north ']);
        $this->record($workspace, $app, 'customers', 'Plot 4', 'suspended', ['address' => 'Plot 4', 'bins' => 1, 'collection_day' => $today, 'route' => 'East']);
        $this->record($workspace, $app, 'customers', 'Plot 5', 'active', ['address' => 'Plot 5', 'bins' => 1, 'collection_day' => strtolower(today()->addDay()->englishDayOfWeek), 'route' => 'South']);
        $late = $this->record($workspace, $app, 'collections', 'South', 'scheduled', [], ['occurs_on' => today()->subDay()]);

        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('missed', $late->fresh()->status);
        $this->assertSame('Not run on the day.', $late->fresh()->value('missed_stops'));
        $run = Record::query()->where('entity', 'collections')->whereDate('occurs_on', today())->sole();
        $this->assertSame('North', $run->title);
        $this->assertEquals(2, $run->value('_stops_planned'));
        $this->assertEquals(5, $run->value('_bins_planned'));

        $this->actingAs($owner)->post($run->url().'/actions/start')->assertSessionHas('flash.message', 'North run started with 2 stops.');
        $this->actingAs($owner)->post($run->url().'/actions/complete', ['stops_done' => 2, 'tonnage' => 3.5])->assertSessionHasErrors(['landfill_ticket' => 'Give the landfill or weighbridge ticket for the tonnage.']);
        $this->actingAs($owner)->post($run->url().'/actions/complete', ['stops_done' => 2, 'tonnage' => 3.5, 'landfill_ticket' => 'WB-77'])->assertSessionHas('flash.message', 'North completed: 2 of 2 stops, 3.5 t.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'collections']), ['title' => 'East', 'status' => 'missed', 'data' => []])
            ->assertSessionHasErrors(['data.missed_stops' => 'Say why the run was missed.']);
        $this->actingAs($owner)->post($first->url().'/actions/suspend')->assertSessionHas('flash.message', 'Plot 2 suspended.');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Today\'s runs')->assertSee('North');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Tonnage by route')->assertSee('3.5');
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
