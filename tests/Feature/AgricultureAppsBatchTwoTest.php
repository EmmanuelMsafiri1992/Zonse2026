<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The farm service apps' rules: agro-dealers, contract farming, tractor hire, grain storage, extension, veterinary visits and land leasing. */
class AgricultureAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /** @param  array<string, mixed>  $data  @param  array<string, mixed>  $attributes */
    protected function record(Workspace $workspace, string $app, string $entity, string $title, string $status, array $data = [], array $attributes = []): Record
    {
        return Record::factory()->ofEntity($app, $entity, $data)->create(['workspace_id' => $workspace->id, 'title' => $title, 'status' => $status, ...$attributes]);
    }

    public function test_agro_dealer_tracks_stock_status_expiry_and_farmer_credit(): void
    {
        $app = 'agro-dealer-input-supply-store';
        [$owner, $workspace] = $this->appWorkspace($app);
        $seed = $this->record($workspace, $app, 'products', 'Hybrid maize seed', 'in_stock', ['type' => 'seed', 'price' => 40, 'stock' => 6, 'batch_number' => 'B7', 'expiry_date' => today()->addDays(10)->toDateString()]);
        $this->assertSame('low_stock', $seed->status);
        $urea = $this->record($workspace, $app, 'products', 'Urea 50kg', 'in_stock', ['type' => 'fertiliser', 'price' => 30, 'stock' => 0]);
        $this->assertSame('out_of_stock', $urea->status);
        $this->assertSame('in_stock', $this->record($workspace, $app, 'products', 'Hoe', 'out_of_stock', ['type' => 'tools', 'price' => 5, 'stock' => 50])->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'products']), [
            'title' => 'Old herbicide', 'status' => 'in_stock', 'data' => ['type' => 'herbicide', 'price' => 10, 'stock' => 4, 'expiry_date' => today()->subDay()->toDateString()],
        ])->assertSessionHasErrors('data.expiry_date');

        $sale = ['title' => 'Grace Banda', 'status' => 'on_credit', 'amount' => 120, 'data' => ['items' => '3 bags seed']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), $sale)->assertSessionHasErrors(['due_on', 'data.phone']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), [...$sale, 'due_on' => today()->addMonths(4)->toDateString(), 'data' => ['items' => '3 bags seed', 'phone' => '0991234567']])->assertSessionHasNoErrors();
        $this->record($workspace, $app, 'sales', 'Peter Phiri', 'on_credit', ['items' => 'Urea', 'phone' => '0888'], ['amount' => 60, 'due_on' => today()->subDays(3), 'occurs_on' => today()->subMonths(3)]);

        $this->actingAs($owner)->get($seed->url())->assertOk()->assertSee('Expires soon')->assertSee('B7');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Farmer credit')->assertSee('Reorder')->assertSee('Urea 50kg')->assertSee('Expiring stock');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Credit book')->assertSee('Peter Phiri')->assertSee('Stock value');
    }

    public function test_contract_farming_recovers_input_loans_from_deliveries(): void
    {
        $app = 'contract-farming-out-grower-schemes';
        [$owner, $workspace] = $this->appWorkspace($app);
        $grower = $this->record($workspace, $app, 'growers', 'John Mvula', 'contracted', ['grower_number' => 'G-01', 'crop' => 'tobacco', 'hectares' => 2, 'input_loan' => 1000]);
        $exited = $this->record($workspace, $app, 'growers', 'Old grower', 'exited', ['grower_number' => 'G-02', 'crop' => 'tobacco']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'growers']), [
            'title' => 'Copy', 'status' => 'contracted', 'data' => ['grower_number' => 'G-01', 'crop' => 'tobacco'],
        ])->assertSessionHasErrors('data.grower_number');

        $delivery = ['title' => 'DN-1', 'status' => 'received', 'amount' => 800, 'data' => ['grower' => $grower->id, 'weight' => 400, 'loan_deduction' => 900]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliveries']), $delivery)->assertSessionHasErrors('data.loan_deduction');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliveries']), [...$delivery, 'data' => ['grower' => $exited->id, 'weight' => 100]])->assertSessionHasErrors('data.grower');

        $first = $this->record($workspace, $app, 'deliveries', 'DN-2', 'graded', ['grower' => $grower->id, 'weight' => 1000, 'grade' => 'A', 'loan_deduction' => 700], ['amount' => 2000]);
        $this->assertEquals(1300, $first->value('_net'));
        $grower = $grower->fresh();
        $this->assertSame('active', $grower->status);
        $this->assertEquals(300, $grower->value('_loan_balance'));
        $this->assertEquals(500, $grower->value('_yield_per_ha'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliveries']), [...$delivery, 'amount' => 1000, 'data' => ['grower' => $grower->id, 'weight' => 500, 'loan_deduction' => 400]])
            ->assertSessionHasErrors('data.loan_deduction');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliveries']), [...$delivery, 'amount' => 1000, 'data' => ['grower' => $grower->id, 'weight' => 500, 'loan_deduction' => 300]])
            ->assertSessionHasNoErrors();
        $this->assertEquals(0, $grower->fresh()->value('_loan_balance'));

        $this->actingAs($owner)->get(route('apps.records.document', [$app, 'deliveries', $first->id, 'slip']))->assertOk()->assertSee('Grower payment slip')->assertSee('Net payable');
        $this->actingAs($owner)->get($grower->url())->assertOk()->assertSee('Grower account');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Loans recovered')->assertSee('100%');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Grower deliveries')->assertSee('G-01');
    }

    public function test_tractor_hire_prices_jobs_and_stops_double_bookings(): void
    {
        $app = 'tractor-machinery-hire';
        [$owner, $workspace] = $this->appWorkspace($app);
        $tractor = $this->record($workspace, $app, 'machines', 'MF 375', 'available', ['type' => 'tractor']);
        $broken = $this->record($workspace, $app, 'machines', 'Old planter', 'repair', ['type' => 'planter']);

        $job = ['title' => 'Mary Tembo', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['machine' => $broken->id, 'service' => 'planting', 'hectares' => 2]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), $job)->assertSessionHasErrors('data.machine');

        $booked = $this->record($workspace, $app, 'jobs', 'Mary Tembo', 'booked', ['machine' => $tractor->id, 'service' => 'ploughing', 'hectares' => 4, 'rate_per_ha' => 50], ['occurs_on' => today()]);
        $this->assertEquals(200, (float) $booked->amount);
        $this->assertSame('booked', $tractor->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), [...$job, 'data' => [...$job['data'], 'machine' => $tractor->id]])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), [...$job, 'occurs_on' => today()->addDay()->toDateString(), 'data' => [...$job['data'], 'machine' => $tractor->id]])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Jobs this week')->assertSee('Mary Tembo');

        $booked->update(['status' => 'done', 'data' => [...$booked->data, 'fuel' => 20]]);
        $tractor = $tractor->fresh();
        $this->assertSame('booked', $tractor->status);
        $this->assertEquals(4, $tractor->value('_hectares'));
        $this->assertEquals(5, $tractor->value('_fuel_per_ha'));
        $this->assertSame('repair', $broken->fresh()->status);

        $this->actingAs($owner)->get($tractor->url())->assertOk()->assertSee('Work done')->assertSee('5 L/ha');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Work by machine')->assertSee('Done but not paid');
    }

    public function test_grain_storage_checks_moisture_room_and_pledges(): void
    {
        $app = 'grain-storage';
        [$owner, $workspace] = $this->appWorkspace($app);
        $silo = $this->record($workspace, $app, 'stores', 'Silo 1', 'empty', ['capacity' => 100, 'commodity' => 'maize']);
        $shed = $this->record($workspace, $app, 'stores', 'Shed', 'fumigating', ['capacity' => 50]);

        $receipt = ['title' => 'Alice Zulu', 'status' => 'issued', 'data' => ['store' => $silo->id, 'commodity' => 'maize', 'weight' => 20, 'moisture' => 15]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'receipts']), $receipt)->assertSessionHasErrors('data.moisture');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'receipts']), [...$receipt, 'data' => [...$receipt['data'], 'moisture' => 12, 'commodity' => 'wheat']])->assertSessionHasErrors('data.commodity');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'receipts']), [...$receipt, 'data' => [...$receipt['data'], 'moisture' => 12, 'weight' => 120]])->assertSessionHasErrors('data.weight');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'receipts']), [...$receipt, 'data' => [...$receipt['data'], 'moisture' => 12, 'store' => $shed->id]])->assertSessionHasErrors('data.store');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'receipts']), [...$receipt, 'status' => 'pledged', 'data' => [...$receipt['data'], 'moisture' => 12]])->assertSessionHasErrors('data.pledged_to');

        $this->record($workspace, $app, 'receipts', 'Alice Zulu', 'issued', ['store' => $silo->id, 'commodity' => 'maize', 'weight' => 60, 'moisture' => 12.5], ['amount' => 90]);
        $pledged = $this->record($workspace, $app, 'receipts', 'Ben Daka', 'pledged', ['store' => $silo->id, 'commodity' => 'maize', 'weight' => 30, 'pledged_to' => 'NBS Bank'], ['amount' => 45]);
        $silo = $silo->fresh();
        $this->assertSame('active', $silo->status);
        $this->assertEquals(90, $silo->value('current_stock'));
        $this->assertEquals(90, $silo->value('_fill'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'receipts', $pledged->id]), [
            'title' => 'Ben Daka', 'status' => 'withdrawn', 'data' => ['store' => $silo->id, 'commodity' => 'maize', 'weight' => 30, 'pledged_to' => 'NBS Bank'],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'receipts']), [...$receipt, 'data' => [...$receipt['data'], 'moisture' => 12, 'weight' => 11]])->assertSessionHasErrors('data.weight');

        $pledged->update(['status' => 'withdrawn']);
        $this->assertEquals(60, $silo->fresh()->value('current_stock'));

        $this->actingAs($owner)->get(route('apps.records.document', [$app, 'receipts', $pledged->id, 'receipt']))->assertOk()->assertSee('Warehouse receipt')->assertSee('Ben Daka');
        $this->actingAs($owner)->get($silo->url())->assertOk()->assertSee('Room left')->assertSee('40 t');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Silo 1')->assertSee('60 / 100 t');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Grain held')->assertSee('Alice Zulu');
    }

    public function test_extension_services_track_visits_follow_ups_and_training_reach(): void
    {
        $app = 'extension-services-farmer-training';
        [$owner, $workspace] = $this->appWorkspace($app);
        $farmer = $this->record($workspace, $app, 'farmers', 'Esther Moyo', 'active', ['village' => 'Chileka']);
        $this->record($workspace, $app, 'farmers', 'Never Seen', 'active');

        $visit = ['title' => 'Esther Moyo', 'status' => 'done', 'occurs_on' => today()->toDateString(), 'data' => ['farmer' => $farmer->id, 'topic' => 'Fall armyworm']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), $visit)->assertSessionHasErrors('data.advice');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [...$visit, 'data' => [...$visit['data'], 'advice' => 'Spray', 'follow_up' => today()->subDay()->toDateString()]])
            ->assertSessionHasErrors('data.follow_up');

        $this->record($workspace, $app, 'visits', 'Esther Moyo', 'done', ['farmer' => $farmer->id, 'topic' => 'Fall armyworm', 'advice' => 'Scout twice a week', 'follow_up' => today()->addDays(2)->toDateString()], ['occurs_on' => today()->subDays(10)]);
        $farmer = $farmer->fresh();
        $this->assertEquals(1, $farmer->value('_visits'));
        $this->assertSame(today()->subDays(10)->toDateString(), $farmer->value('_last_visit'));

        $training = ['title' => 'Conservation agriculture', 'status' => 'held', 'data' => ['attendees' => 20, 'women_attendees' => 25]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trainings']), $training)->assertSessionHasErrors('data.women_attendees');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trainings']), [...$training, 'data' => []])->assertSessionHasErrors('data.attendees');
        $this->record($workspace, $app, 'trainings', 'Conservation agriculture', 'held', ['attendees' => 40, 'women_attendees' => 30], ['occurs_on' => today()]);

        $this->actingAs($owner)->get($farmer->url())->assertOk()->assertSee('Extension contact');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Follow-ups due')->assertSee('Fall armyworm')->assertSee('Not seen in 90 days');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Outreach by month')->assertSee('75%');
    }

    public function test_veterinary_visits_track_withdrawal_and_run_vaccination_campaigns(): void
    {
        $app = 'veterinary-animal-health-visits';
        [$owner, $workspace] = $this->appWorkspace($app);

        $call = ['title' => 'Kamwendo farm', 'status' => 'done', 'occurs_on' => today()->toDateString(), 'data' => ['species' => 'cattle']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), $call)->assertSessionHasErrors('data.diagnosis');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [...$call, 'data' => ['species' => 'cattle', 'diagnosis' => 'Mastitis', 'withdrawal_until' => today()->subDay()->toDateString()]])
            ->assertSessionHasErrors('data.withdrawal_until');

        $treated = $this->record($workspace, $app, 'visits', 'Kamwendo farm', 'done', ['species' => 'cattle', 'animals' => 3, 'diagnosis' => 'Mastitis', 'withdrawal_until' => today()->addDays(4)->toDateString()], ['occurs_on' => today(), 'amount' => 50]);
        $this->record($workspace, $app, 'visits', 'Banda piggery', 'booked', ['species' => 'pigs'], ['occurs_on' => today()]);

        $starting = $this->record($workspace, $app, 'vaccinations', 'Newcastle', 'planned', ['area' => 'Zomba'], ['occurs_on' => today()->subDay(), 'due_on' => today()->addDays(5)]);
        $finished = $this->record($workspace, $app, 'vaccinations', 'Anthrax', 'running', ['area' => 'Dedza', 'animals_vaccinated' => 800], ['occurs_on' => today()->subDays(10), 'due_on' => today()->subDay()]);
        $uncounted = $this->record($workspace, $app, 'vaccinations', 'LSD', 'running', ['area' => 'Mchinji'], ['occurs_on' => today()->subDays(10), 'due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('running', $starting->fresh()->status);
        $this->assertSame('completed', $finished->fresh()->status);
        $this->assertSame('running', $uncounted->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'vaccinations']), [
            'title' => 'Rabies', 'status' => 'completed', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(), 'data' => [],
        ])->assertSessionHasErrors(['data.animals_vaccinated', 'due_on']);

        $this->actingAs($owner)->get($treated->url())->assertOk()->assertSee('Withdrawal period')->assertSee('5 days');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Under withdrawal')->assertSee('Calls to make')->assertSee('Banda piggery');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Farm calls by species')->assertSee('Anthrax');
    }

    public function test_land_leasing_allows_one_active_lease_per_parcel_and_expires_leases(): void
    {
        $app = 'land-leasing-land-registry';
        [$owner, $workspace] = $this->appWorkspace($app);
        $north = $this->record($workspace, $app, 'parcels', 'North block', 'owned', ['parcel_number' => 'LR-1', 'hectares' => 10, 'land_use' => 'crop']);
        $rented = $this->record($workspace, $app, 'parcels', 'Rented field', 'leased_in', ['parcel_number' => 'LR-2', 'hectares' => 4]);
        $east = $this->record($workspace, $app, 'parcels', 'East block', 'vacant', ['parcel_number' => 'LR-3', 'hectares' => 5]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'parcels']), [
            'title' => 'Copy', 'status' => 'owned', 'data' => ['parcel_number' => 'LR-1', 'hectares' => 1],
        ])->assertSessionHasErrors('data.parcel_number');

        $lease = $this->record($workspace, $app, 'leases', 'Chikondi Estates', 'active', ['parcel' => $north->id, 'rent_basis' => 'per_hectare'], ['amount' => 5000, 'occurs_on' => today()->subYear(), 'due_on' => today()->addDays(30)]);
        $this->assertEquals(500, $lease->value('_rent_per_ha'));
        $this->assertSame('leased_out', $north->fresh()->status);

        $another = ['title' => 'Someone else', 'status' => 'active', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addYear()->toDateString(), 'data' => ['parcel' => $north->id]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'leases']), $another)->assertSessionHasErrors('data.parcel');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'leases']), [...$another, 'data' => ['parcel' => $rented->id]])->assertSessionHasErrors('data.parcel');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'leases']), [...$another, 'due_on' => today()->subDay()->toDateString(), 'data' => ['parcel' => $east->id]])->assertSessionHasErrors('due_on');

        $ended = $this->record($workspace, $app, 'leases', 'Old tenant', 'active', ['parcel' => $east->id], ['amount' => 1000, 'occurs_on' => today()->subYears(2), 'due_on' => today()->subDay()]);
        $this->assertSame('leased_out', $east->fresh()->status);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $ended->fresh()->status);
        $this->assertSame('vacant', $east->fresh()->status);
        $this->assertSame('active', $lease->fresh()->status);

        $this->actingAs($owner)->get($north->url())->assertOk()->assertSee('Lease history')->assertSee('Chikondi Estates');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Leases ending soon')->assertSee('Chikondi Estates');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Rent roll')->assertSee('North block');
    }
}
