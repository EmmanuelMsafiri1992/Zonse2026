<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Contacts\Models\Contact;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The farming apps' rules: crops, livestock, poultry, dairy collection, cooperatives, produce sales, fish farming and greenhouse irrigation. */
class AgricultureAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @param  list<string>  $extra  @return array{0: User, 1: Workspace} */
    protected function appWorkspace(string $app, array $extra = []): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app, ...$extra], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /** @param  array<string, mixed>  $data  @param  array<string, mixed>  $attributes */
    protected function record(Workspace $workspace, string $app, string $entity, string $title, string $status, array $data = [], array $attributes = []): Record
    {
        return Record::factory()->ofEntity($app, $entity, $data)->create(['workspace_id' => $workspace->id, 'title' => $title, 'status' => $status, ...$attributes]);
    }

    protected function latest(string $app, string $entity): Record
    {
        return Record::query()->ofEntity($app, $entity)->latest('id')->firstOrFail();
    }

    public function test_farm_keeps_plantings_inside_their_field_and_adds_up_costs_and_yield(): void
    {
        [$owner, $workspace] = $this->appWorkspace('farm');
        $north = $this->record($workspace, 'farm', 'fields', 'North', 'active', ['size_ha' => 10]);
        $south = $this->record($workspace, 'farm', 'fields', 'South', 'fallow', ['size_ha' => 5]);
        $maize = $this->record($workspace, 'farm', 'plantings', 'Maize', 'planted', ['field' => $north->id, 'area_ha' => 6, 'expected_yield' => 30]);

        $planting = ['title' => 'Soya', 'status' => 'planned', 'data' => ['field' => $north->id, 'area_ha' => 5]];
        $this->actingAs($owner)->post(route('apps.records.store', ['farm', 'plantings']), $planting)->assertSessionHasErrors('data.area_ha');
        $this->actingAs($owner)->post(route('apps.records.store', ['farm', 'plantings']), [...$planting, 'data' => ['field' => $south->id, 'area_ha' => 2]])->assertSessionHasErrors('data.field');
        $this->actingAs($owner)->post(route('apps.records.store', ['farm', 'plantings']), [...$planting, 'data' => ['field' => $north->id, 'area_ha' => 4]])->assertSessionHasNoErrors();

        $this->record($workspace, 'farm', 'activities', 'Top dressing', 'done', ['planting' => $maize->id, 'type' => 'fertilising'], ['amount' => 500]);
        $this->record($workspace, 'farm', 'activities', 'Weeding', 'planned', ['planting' => $maize->id, 'type' => 'weeding'], ['amount' => 200, 'occurs_on' => today()]);
        $this->assertSame(500.0, (float) $maize->fresh()->value('_cost'));

        $this->record($workspace, 'farm', 'harvests', 'Lot 1', 'in_store', ['planting' => $maize->id, 'quantity' => 12, 'unit' => 'tonnes'], ['amount' => 3000]);
        $maize = $maize->fresh();
        $this->assertSame('harvested', $maize->status);
        $this->assertEquals(12, $maize->value('_tonnes'));
        $this->assertEquals(2, $maize->value('_yield_per_ha'));
        $this->assertEquals(2500, $maize->value('_margin'));

        $this->actingAs($owner)->get(route('apps.show', 'farm'))->assertOk()->assertSee('Work this week')->assertSee('Weeding');
        $this->actingAs($owner)->get($maize->url())->assertOk()->assertSee('Crop result')->assertSee('2 t/ha');
        $this->actingAs($owner)->get($north->url())->assertOk()->assertSee('Field use');
        $this->actingAs($owner)->get(route('apps.reports', 'farm'))->assertOk()->assertSee('Crop profitability')->assertSee('Maize');
    }

    public function test_livestock_register_checks_tags_and_holds_animals_under_withdrawal(): void
    {
        [$owner, $workspace] = $this->appWorkspace('livestock');
        $cow = $this->record($workspace, 'livestock', 'animals', 'TAG-1', 'alive', ['species' => 'cattle', 'sex' => 'female']);
        $this->record($workspace, 'livestock', 'animals', 'TAG-2', 'alive', ['species' => 'cattle', 'sex' => 'male']);
        $dead = $this->record($workspace, 'livestock', 'animals', 'TAG-9', 'died', ['species' => 'goat']);

        $this->actingAs($owner)->post(route('apps.records.store', ['livestock', 'animals']), [
            'title' => 'TAG-1', 'status' => 'alive', 'data' => ['species' => 'cattle', 'dam_tag' => 'NOPE'],
        ])->assertSessionHasErrors(['title', 'data.dam_tag']);
        $this->actingAs($owner)->post(route('apps.records.store', ['livestock', 'animals']), [
            'title' => 'TAG-3', 'status' => 'alive', 'data' => ['species' => 'cattle', 'dam_tag' => 'TAG-2'],
        ])->assertSessionHasErrors('data.dam_tag');
        $this->actingAs($owner)->post(route('apps.records.store', ['livestock', 'animals']), [
            'title' => 'TAG-3', 'status' => 'alive', 'data' => ['species' => 'cattle', 'dam_tag' => 'TAG-1'],
        ])->assertSessionHasNoErrors();

        $this->record($workspace, 'livestock', 'treatments', 'Antibiotic', 'given', ['animal' => $cow->id, 'withdrawal_days' => 10], ['occurs_on' => today()->subDays(2), 'amount' => 15]);
        $this->record($workspace, 'livestock', 'treatments', 'Dip', 'scheduled', ['animal' => $cow->id], ['due_on' => today()->addDays(3)]);
        $this->assertSame(today()->addDays(8)->toDateString(), $cow->fresh()->value('_withdrawal_until'));
        $this->actingAs($owner)->put(route('apps.records.update', ['livestock', 'animals', $cow->id]), [
            'title' => 'TAG-1', 'status' => 'sold', 'data' => ['species' => 'cattle', 'sex' => 'female'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.store', ['livestock', 'treatments']), [
            'title' => 'Vaccine', 'status' => 'given', 'data' => ['animal' => $dead->id],
        ])->assertSessionHasErrors('data.animal');

        $this->record($workspace, 'livestock', 'weighings', 'Monthly', 'recorded', ['animal' => $cow->id, 'weight_kg' => 200], ['occurs_on' => today()->subDays(10)]);
        $second = $this->record($workspace, 'livestock', 'weighings', 'Monthly', 'recorded', ['animal' => $cow->id, 'weight_kg' => 210], ['occurs_on' => today()]);
        $this->assertEquals(1, $second->value('_daily_gain'));
        $this->assertEquals(210, $cow->fresh()->value('_weight'));

        $this->actingAs($owner)->get(route('apps.show', 'livestock'))->assertOk()->assertSee('Treatments due')->assertSee('Dip');
        $this->actingAs($owner)->get($cow->fresh()->url())->assertOk()->assertSee('Withdrawal')->assertSee('1000 g/day');
        $this->actingAs($owner)->get(route('apps.reports', 'livestock'))->assertOk()->assertSee('Herd on hand')->assertSee('Weight gain');
    }

    public function test_poultry_counts_live_birds_laying_rate_and_flags_mortality(): void
    {
        [$owner, $workspace] = $this->appWorkspace('poultry');
        $flock = $this->record($workspace, 'poultry', 'flocks', 'House A', 'active', ['type' => 'layers', 'birds_placed' => 1000], ['occurs_on' => today()->subDays(120)]);
        $closed = $this->record($workspace, 'poultry', 'flocks', 'House B', 'closed', ['type' => 'broilers', 'birds_placed' => 500]);

        $yesterday = $this->record($workspace, 'poultry', 'daily_records', 'Day', 'recorded', ['flock' => $flock->id, 'eggs_collected' => 796, 'feed_kg' => 110, 'mortality' => 5], ['occurs_on' => today()->subDay()]);
        $this->assertEquals(79.6, $yesterday->value('_laying_rate'));
        $this->assertEquals(995, $flock->fresh()->value('_alive'));

        $day = ['title' => 'Day', 'status' => 'recorded', 'occurs_on' => today()->subDay()->toDateString(), 'data' => ['flock' => $flock->id, 'mortality' => 1]];
        $this->actingAs($owner)->post(route('apps.records.store', ['poultry', 'daily_records']), $day)->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', ['poultry', 'daily_records']), [...$day, 'occurs_on' => today()->toDateString(), 'data' => ['flock' => $flock->id, 'mortality' => 2000]])->assertSessionHasErrors('data.mortality');
        $this->actingAs($owner)->post(route('apps.records.store', ['poultry', 'daily_records']), [...$day, 'data' => ['flock' => $closed->id]])->assertSessionHasErrors('data.flock');

        $this->record($workspace, 'poultry', 'daily_records', 'Day', 'recorded', ['flock' => $flock->id, 'eggs_collected' => 700, 'feed_kg' => 108, 'mortality' => 20], ['occurs_on' => today()]);
        $flock = $flock->fresh();
        $this->assertEquals(975, $flock->value('_alive'));
        $this->assertEquals(1496, $flock->value('_eggs'));
        $this->assertEquals(2.5, $flock->value('_mortality_percent'));

        $this->actingAs($owner)->get(route('apps.show', 'poultry'))->assertOk()->assertSee('High mortality in House A')->assertSee('975 birds');
        $this->actingAs($owner)->get($flock->url())->assertOk()->assertSee('Laying rate');
        $this->actingAs($owner)->get(route('apps.reports', 'poultry'))->assertOk()->assertSee('Production by month')->assertSee('House A');
    }

    public function test_dairy_prices_deliveries_and_issues_a_monthly_payout(): void
    {
        [$owner, $workspace] = $this->appWorkspace('dairy');
        $farmer = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Tendai Moyo']);
        $attributes = ['contact_id' => $farmer->id, 'occurs_on' => today()];

        $this->record($workspace, 'dairy', 'deliveries', 'Tendai Moyo', 'accepted', ['session' => 'morning', 'litres' => 20, 'fat_percent' => 4], [...$attributes, 'amount' => 10]);
        $evening = $this->record($workspace, 'dairy', 'deliveries', 'Tendai Moyo', 'accepted', ['session' => 'evening', 'litres' => 10], $attributes);
        $this->assertSame(5.0, (float) $evening->amount);
        $sour = $this->record($workspace, 'dairy', 'deliveries', 'Tendai Moyo', 'rejected', ['session' => 'morning', 'litres' => 6], ['contact_id' => $farmer->id, 'occurs_on' => today()->subDay(), 'amount' => 3]);
        $this->assertSame(0.0, (float) $sour->amount);

        $this->actingAs($owner)->post(route('apps.records.store', ['dairy', 'deliveries']), [
            'title' => 'Tendai Moyo', 'status' => 'accepted', 'contact_id' => $farmer->id, 'occurs_on' => today()->toDateString(), 'data' => ['session' => 'morning', 'litres' => 5, 'fat_percent' => 40],
        ])->assertSessionHasErrors(['data.session', 'data.fat_percent']);

        $this->actingAs($owner)->get(route('apps.records.document', ['dairy', 'deliveries', $evening->id, 'payout']))->assertOk()
            ->assertSee('Milk payout')->assertSee('Tendai Moyo')->assertSee('30.0')->assertSee('15.00');
        $this->actingAs($owner)->get(route('apps.show', 'dairy'))->assertOk()->assertSee('Milk intake')->assertSee('30.0 L');
        $this->actingAs($owner)->get(route('apps.reports', 'dairy'))->assertOk()->assertSee('Farmer payouts')->assertSee('Litres by centre');
    }

    public function test_cooperative_numbers_members_and_totals_their_contributions(): void
    {
        [$owner, $workspace] = $this->appWorkspace('cooperative');
        $grace = $this->record($workspace, 'cooperative', 'members', 'Grace Phiri', 'active');
        $peter = $this->record($workspace, 'cooperative', 'members', 'Peter Banda', 'exited');
        $this->assertSame('M0001', $grace->value('member_number'));
        $this->assertSame('M0002', $peter->value('member_number'));

        $this->actingAs($owner)->post(route('apps.records.store', ['cooperative', 'members']), [
            'title' => 'Ruth Zulu', 'status' => 'active', 'data' => ['member_number' => 'M0001'],
        ])->assertSessionHasErrors('data.member_number');

        $this->record($workspace, 'cooperative', 'contributions', 'Shares', 'received', ['member' => $grace->id, 'type' => 'share_capital'], ['amount' => 100]);
        $this->record($workspace, 'cooperative', 'contributions', 'Savings', 'received', ['member' => $grace->id, 'type' => 'savings'], ['amount' => 50]);
        $this->record($workspace, 'cooperative', 'contributions', 'Levy', 'pending', ['member' => $grace->id, 'type' => 'levy'], ['amount' => 20]);
        $grace = $grace->fresh();
        $this->assertEquals(100, $grace->value('_share_capital'));
        $this->assertEquals(50, $grace->value('_savings'));
        $this->assertEquals(0, $grace->value('_levy'));

        $this->actingAs($owner)->post(route('apps.records.store', ['cooperative', 'contributions']), [
            'title' => 'Shares', 'status' => 'received', 'amount' => 10, 'data' => ['member' => $peter->id, 'type' => 'share_capital'],
        ])->assertSessionHasErrors('data.member');

        $this->actingAs($owner)->get($grace->url())->assertOk()->assertSee('Member account')->assertSee('M0001');
        $this->actingAs($owner)->get(route('apps.show', 'cooperative'))->assertOk()->assertSee('Active members')->assertSee('100.00');
        $this->actingAs($owner)->get(route('apps.reports', 'cooperative'))->assertOk()->assertSee('Contributions by month')->assertSee('Member register');
    }

    public function test_produce_sales_cannot_oversell_a_lot_and_close_it_when_sold_out(): void
    {
        [$owner, $workspace] = $this->appWorkspace('produce-sales');
        $lot = $this->record($workspace, 'produce-sales', 'lots', 'Tomatoes L1', 'in_store', ['crop' => 'Tomatoes', 'quantity' => 100, 'grade' => 'grade_a']);
        $reject = $this->record($workspace, 'produce-sales', 'lots', 'Tomatoes L2', 'graded', ['crop' => 'Tomatoes', 'quantity' => 30, 'grade' => 'reject']);

        $sale = ['title' => 'Fresh Mart', 'status' => 'agreed', 'data' => ['lot' => $lot->id, 'quantity' => 120, 'price_per_kg' => 2, 'market' => 'retailer']];
        $this->actingAs($owner)->post(route('apps.records.store', ['produce-sales', 'sales']), $sale)->assertSessionHasErrors('data.quantity');
        $this->actingAs($owner)->post(route('apps.records.store', ['produce-sales', 'sales']), [...$sale, 'data' => [...$sale['data'], 'lot' => $reject->id, 'quantity' => 5]])->assertSessionHasErrors('data.lot');

        $this->actingAs($owner)->post(route('apps.records.store', ['produce-sales', 'sales']), [...$sale, 'data' => [...$sale['data'], 'quantity' => 60]])->assertSessionHasNoErrors();
        $first = $this->latest('produce-sales', 'sales');
        $this->assertSame(120.0, (float) $first->amount);
        $this->actingAs($owner)->post(route('apps.records.store', ['produce-sales', 'sales']), [...$sale, 'data' => [...$sale['data'], 'quantity' => 40, 'market' => 'fresh_produce_market']])->assertSessionHasNoErrors();
        $this->assertSame('sold', $lot->fresh()->status);

        $this->actingAs($owner)->put(route('apps.records.update', ['produce-sales', 'sales', $first->id]), [...$sale, 'data' => [...$sale['data'], 'quantity' => 50]])->assertSessionHasNoErrors();
        $lot = $lot->fresh();
        $this->assertSame('in_store', $lot->status);
        $this->assertEquals(10, $lot->value('_available'));

        $this->actingAs($owner)->get($lot->url())->assertOk()->assertSee('Average price');
        $this->actingAs($owner)->get(route('apps.show', 'produce-sales'))->assertOk()->assertSee('Produce to sell')->assertSee('Tomatoes L1');
        $this->actingAs($owner)->get(route('apps.reports', 'produce-sales'))->assertOk()->assertSee('Sales by market')->assertSee('Fresh produce market');
    }

    public function test_fish_ponds_track_survival_biomass_feed_conversion_and_water_quality(): void
    {
        [$owner, $workspace] = $this->appWorkspace('fish-farming-aquaculture');
        $pond = $this->record($workspace, 'fish-farming-aquaculture', 'units', 'Pond 1', 'stocked', ['species' => 'tilapia', 'fish_count' => 1000, 'average_weight' => 200], ['occurs_on' => today()->subDays(30)]);
        $empty = $this->record($workspace, 'fish-farming-aquaculture', 'units', 'Pond 2', 'empty');

        $log = $this->record($workspace, 'fish-farming-aquaculture', 'logs', 'Pond 1', 'logged', ['unit' => $pond->id, 'feed' => 100, 'mortalities' => 50, 'oxygen' => 3, 'ph' => 7], ['occurs_on' => today()]);
        $this->assertSame(['Low oxygen (3 mg/L)'], $log->value('_problems'));
        $pond = $pond->fresh();
        $this->assertEquals(950, $pond->value('_alive'));
        $this->assertEquals(190, $pond->value('_biomass'));
        $this->assertEquals(95, $pond->value('_survival'));

        $this->actingAs($owner)->post(route('apps.records.store', ['fish-farming-aquaculture', 'logs']), [
            'title' => 'Pond 2', 'status' => 'logged', 'data' => ['unit' => $empty->id, 'feed' => 5],
        ])->assertSessionHasErrors('data.unit');

        $this->record($workspace, 'fish-farming-aquaculture', 'harvests', 'Pond 1', 'sold', ['unit' => $pond->id, 'weight' => 150], ['amount' => 600, 'occurs_on' => today()]);
        $pond = $pond->fresh();
        $this->assertSame('harvesting', $pond->status);
        $this->assertEquals(0.67, $pond->value('_fcr'));

        $this->actingAs($owner)->get(route('apps.show', 'fish-farming-aquaculture'))->assertOk()->assertSee('Check Pond 1')->assertSee('Low oxygen');
        $this->actingAs($owner)->get($pond->url())->assertOk()->assertSee('Standing biomass');
        $this->actingAs($owner)->get(route('apps.reports', 'fish-farming-aquaculture'))->assertOk()->assertSee('Production by pond')->assertSee('600.00');
    }

    public function test_greenhouse_irrigation_refuses_clashes_and_skips_missed_runs(): void
    {
        [$owner, $workspace] = $this->appWorkspace('greenhouse-irrigation-scheduling');
        $app = 'greenhouse-irrigation-scheduling';
        $tunnel = $this->record($workspace, $app, 'blocks', 'Tunnel 1', 'planted', ['crop' => 'Peppers', 'area' => 500]);
        $fallow = $this->record($workspace, $app, 'blocks', 'Tunnel 2', 'fallow', ['area' => 300]);
        $this->record($workspace, $app, 'irrigations', 'Tunnel 1', 'scheduled', ['block' => $tunnel->id, 'start_time' => '06:00', 'minutes' => 30], ['occurs_on' => today()]);

        $run = ['title' => 'Tunnel 1', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['block' => $tunnel->id, 'start_time' => '06:15', 'minutes' => 15]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'irrigations']), $run)->assertSessionHasErrors('data.start_time');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'irrigations']), [...$run, 'data' => [...$run['data'], 'block' => $fallow->id]])->assertSessionHasErrors('data.block');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'irrigations']), [...$run, 'data' => [...$run['data'], 'start_time' => '06:30']])->assertSessionHasNoErrors();

        $hot = $this->record($workspace, $app, 'irrigations', 'Tunnel 1', 'done', ['block' => $tunnel->id, 'water' => 1000, 'ec' => 4.2], ['occurs_on' => today()->subDay()]);
        $this->assertTrue($hot->value('_high_ec'));
        $this->assertEquals(2, $tunnel->fresh()->value('_litres_per_m2'));

        $missed = $this->record($workspace, $app, 'irrigations', 'Tunnel 1', 'scheduled', ['block' => $tunnel->id, 'start_time' => '05:00', 'minutes' => 20], ['occurs_on' => today()->subDays(2)]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('skipped', $missed->fresh()->status);

        $this->actingAs($owner)->get($hot->url())->assertOk()->assertSee('EC too high');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Today&#039;s irrigation', false)->assertSee('06:30');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Water by block')->assertSee('Peppers');
    }
}
