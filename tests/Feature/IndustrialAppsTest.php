<?php

namespace Tests\Feature;

use App\Blueprints\Logic\LabLogic;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class IndustrialAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_mine_locks_shift_reports_and_stops_blasting_after_a_misfire(): void
    {
        $app = 'mine-quarry-operations';
        [$owner, $workspace] = $this->appWorkspace($app);
        $shift = fn (array $data, string $title = 'North day') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shifts']), [
            'title' => $title, 'status' => 'open', 'occurs_on' => today()->toDateString(), 'data' => ['pit' => 'North pit', 'shift' => 'day', ...$data],
        ]);

        $shift(['tonnes_mined' => 1200, 'tonnes_hauled' => 1100, 'loads' => 25, 'downtime_minutes' => 60])->assertSessionHasNoErrors();
        $report = Record::query()->where('entity', 'shifts')->firstOrFail();
        $this->assertEquals(44, $report->value('_tonnes_per_load'));
        $this->assertEquals(91.7, $report->value('_availability'));
        $shift(['pit' => ' north PIT'], 'Again')->assertSessionHasErrors('data.shift');
        $shift(['shift' => 'night', 'downtime_minutes' => 800], 'Night')->assertSessionHasErrors('data.downtime_minutes');
        $shift(['shift' => 'night', 'tonnes_mined' => -5], 'Night')->assertSessionHasErrors('data.tonnes_mined');

        $this->actingAs($owner)->post($report->url().'/actions/submit')->assertSessionHas('flash.message', 'North day submitted: 1,200 t mined.');
        $this->actingAs($owner)->post($report->url().'/actions/approve');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'shifts', $report->id]), [
            'title' => 'North day', 'status' => 'open', 'occurs_on' => today()->toDateString(), 'data' => ['pit' => 'North pit', 'shift' => 'day', 'tonnes_mined' => 1500],
        ])->assertSessionHasErrors('status');

        $empty = $this->record($workspace, $app, 'blasts', 'Bench 1', 'planned', ['blaster' => 'J. Banda']);
        $this->actingAs($owner)->post($empty->url().'/actions/fire')->assertSessionHasErrors('holes');
        $bench = $this->record($workspace, $app, 'blasts', 'Bench 3', 'planned', ['blaster' => 'J. Banda', 'holes' => 40, 'explosives_kg' => 800]);
        $this->assertEquals(20, $bench->value('_kg_per_hole'));
        $this->actingAs($owner)->post($bench->url().'/actions/fire', ['fire_time' => '12:30', 'vibration' => 14])
            ->assertSessionHas('flash.message', 'Bench 3 fired; vibration of 14 mm/s is over the 12.5 mm/s limit.');
        $this->actingAs($owner)->post($bench->url().'/actions/misfire');

        $next = $this->record($workspace, $app, 'blasts', 'Bench 4', 'planned', ['blaster' => 'J. Banda', 'holes' => 10, 'explosives_kg' => 150]);
        $this->actingAs($owner)->post($next->url().'/actions/fire')->assertSessionHasErrors('status');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Misfire to clear')->assertSee('Bench 3');
        $this->actingAs($owner)->post($bench->url().'/actions/clear');
        $this->actingAs($owner)->post($next->url().'/actions/fire', ['vibration' => 4])->assertSessionHas('flash.message', 'Bench 4 fired.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertDontSee('Misfire to clear');

        $pile = $this->record($workspace, $app, 'stockpiles', '19mm stone', 'active', ['product' => 'aggregate_19mm', 'tonnes' => 500]);
        $this->actingAs($owner)->post($pile->url().'/actions/draw', ['tonnes' => 600])->assertSessionHasErrors('tonnes');
        $this->actingAs($owner)->post($pile->url().'/actions/draw', ['tonnes' => 500])->assertSessionHas('flash.message', '500.0 t loaded out of 19mm stone; 0.0 t left.');
        $this->assertSame('depleted', $pile->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Production by pit')->assertSee('North pit')->assertSee('Blasting register')->assertSee('(over limit)');
    }

    public function test_weighbridge_works_out_net_mass_from_two_weighs(): void
    {
        $app = 'weighbridge';
        [$owner] = $this->appWorkspace($app);
        $ticket = fn (string $registration, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tickets']), [
            'title' => $registration, 'status' => 'first_weigh', 'occurs_on' => today()->toDateString(), 'data' => ['direction' => 'in', 'product' => 'Maize', ...$data],
        ]);

        $ticket('abc 123 gp', ['gross' => 30000])->assertSessionHasNoErrors();
        $first = Record::query()->where('entity', 'tickets')->firstOrFail();
        $this->assertSame('ABC 123 GP', $first->title);
        $ticket('ABC123GP', ['gross' => 31000])->assertSessionHasErrors('title');

        $this->actingAs($owner)->post($first->url().'/actions/second_weigh', ['mass' => 30000])->assertSessionHasErrors('mass');
        $this->actingAs($owner)->post($first->url().'/actions/second_weigh', ['mass' => 12000])->assertSessionHas('flash.message', 'ABC 123 GP weighed: net 18,000 kg.');
        $first->refresh();
        $this->assertSame('completed', $first->status);
        $this->assertEquals(18000, $first->value('net'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'tickets', $first->id]), [
            'title' => 'ABC 123 GP', 'status' => 'completed', 'occurs_on' => today()->toDateString(), 'data' => ['direction' => 'in', 'gross' => 31000, 'tare' => 12000],
        ])->assertSessionHasErrors('data.gross');

        $ticket('ABC 123 GP', ['direction' => 'out', 'gross' => 60000])->assertSessionHasNoErrors();
        $second = Record::query()->where('entity', 'tickets')->where('status', 'first_weigh')->firstOrFail();
        $ticket('XYZ 9', ['gross' => 15000]);
        $this->actingAs($owner)->post($second->url().'/actions/stored_tare')->assertSessionHas('flash.message', 'ABC 123 GP weighed: net 48,000 kg; overloaded by 4,000 kg.');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting for second weigh')->assertSee('XYZ 9')->assertSee('18.0')->assertSee('48.0');

        $this->actingAs($owner)->post($first->url().'/actions/void')->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($first->url().'/actions/void', ['reason' => 'Wrong product'])->assertSessionHas('flash.message', $first->number.' voided: Wrong product.');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Tonnage by product')->assertSee('Overloads')->assertSee('4,000')->assertSee('Wrong product');
    }

    public function test_mes_runs_one_job_per_machine_and_works_out_oee(): void
    {
        $app = 'manufacturing-execution';
        [$owner, $workspace] = $this->appWorkspace($app);
        $press = $this->record($workspace, $app, 'machines', 'Press 1', 'idle', ['line' => 'Line A', 'rated_output' => 600]);
        $run = fn (string $title, string $status = 'planned') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'runs']), [
            'title' => $title, 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['machine' => $press->id, 'planned_quantity' => 1000],
        ]);

        $run('Brackets')->assertSessionHasNoErrors();
        $brackets = Record::query()->where('entity', 'runs')->firstOrFail();
        $this->actingAs($owner)->post($brackets->url().'/actions/start')->assertSessionHas('flash.message', 'Brackets started on Press 1.');
        $this->assertSame('running', $press->fresh()->status);
        $run('Clips', 'running')->assertSessionHasErrors('data.machine');
        $run('Clips')->assertSessionHasNoErrors();
        $clips = Record::query()->where('entity', 'runs')->where('title', 'Clips')->firstOrFail();
        $this->actingAs($owner)->post($clips->url().'/actions/start')->assertSessionHasErrors('machine');

        $this->travel(2)->hours();
        $this->actingAs($owner)->post($brackets->url().'/actions/complete', ['good_quantity' => 700, 'scrap' => 50, 'downtime_minutes' => 30])->assertSessionHasErrors('downtime_reason');
        $this->actingAs($owner)->post($brackets->url().'/actions/complete', ['good_quantity' => 700, 'scrap' => 50, 'downtime_minutes' => 30, 'downtime_reason' => 'Die change'])
            ->assertSessionHas('flash.message', 'Brackets completed: 700 good, 50 scrap, OEE 58.3%.');
        $brackets->refresh();
        $this->assertEquals(75, $brackets->value('_availability'));
        $this->assertEquals(83.3, $brackets->value('_performance'));
        $this->assertEquals(93.3, $brackets->value('_quality'));
        $this->assertSame('idle', $press->fresh()->status);
        $this->actingAs($owner)->get($brackets->url())->assertOk()->assertSee('OEE')->assertSee('58.3%');

        $this->actingAs($owner)->post($clips->url().'/actions/start')->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($press->url().'/actions/breakdown')->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($press->url().'/actions/breakdown', ['reason' => 'Hydraulic leak'])->assertSessionHas('flash.message', 'Press 1 is down; Clips stopped.');
        $this->assertSame('stopped', $clips->fresh()->status);
        $this->assertSame('Hydraulic leak', $clips->fresh()->value('downtime_reason'));

        $run('Washers')->assertSessionHasNoErrors();
        $washers = Record::query()->where('entity', 'runs')->where('title', 'Washers')->firstOrFail();
        $this->actingAs($owner)->post($washers->url().'/actions/start')->assertSessionHasErrors('machine');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Floor now')->assertSee('Hydraulic leak');
        $this->actingAs($owner)->post($press->url().'/actions/repaired')->assertSessionHas('flash.message', 'Press 1 is back in service.');
        $this->actingAs($owner)->post($washers->url().'/actions/start')->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('OEE by machine')->assertSee('58.3%')->assertSee('Die change')->assertSee('Scrap by product');
    }

    public function test_lab_judges_results_against_specifications(): void
    {
        $lab = new LabLogic;
        $this->assertSame('pass', $lab->judge('8.2', '6.5 - 8.5'));
        $this->assertSame('fail', $lab->judge('9', '6.5–8.5'));
        $this->assertSame('fail', $lab->judge('12 mg/L', '≤ 10'));
        $this->assertSame('pass', $lab->judge('10', '<= 10'));
        $this->assertSame('fail', $lab->judge('10', '< 10'));
        $this->assertSame('pass', $lab->judge('<0.5', 'max 1'));
        $this->assertSame('pass', $lab->judge('5', 'min 5'));
        $this->assertSame('pass', $lab->judge('Not detected', 'Absent'));
        $this->assertSame('fail', $lab->judge('Present', 'Absent in 100 mL'));
        $this->assertNull($lab->judge('Cloudy', 'Clear'));
        $this->assertNull($lab->judge('7', ''));
    }

    public function test_lab_moves_samples_through_testing_and_issues_certificates(): void
    {
        $app = 'lab-testing-certificates-of';
        [$owner, $workspace] = $this->appWorkspace($app);
        $sample = fn (string $number) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'samples']), [
            'title' => 'Borehole water', 'status' => 'received', 'occurs_on' => today()->toDateString(), 'data' => ['sample_number' => $number, 'matrix' => 'water', 'tests_requested' => 'pH, nitrate, turbidity'],
        ]);
        $sample('W-001')->assertSessionHasNoErrors();
        $water = Record::query()->where('entity', 'samples')->firstOrFail();
        $this->assertSame(today()->addDays(5)->toDateString(), $water->due_on->toDateString());
        $sample('w-001')->assertSessionHasErrors('data.sample_number');

        $result = fn (string $test, array $data, string $status = 'pending') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'results']), [
            'title' => $test, 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['sample' => $water->id, ...$data],
        ]);
        $result('pH', ['value' => '7.2', 'specification' => '6.5 - 8.5'])->assertSessionHasNoErrors();
        $this->assertSame('pass', Record::query()->where('entity', 'results')->where('title', 'pH')->firstOrFail()->status);
        $this->assertSame('results_ready', $water->fresh()->status);
        $result('Turbidity', ['value' => '3', 'unit' => 'NTU'])->assertSessionHasNoErrors();
        $this->assertSame('testing', $water->fresh()->status);
        $result('Nitrate', ['value' => '12', 'unit' => 'mg/L', 'specification' => '≤ 10']);
        $this->assertSame('fail', Record::query()->where('entity', 'results')->where('title', 'Nitrate')->firstOrFail()->status);

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'samples', $water->id]), [
            'title' => 'Borehole water', 'status' => 'certificate_issued', 'occurs_on' => today()->toDateString(), 'data' => ['sample_number' => 'W-001', 'matrix' => 'water', 'tests_requested' => 'pH, nitrate, turbidity'],
        ])->assertSessionHasErrors('status');
        $turbidity = Record::query()->where('entity', 'results')->where('title', 'Turbidity')->firstOrFail();
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'results', $turbidity->id]), [
            'title' => 'Turbidity', 'status' => 'pass', 'occurs_on' => today()->toDateString(), 'data' => ['sample' => $water->id, 'value' => '3', 'unit' => 'NTU'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('results_ready', $water->fresh()->status);

        $this->actingAs($owner)->post($water->url().'/actions/certificate')
            ->assertSessionHas('flash.message', 'Certificate COA-'.today()->format('Y').'-0001 issued with 1 result out of specification.');
        $this->actingAs($owner)->get(route('apps.records.document', [$app, 'samples', $water->id, 'certificate']))->assertOk()
            ->assertSee('Certificate of analysis')->assertSee('COA-'.today()->format('Y').'-0001')->assertSee('1 of 3 results out of specification');
        $result('Lead', ['value' => '0.001', 'specification' => 'max 0.01'])->assertSessionHasErrors('data.sample');

        $soil = $this->record($workspace, $app, 'samples', 'Field soil', 'received', ['sample_number' => 'S-014', 'matrix' => 'soil', 'tests_requested' => 'pH']);
        $this->actingAs($owner)->post($soil->url().'/actions/reject')->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($soil->url().'/actions/reject', ['reason' => 'Container leaked'])->assertSessionHas('flash.message', $soil->number.' rejected: Container leaked.');

        $this->record($workspace, $app, 'samples', 'Old maize', 'testing', ['sample_number' => 'F-900', 'matrix' => 'food', 'tests_requested' => 'Aflatoxin'], ['occurs_on' => today()->subDays(10)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Overdue samples')->assertSee('F-900');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Turnaround by matrix')->assertSee('Pass rate by matrix')->assertSee('67%')->assertSee('Container leaked');
    }

    public function test_permits_need_their_controls_and_hold_one_location_at_a_time(): void
    {
        $app = 'permit-to-work-safety';
        [$owner, $workspace] = $this->appWorkspace($app);
        $permit = fn (array $data, string $status = 'requested', array $extra = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'permits']), [
            'title' => 'Clean tank', 'status' => $status, 'occurs_on' => today()->toDateString(), ...$extra,
            'data' => ['type' => 'confined_space', 'location' => 'Tank 4', 'hazards' => 'Fumes; ventilate and use a standby man', 'isolations' => 'Inlet valve locked, pump LOTO', 'start_time' => '08:00', 'end_time' => '16:00', ...$data],
        ]);

        $permit(['isolations' => ''])->assertSessionHasErrors('data.isolations');
        $permit(['end_time' => '07:00'])->assertSessionHasErrors('data.end_time');
        $permit(['issuer' => $owner->id], 'requested', ['assignee_id' => $owner->id])->assertSessionHasErrors('data.issuer');
        $permit([])->assertSessionHasNoErrors();
        $tank = Record::query()->where('entity', 'permits')->firstOrFail();

        $this->actingAs($owner)->post($tank->url().'/actions/approve')->assertSessionHas('flash.message', $tank->number.' approved.');
        $this->assertSame($owner->id, (int) $tank->fresh()->value('issuer'));
        $this->actingAs($owner)->post($tank->url().'/actions/activate')->assertSessionHasErrors('gas_test');
        $this->actingAs($owner)->post($tank->url().'/actions/activate', ['gas_test' => 'O2 20.9%, LEL 0%'])->assertSessionHas('flash.message', $tank->number.' active.');

        $permit(['location' => ' tank 4', 'issuer' => $owner->id], 'approved')->assertSessionHasErrors('data.location');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'permits', $tank->id]), [
            'title' => 'Clean tank', 'status' => 'requested', 'occurs_on' => today()->toDateString(),
            'data' => ['type' => 'confined_space', 'location' => 'Tank 4', 'hazards' => 'Fumes', 'isolations' => 'LOTO', 'gas_test' => 'OK', 'issuer' => $owner->id],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post($tank->url().'/actions/suspend')->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($tank->url().'/actions/suspend', ['reason' => 'Rain'])->assertSessionHas('flash.message', $tank->number.' suspended.');
        $this->actingAs($owner)->post($tank->url().'/actions/activate')->assertSessionHasErrors('gas_test');
        $this->actingAs($owner)->post($tank->url().'/actions/activate', ['gas_test' => 'O2 20.8%, LEL 0%'])->assertSessionHasNoErrors();

        $stale = $this->record($workspace, $app, 'permits', 'Weld bracket', 'active', ['type' => 'hot_work', 'location' => 'Pump house', 'hazards' => 'Sparks', 'gas_test' => 'LEL 0%'], ['occurs_on' => today()->subDay()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Live permits')->assertSee('Tank 4')->assertSee('Pump house');
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('suspended', $stale->fresh()->status);
        $this->assertSame('active', $tank->fresh()->status);

        $this->actingAs($owner)->post($tank->url().'/actions/close')->assertSessionHas('flash.message', $tank->number.' closed.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Permits by type')->assertSee('Confined space')->assertSee('Not closed out by the end of the day');
    }

    public function test_contractor_access_admits_only_inducted_workers(): void
    {
        $app = 'contractor-site-access-management';
        [$owner, $workspace] = $this->appWorkspace($app);
        $worker = fn (string $name, string $status, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'workers']), [
            'title' => $name, 'status' => $status, 'data' => ['company' => 'Acme Scaffolding', ...$data],
        ]);

        $worker('Peter Phiri', 'inducted')->assertSessionHasErrors('data.induction_date');
        $worker('Peter Phiri', 'inducted', ['induction_date' => today()->subYears(2)->toDateString()])->assertSessionHasErrors('status');
        $worker('Peter Phiri', 'inducted', ['induction_date' => today()->subMonths(2)->toDateString(), 'medical_expiry' => today()->addYear()->toDateString()])->assertSessionHasNoErrors();
        $peter = Record::query()->where('entity', 'workers')->firstOrFail();
        $this->assertSame(today()->subMonths(2)->addMonths(12)->toDateString(), $peter->value('induction_expiry'));
        $worker('Old Hand', 'pending')->assertSessionHasNoErrors();
        $oldHand = Record::query()->where('entity', 'workers')->where('title', 'Old Hand')->firstOrFail();

        $this->actingAs($owner)->post($oldHand->url().'/actions/induct', ['induction_date' => today()->toDateString(), 'medical_expiry' => today()->subDay()->toDateString()])->assertSessionHasErrors('medical_expiry');
        $this->actingAs($owner)->post($oldHand->url().'/actions/induct', ['induction_date' => today()->toDateString(), 'medical_expiry' => today()->addMonths(6)->toDateString()])
            ->assertSessionHas('flash.message', 'Old Hand inducted until '.today()->addYear()->format('d M Y').'.');

        $newGuy = $this->record($workspace, $app, 'workers', 'New Guy', 'pending', ['company' => 'Acme Scaffolding']);
        $signIn = fn (Record $who, string $in = '07:00') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sign_ins']), [
            'title' => 'x', 'status' => 'on_site', 'occurs_on' => today()->toDateString(), 'data' => ['worker' => $who->id, 'time_in' => $in, 'area' => 'Boiler'],
        ]);
        $signIn($newGuy)->assertSessionHasErrors('data.worker');
        $signIn($peter)->assertSessionHasNoErrors();
        $signIn($peter, '09:00')->assertSessionHasErrors('data.worker');
        $visit = Record::query()->where('entity', 'sign_ins')->firstOrFail();
        $this->assertSame('Peter Phiri', $visit->title);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('On site now (1)')->assertSee('Peter Phiri');
        $this->actingAs($owner)->post($visit->url().'/actions/sign_out', ['time_out' => '06:00'])->assertSessionHasErrors('time_out');
        $this->actingAs($owner)->post($visit->url().'/actions/sign_out', ['time_out' => '15:30'])->assertSessionHas('flash.message', 'Peter Phiri signed out after 8.5 hours.');
        $this->assertSame('signed_out', $visit->fresh()->status);

        $signIn($oldHand, '00:00')->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($oldHand->url().'/actions/block')->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($oldHand->url().'/actions/block', ['reason' => 'Safety breach'])->assertSessionHas('flash.message', 'Old Hand blocked from site and signed out.');
        $this->assertSame(0, Record::query()->where('entity', 'sign_ins')->where('status', 'on_site')->count());
        $signIn($oldHand)->assertSessionHasErrors('data.worker');

        $lapsed = $this->record($workspace, $app, 'workers', 'Lapsed Lungu', 'inducted', ['company' => 'Bolt Electrical', 'induction_date' => today()->subMonths(13)->toDateString()]);
        $this->record($workspace, $app, 'workers', 'Soon Sakala', 'inducted', ['company' => 'Bolt Electrical', 'induction_date' => today()->toDateString(), 'medical_expiry' => today()->addDays(10)->toDateString()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $lapsed->fresh()->status);
        $this->assertSame('inducted', $peter->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Expiring in 30 days')->assertSee('Soon Sakala');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Hours on site by company')->assertSee('Acme Scaffolding')->assertSee('8.5')->assertSee('Safety breach')->assertSee('Induction expired');
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
