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

/** Operations: fleet, minibus collections, equipment maintenance, visitors and compliance. */
class OperationsAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_fleet_tracks_fuel_economy_services_and_grounds_vehicles_with_lapsed_papers(): void
    {
        $app = 'fleet';
        [$owner, $workspace] = $this->appWorkspace($app);
        $vehicle = fn (string $title, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'vehicles']), ['title' => $title, 'status' => 'active', 'data' => ['make_model' => 'Toyota Hilux', ...$data]]);
        $vehicle(' ab 123 gp')->assertSessionHasNoErrors();
        $vehicle('AB-123-GP')->assertSessionHasErrors(['title' => 'AB 123 GP is already in the fleet.']);
        $vehicle('CD 456 GP', ['year' => 1900])->assertSessionHasErrors(['data.year' => 'Give a year between 1950 and '.(today()->year + 1).'.']);
        $hilux = Record::query()->where('entity', 'vehicles')->firstOrFail();
        $this->assertSame('AB 123 GP', $hilux->title);

        $fuel = fn (float $litres, float $odometer, int $daysAgo) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'fuel']), ['title' => 'Shell', 'status' => 'recorded', 'amount' => $litres * 20, 'occurs_on' => today()->subDays($daysAgo)->toDateString(), 'data' => ['vehicle' => $hilux->id, 'litres' => $litres, 'odometer' => $odometer]]);
        $fuel(0, 9000, 3)->assertSessionHasErrors(['data.litres' => 'Give the litres filled.']);
        $fuel(50, 10000, 2)->assertSessionHasNoErrors();
        $fuel(40, 10500, 1)->assertSessionHasNoErrors();
        $fuel(30, 10200, 0)->assertSessionHasErrors(['data.odometer' => 'AB 123 GP had already done 10,500 km by then.']);
        $second = Record::query()->where('entity', 'fuel')->orderByDesc('id')->firstOrFail();
        $this->assertEquals(500, $second->value('_km'));
        $this->assertEquals(12.5, $second->value('_km_per_litre'));
        $this->assertEquals(10500, $hilux->fresh()->value('odometer'));

        $this->actingAs($owner)->post($hilux->url().'/actions/workshop')->assertSessionHas('flash.message', 'AB 123 GP is in the workshop.');
        $service = $this->record($workspace, $app, 'services', 'Major service', 'booked', ['vehicle' => $hilux->id, 'workshop' => 'Toyota Sandton'], ['occurs_on' => today(), 'amount' => null]);
        $this->actingAs($owner)->post($service->url().'/actions/done', ['odometer' => 10400, 'cost' => 2500])->assertSessionHasErrors('odometer');
        $this->actingAs($owner)->post($service->url().'/actions/done', ['odometer' => 10600, 'cost' => 2500])
            ->assertSessionHas('flash.message', 'AB 123 GP serviced. Next service by '.today()->addMonthsNoOverflow(6)->format('d M Y').' or 25,600 km.');
        $hilux->refresh();
        $this->assertSame('active', $hilux->status);
        $this->assertEquals(25600, $hilux->value('_next_service_km'));

        $bakkie = $this->record($workspace, $app, 'vehicles', 'EF 789 GP', 'active', ['make_model' => 'Nissan NP200', 'insurance_expiry' => today()->subDay()->toDateString()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $bakkie->refresh();
        $this->assertSame('off_road', $bakkie->status);
        $this->assertSame('Insurance expired', $bakkie->value('_off_road_reason'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'vehicles', $bakkie->id]), ['title' => 'EF 789 GP', 'status' => 'active', 'data' => ['make_model' => 'Nissan NP200', 'insurance_expiry' => today()->subDay()->toDateString()]])
            ->assertSessionHasErrors(['status' => 'The insurance has expired; renew it before putting the vehicle back on the road.']);

        $this->actingAs($owner)->get($hilux->url())->assertOk()->assertSee('Kilometres per litre')->assertSee('12.50');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Coming due')->assertSee('EF 789 GP');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Running costs by vehicle')->assertSee($this->money(2500));
    }

    public function test_minibus_collections_set_cash_against_the_target_after_fuel(): void
    {
        $app = 'kombi-collections';
        [$owner] = $this->appWorkspace($app);
        $collection = fn (string $driver, string $status, string $vehicle, float $expected, float $fuel = 0, ?float $cash = null) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'collections']), ['title' => $driver, 'status' => $status, 'amount' => $cash, 'occurs_on' => today()->toDateString(), 'data' => ['vehicle' => $vehicle, 'route' => 'Town – Airport', 'expected' => $expected, 'fuel' => $fuel]]);

        $collection('John', 'pending', 'abc 123', 1500, 300)->assertSessionHasNoErrors();
        $collection('Mike', 'pending', 'ABC-123', 1500)->assertSessionHasErrors(['data.vehicle' => 'ABC 123 has already cashed up on '.today()->format('d M Y').' (John).']);
        $collection('Peter', 'received', 'XYZ 9', 0, 0, 500)->assertSessionHasErrors(['data.expected' => 'Set the day\'s target before cashing up.']);
        $collection('Peter', 'received', 'XYZ 9', 1000, 0, 800)->assertSessionHasNoErrors();
        [$john, $peter] = Record::query()->where('entity', 'collections')->orderBy('id')->get()->all();
        $this->assertSame('short', $peter->status);
        $this->assertEquals(200, $peter->value('_short'));

        $this->actingAs($owner)->post($john->url().'/actions/cash_up', ['cash' => 1000, 'fuel' => 300])
            ->assertSessionHas('flash.message', 'ABC 123: '.$this->money(1000).' handed in, '.$this->money(200).' short.');
        $this->assertSame('short', $john->fresh()->status);
        $this->actingAs($owner)->post($john->url().'/actions/repay', ['cash' => 300])->assertSessionHasErrors('cash');
        $this->actingAs($owner)->post($john->url().'/actions/repay', ['cash' => 200])
            ->assertSessionHas('flash.message', 'ABC 123: '.$this->money(1200).' handed in, all '.$this->money(1200).' collected.');
        $this->assertSame('received', $john->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Shortfalls owed')->assertSee('Peter');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Collections by driver')->assertSee('XYZ 9');
    }

    public function test_maintenance_tracks_breakdowns_and_raises_scheduled_services(): void
    {
        $app = 'maintenance';
        [$owner, $workspace] = $this->appWorkspace($app);
        $equipment = fn (string $title, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'equipment']), ['title' => $title, 'status' => 'operational', 'data' => ['location' => 'Workshop', ...$data]]);
        $equipment('Compressor', ['serial_number' => 'sn-1', 'service_interval_days' => 30])->assertSessionHasNoErrors();
        $equipment('Lathe', ['serial_number' => ' SN-1', 'service_interval_days' => 30])->assertSessionHasErrors(['data.serial_number' => 'Serial number SN-1 belongs to Compressor.']);
        $equipment('Lathe', ['service_interval_days' => 0])->assertSessionHasErrors(['data.service_interval_days' => 'The service interval is at least one day.']);
        $compressor = Record::query()->where('entity', 'equipment')->firstOrFail();
        $old = $this->record($workspace, $app, 'equipment', 'Old press', 'retired');

        $order = fn (string $title, Record $item, string $type) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'work_orders']), ['title' => $title, 'status' => 'open', 'data' => ['equipment' => $item->id, 'type' => $type]]);
        $order('Fix press', $old, 'breakdown')->assertSessionHasErrors(['data.equipment' => 'Old press is retired.']);
        $order('Belt snapped', $compressor, 'breakdown')->assertSessionHasNoErrors();
        $this->assertSame('faulty', $compressor->fresh()->status);
        $belt = Record::query()->where('entity', 'work_orders')->firstOrFail();
        $this->actingAs($owner)->post($belt->url().'/actions/complete', ['downtime_hours' => 4, 'cost' => 500, 'notes' => 'Replaced belt'])->assertSessionHas('flash.message', 'Belt snapped done.');
        $this->assertSame('operational', $compressor->fresh()->status);

        $order('Monthly check', $compressor, 'preventive')->assertSessionHasNoErrors();
        $check = Record::query()->where('entity', 'work_orders')->where('title', 'Monthly check')->firstOrFail();
        $this->actingAs($owner)->post($check->url().'/actions/complete', ['downtime_hours' => 1, 'cost' => 100])
            ->assertSessionHas('flash.message', 'Monthly check done. Next service for Compressor on '.today()->addDays(30)->format('d M Y').'.');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'work_orders', $check->id]), ['title' => 'Monthly check', 'status' => 'open', 'data' => ['equipment' => $compressor->id, 'type' => 'preventive']])
            ->assertSessionHasErrors(['status' => 'This work order is done; raise a new one.']);

        $generator = $this->record($workspace, $app, 'equipment', 'Generator', 'operational', ['service_interval_days' => 90], ['due_on' => today()->addDays(3)]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $scheduled = Record::query()->where('entity', 'work_orders')->where('title', 'Scheduled service: Generator')->get();
        $this->assertCount(1, $scheduled);
        $this->assertEquals($generator->id, $scheduled->first()->value('equipment'));
        $this->assertSame(0, Record::query()->where('entity', 'work_orders')->where('title', 'Scheduled service: Compressor')->count());

        $this->actingAs($owner)->get($compressor->url())->assertOk()->assertSee('Downtime')->assertSee('5.0 h');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Scheduled service: Generator');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Maintenance by equipment')->assertSee($this->money(600));
    }

    public function test_visitors_sign_in_once_and_are_signed_out_with_the_length_of_their_visit(): void
    {
        $app = 'visitors';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->travelTo(today()->setTime(11, 45));
        $visit = fn (string $title, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), ['title' => $title, 'status' => 'signed_in', 'occurs_on' => today()->toDateString(), 'data' => ['host' => $owner->id, 'company' => 'Acme', ...$data]]);

        $visit('Jane Doe', ['id_number' => '800101 5009 087', 'time_in' => '09:15'])->assertSessionHasNoErrors();
        $visit('Jane D', ['id_number' => '8001015009087'])->assertSessionHasErrors(['data.id_number' => 'Jane Doe with this ID is already signed in since 09:15.']);
        $visit('Sam', ['time_in' => '10:00', 'time_out' => '09:00'])->assertSessionHasErrors(['data.time_out' => 'The visitor cannot leave before they arrived.']);
        $visit('Sam', ['company' => ''])->assertSessionHasNoErrors();
        [$jane, $sam] = Record::query()->where('entity', 'visits')->orderBy('id')->get()->all();
        $this->assertSame('11:45', $sam->value('time_in'));

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('On site now (2)')->assertSee('Jane Doe');
        $this->actingAs($owner)->post($jane->url().'/actions/sign_out')->assertSessionHas('flash.message', 'Jane Doe signed out at 11:45 after 2 h 30 min.');
        $this->assertEquals(150, $jane->fresh()->value('_minutes'));

        $stayed = $this->record($workspace, $app, 'visits', 'Night guard', 'signed_in', ['time_in' => '18:00'], ['occurs_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $stayed->refresh();
        $this->assertSame('signed_out', $stayed->status);
        $this->assertTrue($stayed->value('_not_signed_out'));

        $this->actingAs($owner)->get($stayed->url())->assertOk()->assertSee('Never signed out');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Visits by host')->assertSee($owner->name)->assertSee('2 h 30 min');
    }

    public function test_compliance_follows_expiry_dates_and_closes_findings_with_their_fix(): void
    {
        $app = 'compliance';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'obligations']), ['title' => 'Trading licence', 'status' => 'compliant', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(), 'data' => []])
            ->assertSessionHasErrors(['due_on' => 'It cannot expire before it was issued.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'obligations']), ['title' => 'Trading licence', 'status' => 'compliant', 'amount' => 300, 'occurs_on' => today()->subYear()->toDateString(), 'due_on' => today()->addDays(10)->toDateString(), 'data' => ['authority' => 'City council', 'reference' => 'TL-1']])
            ->assertSessionHasNoErrors();
        $licence = Record::query()->where('entity', 'obligations')->firstOrFail();
        $this->assertSame('due_soon', $licence->status);

        $this->actingAs($owner)->post($licence->url().'/actions/renew', ['expires_on' => today()->subDay()->toDateString()])->assertSessionHasErrors('expires_on');
        $renewed = today()->addYear();
        $this->actingAs($owner)->post($licence->url().'/actions/renew', ['expires_on' => $renewed->toDateString(), 'fee' => 350, 'reference' => 'TL-2'])
            ->assertSessionHas('flash.message', 'Trading licence renewed until '.$renewed->format('d M Y').'.');
        $licence->refresh();
        $this->assertSame('compliant', $licence->status);
        $this->assertSame('TL-2', $licence->value('reference'));
        $this->assertEquals(350, $licence->value('_fees_paid'));

        $fire = $this->record($workspace, $app, 'obligations', 'Fire certificate', 'compliant', ['authority' => 'Fire department'], ['occurs_on' => today()->subYear(), 'due_on' => today()->addMonths(3)]);
        Record::query()->whereKey($fire->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('lapsed', $fire->fresh()->status);

        $finding = fn (string $status, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'findings']), ['title' => 'Missing extinguishers', 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['obligation' => $fire->id, 'severity' => 'critical', ...$data]]);
        $finding('closed', [])->assertSessionHasErrors(['data.corrective_action' => 'Write down the corrective action before closing the finding.']);
        $finding('open', [])->assertSessionHasNoErrors();
        $missing = Record::query()->where('entity', 'findings')->firstOrFail();
        $this->assertTrue($missing->due_on->isSameDay(today()->addDays(7)));
        $this->actingAs($owner)->post($missing->url().'/actions/close', ['corrective_action' => ''])->assertSessionHasErrors('corrective_action');
        $this->actingAs($owner)->post($missing->url().'/actions/close', ['corrective_action' => 'Fitted four extinguishers'])->assertSessionHas('flash.message', 'Missing extinguishers closed after 0 days.');

        $this->actingAs($owner)->get($fire->url())->assertOk()->assertSee('Lapsed 1 day ago');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Renewals')->assertSee('Fire certificate');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Findings by severity')->assertSee('Critical')->assertSee('TL-2');
    }

    private function money(float $amount): string
    {
        return Money::format($amount);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     */
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
