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

class GovernmentAppsBatchThreeTest extends TestCase
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

    public function test_grants_stay_inside_the_programme_budget_and_the_programme_completes_once_every_grant_is_settled(): void
    {
        $app = 'grants-subsidies-management';
        [$owner, $workspace] = $this->appWorkspace($app);
        $programme = $this->record($workspace, $app, 'programmes', 'Youth enterprise fund', 'open', ['budget' => 100000], ['occurs_on' => today(), 'due_on' => today()->addMonth()]);
        $closed = $this->record($workspace, $app, 'programmes', 'Farm inputs 2025', 'closed', ['budget' => 5000], ['occurs_on' => today()->subYear(), 'due_on' => today()->subMonths(6)]);
        $kanyama = $this->record($workspace, $app, 'applications', 'Kanyama Welders', 'submitted', ['programme' => $programme->id, 'requested' => 60000], ['amount' => 0]);
        $chawama = $this->record($workspace, $app, 'applications', 'Chawama Bakers', 'screening', ['programme' => $programme->id, 'requested' => 50000], ['amount' => 0]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), [
            'title' => 'kanyama welders', 'status' => 'submitted', 'data' => ['programme' => $programme->id, 'requested' => 1000],
        ])->assertSessionHasErrors('title');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), [
            'title' => 'Late Grower', 'status' => 'submitted', 'data' => ['programme' => $closed->id, 'requested' => 1000],
        ])->assertSessionHasErrors('data.programme');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $kanyama->id, 'approve']), ['amount' => 50000])->assertNotFound();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $kanyama->id, 'screen']))->assertSessionHas('flash.message', 'Screening Kanyama Welders.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $kanyama->id, 'approve']), ['amount' => 70000])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $kanyama->id, 'approve']), ['amount' => 60000, 'score' => 82])
            ->assertSessionHas('flash.message', 'Kanyama Welders approved.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $chawama->id, 'approve']), ['amount' => 50000])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $chawama->id, 'reject']), ['reason' => 'Budget exhausted'])
            ->assertSessionHas('flash.message', 'Chawama Bakers rejected.');

        $programme->refresh();
        $this->assertEquals(60000, $programme->value('_awarded'));
        $this->assertEquals(40000, $programme->value('_remaining'));
        $this->assertSame(1, $programme->value('_grants'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'programmes', $programme->id]), [
            'title' => $programme->title, 'status' => 'open', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addMonth()->toDateString(), 'data' => ['budget' => 50000],
        ])->assertSessionHasErrors('data.budget');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $kanyama->id, 'disburse']), ['report_due' => today()->addMonths(6)->toDateString()])
            ->assertSessionHas('flash.message', 'Grant to Kanyama Welders disbursed; report due '.today()->addMonths(6)->format('d M Y').'.');
        $this->assertEquals(60000, $programme->fresh()->value('_disbursed'));

        $kanyama->refresh();
        $kanyama->update(['data' => [...$kanyama->data, 'report_due' => today()->subDay()->toDateString()]]);
        $this->assertTrue($kanyama->fresh()->value('_report_overdue'));
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('reporting', $kanyama->fresh()->status);
        $this->assertSame('closed', $closed->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'programmes', $programme->id, 'close']))->assertSessionHas('flash.message', 'Youth enterprise fund is closed to applications.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'programmes', $programme->id, 'complete']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $kanyama->id, 'close']))
            ->assertSessionHas('flash.message', 'Report from Kanyama Welders received; grant closed.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'programmes', $programme->id, 'complete']))->assertSessionHas('flash.message', 'Youth enterprise fund completed.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'programmes', $programme->id]))->assertOk()->assertSee('Applications');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Reports due');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Applications by month');
    }

    public function test_privacy_requests_need_verified_identity_and_breaches_are_judged_against_the_72_hour_deadline(): void
    {
        $app = 'data-privacy-gdpr-popia';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'processing']), [
            'title' => 'Payroll', 'status' => 'active', 'data' => ['purpose' => 'Paying staff', 'lawful_basis' => 'contract'],
        ])->assertSessionHasErrors('data.retention');
        $payroll = $this->record($workspace, $app, 'processing', 'Payroll', 'active', ['purpose' => 'Paying staff', 'lawful_basis' => 'contract', 'retention' => '7 years']);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'processing', $payroll->id, 'retire']))->assertSessionHas('flash.message', 'Payroll retired.');
        $this->assertNotNull($payroll->fresh()->value('_retired_on'));

        $request = $this->record($workspace, $app, 'requests', 'Natasha Phiri', 'received', ['type' => 'access', 'email' => 'natasha@example.com'], ['occurs_on' => today()->subDays(40), 'due_on' => null]);
        $this->assertTrue($request->due_on->isSameDay(today()->subDays(10)));
        $this->assertTrue($request->value('_overdue'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'requests', $request->id]), [
            'title' => 'Natasha Phiri', 'status' => 'in_progress', 'occurs_on' => today()->subDays(40)->toDateString(), 'due_on' => $request->due_on->toDateString(), 'data' => ['type' => 'access'],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'requests', $request->id, 'extend']), ['days' => 30, 'reason' => 'Large archive'])->assertNotFound();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'requests', $request->id, 'verified']))->assertSessionHas('flash.message', 'Natasha Phiri\'s identity verified.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'requests', $request->id, 'extend']), ['days' => 90, 'reason' => 'Large archive'])->assertSessionHasErrors('days');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'requests', $request->id, 'extend']), ['days' => 30, 'reason' => 'Large archive'])
            ->assertSessionHas('flash.message', 'Deadline for Natasha Phiri extended to '.today()->addDays(20)->format('d M Y').'.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'requests', $request->id, 'extend']), ['days' => 10, 'reason' => 'Again'])->assertNotFound();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'requests', $request->id, 'complete']), ['response' => 'Copy of records emailed.'])
            ->assertSessionHas('flash.message', 'Request from Natasha Phiri completed on time.');
        $request->refresh();
        $this->assertSame(40, $request->value('_days_to_respond'));
        $this->assertFalse($request->value('_overdue'));

        $breach = $this->record($workspace, $app, 'breaches', 'Laptop stolen from car', 'detected', ['records_affected' => 1200], ['occurs_on' => today()->subDays(5)]);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'breaches', $breach->id]), [
            'title' => $breach->title, 'status' => 'closed', 'occurs_on' => today()->subDays(5)->toDateString(), 'data' => ['records_affected' => 1200],
        ])->assertSessionHasErrors('data.actions');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'breaches', $breach->id, 'contain']))->assertSessionHas('flash.message', 'Laptop stolen from car contained.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'breaches', $breach->id, 'notify']), ['subjects_notified' => '1'])
            ->assertSessionHas('flash.message', 'Regulator notified of Laptop stolen from car, after the 72-hour deadline.');
        $breach->refresh();
        $this->assertTrue($breach->value('regulator_notified'));
        $this->assertTrue($breach->value('subjects_notified'));
        $this->assertTrue($breach->value('_notified_late'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'breaches', $breach->id, 'close']), ['actions' => 'Remote wipe, staff retrained.'])
            ->assertSessionHas('flash.message', 'Laptop stolen from car closed.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'breaches', $breach->id]))->assertOk()->assertSee('Notify regulator by');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Requests due');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Requests by type');
    }

    public function test_environmental_samples_judge_themselves_against_their_limit_and_permits_expire_and_renew(): void
    {
        $app = 'environmental-monitoring-permits';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'permits']), [
            'title' => 'Mine tailings discharge', 'status' => 'active', 'data' => ['type' => 'water_use', 'permit_number' => 'WU-9'],
        ])->assertSessionHasErrors('due_on');
        $permit = $this->record($workspace, $app, 'permits', 'Mine tailings discharge', 'applied', ['type' => 'water_use'], ['due_on' => null]);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'permits', $permit->id, 'issue']), ['permit_number' => 'env-001', 'due_on' => today()->addYears(5)->toDateString()])
            ->assertSessionHas('flash.message', 'Permit ENV-001 issued, valid until '.today()->addYears(5)->format('d M Y').'.');
        $this->assertSame('active', $permit->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'permits']), [
            'title' => 'Copy', 'status' => 'applied', 'data' => ['type' => 'waste', 'permit_number' => 'ENV-001'],
        ])->assertSessionHasErrors('data.permit_number');

        $outfall = $this->record($workspace, $app, 'samples', 'Outfall 1', 'collected', ['permit' => $permit->id, 'medium' => 'water', 'parameter' => 'pH'], ['occurs_on' => today()]);
        $this->record($workspace, $app, 'samples', 'Upstream', 'collected', ['permit' => $permit->id, 'medium' => 'water', 'parameter' => 'pH', 'result' => 7.1, 'limit' => 8.5], ['occurs_on' => today()]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'samples']), [
            'title' => 'Borehole', 'status' => 'compliant', 'occurs_on' => today()->toDateString(), 'data' => ['permit' => $permit->id, 'medium' => 'water', 'parameter' => 'Lead'],
        ])->assertSessionHasErrors('data.result');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'samples', $outfall->id, 'send_to_lab']), ['lab_reference' => 'LAB-77'])
            ->assertSessionHas('flash.message', 'pH sample from Outfall 1 sent to the lab.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'samples', $outfall->id, 'record_result']), ['result' => 9.2, 'limit' => 8.5])
            ->assertSessionHas('flash.message', 'pH at Outfall 1 exceeds its limit of 8.5.');
        $outfall->refresh();
        $this->assertSame('non_compliant', $outfall->status);
        $this->assertEquals(0.7, $outfall->value('_exceedance'));

        $permit->refresh();
        $this->assertSame(2, $permit->value('_samples'));
        $this->assertSame(1, $permit->value('_exceedances'));
        $this->assertSame(50, $permit->value('_compliance'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'permits', $permit->id, 'suspend']), ['reason' => 'Repeated pH exceedances'])->assertSessionHas('flash.message', 'Permit ENV-001 suspended.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'permits', $permit->id, 'reinstate']))->assertSessionHas('flash.message', 'Permit ENV-001 reinstated.');

        Record::query()->whereKey($permit->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $permit->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'permits', $permit->id, 'renew']), ['due_on' => today()->addYears(5)->toDateString()])
            ->assertSessionHas('flash.message', 'Permit ENV-001 renewed until '.today()->addYears(5)->format('d M Y').'.');
        $this->assertSame('active', $permit->fresh()->status);

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'permits', $permit->id]))->assertOk()->assertSee('Exceedances');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Latest exceedances');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Samples by parameter');
    }

    public function test_bills_move_one_stage_at_a_time_votes_follow_the_majority_and_adjourned_business_carries_forward(): void
    {
        $app = 'parliament-council';
        [$owner, $workspace] = $this->appWorkspace($app);
        $sitting = $this->record($workspace, $app, 'sittings', 'Ordinary sitting 12', 'scheduled', ['type' => 'ordinary', 'venue' => 'Main chamber'], ['occurs_on' => today()]);
        $bill = $this->record($workspace, $app, 'items', 'Local Rates Bill', 'tabled', ['type' => 'bill', 'sitting' => $sitting->id, 'sponsor' => 'Cllr Zulu'], ['occurs_on' => today()]);
        $motion = $this->record($workspace, $app, 'items', 'Motion to resurface Cairo Road', 'tabled', ['type' => 'motion', 'sitting' => $sitting->id, 'sponsor' => 'Cllr Mwale'], ['occurs_on' => today()]);
        $question = $this->record($workspace, $app, 'items', 'Question on street lights', 'tabled', ['type' => 'question', 'sitting' => $sitting->id, 'sponsor' => 'Cllr Tembo'], ['occurs_on' => today()]);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'items', $bill->id, 'vote']), ['votes_for' => 20, 'votes_against' => 3])->assertNotFound();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'items', $bill->id, 'advance']))->assertSessionHas('flash.message', 'Local Rates Bill moves to first reading.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'items', $bill->id, 'advance']))->assertSessionHas('flash.message', 'Local Rates Bill moves to committee.');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'items', $bill->id]), [
            'title' => $bill->title, 'status' => 'passed', 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'bill', 'sitting' => $sitting->id, 'votes_for' => 20, 'votes_against' => 3],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'items', $bill->id, 'advance']))->assertSessionHas('flash.message', 'Local Rates Bill moves to second reading.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'items', $bill->id, 'advance']))->assertNotFound();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'items', $bill->id, 'vote']), ['votes_for' => 0, 'votes_against' => 0])->assertSessionHasErrors('votes_for');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'items', $bill->id, 'vote']), ['votes_for' => 20, 'votes_against' => 3, 'resolution' => 'Adopted'])
            ->assertSessionHas('flash.message', 'Local Rates Bill passed, 20 votes to 3.');

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'items', $motion->id]), [
            'title' => $motion->title, 'status' => 'passed', 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'motion', 'sitting' => $sitting->id, 'votes_for' => 10, 'votes_against' => 12],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'items', $motion->id, 'vote']), ['votes_for' => 10, 'votes_against' => 12])
            ->assertSessionHas('flash.message', 'Motion to resurface Cairo Road rejected, 10 votes to 12.');
        $this->assertSame(-2, $motion->fresh()->value('_majority'));

        $sitting->refresh();
        $this->assertSame(3, $sitting->value('_items'));
        $this->assertSame(1, $sitting->value('_passed'));
        $this->assertSame(1, $sitting->value('_rejected'));
        $this->assertSame(1, $sitting->value('_pending'));

        $nextWeek = today()->addWeek();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'sittings', $sitting->id, 'adjourn']), ['occurs_on' => $nextWeek->toDateString()])
            ->assertSessionHas('flash.message', 'Ordinary sitting 12 adjourned to '.$nextWeek->format('d M Y').' with 1 item carried forward.');
        $resumed = Record::query()->ofEntity($app, 'sittings')->where('status', 'scheduled')->firstOrFail();
        $this->assertSame('Ordinary sitting 12 (resumed)', $resumed->title);
        $this->assertSame($resumed->id, (int) $question->fresh()->value('sitting'));
        $this->assertSame((string) $sitting->id, (string) $bill->fresh()->value('sitting'));
        $this->assertSame(1, $resumed->fresh()->value('_pending'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'sittings', $resumed->id, 'hold']))->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'sittings', $sitting->id]))->assertOk()->assertSee('Order paper');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Bills in progress');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Business by type');
    }

    public function test_inmates_are_sentenced_released_only_when_due_and_visits_are_booked_for_inmates_in_custody(): void
    {
        $app = 'prison-correctional-records';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'inmates']), [
            'title' => 'John Mbewe', 'status' => 'sentenced', 'occurs_on' => today()->toDateString(), 'data' => ['inmate_number' => 'A1'],
        ])->assertSessionHasErrors(['data.sentence', 'data.release_date']);
        $inmate = $this->record($workspace, $app, 'inmates', 'John Mbewe', 'remand', ['inmate_number' => 'inm-1', 'offence' => 'Theft', 'cell' => 'B2'], ['occurs_on' => today()->subDays(30)]);
        $this->assertSame('INM-1', $inmate->value('inmate_number'));
        $this->assertSame(30, $inmate->value('_days_in_custody'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'inmates']), [
            'title' => 'Someone Else', 'status' => 'remand', 'occurs_on' => today()->toDateString(), 'data' => ['inmate_number' => 'INM-1'],
        ])->assertSessionHasErrors('data.inmate_number');

        $release = today()->addYear();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'inmates', $inmate->id, 'sentence']), ['sentence' => '12 months', 'release_date' => $release->toDateString()])
            ->assertSessionHas('flash.message', 'John Mbewe sentenced to 12 months; release due '.$release->format('d M Y').'.');
        $this->assertSame((int) today()->diffInDays($release), $inmate->fresh()->value('_days_to_release'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'inmates', $inmate->id, 'release']))->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [
            'title' => 'Grace Mbewe', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['inmate' => $inmate->id, 'relationship' => 'Sister'],
        ])->assertSessionHasErrors('data.id_number');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [
            'title' => 'Grace Mbewe', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['inmate' => $inmate->id, 'relationship' => 'Sister', 'id_number' => '123456/10/1'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [
            'title' => 'Peter Mbewe', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['inmate' => $inmate->id, 'id_number' => '654321/10/1'],
        ])->assertSessionHasErrors('occurs_on');
        $visit = Record::query()->ofEntity($app, 'visits')->firstOrFail();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'visits', $visit->id, 'complete']))->assertSessionHas('flash.message', 'Visit by Grace Mbewe done.');
        $this->assertSame(1, $inmate->fresh()->value('_visits'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'inmates', $inmate->id, 'escape']))->assertSessionHas('flash.message', 'John Mbewe recorded as escaped.');
        $this->assertNotNull($inmate->fresh()->value('_left_on'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [
            'title' => 'Grace Mbewe', 'status' => 'booked', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['inmate' => $inmate->id, 'id_number' => '123456/10/1'],
        ])->assertSessionHasErrors('data.inmate');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'inmates', $inmate->id, 'recapture']))->assertSessionHas('flash.message', 'John Mbewe recaptured and back on sentenced.');
        $this->assertNull($inmate->fresh()->value('_left_on'));

        $due = $this->record($workspace, $app, 'inmates', 'Moses Lungu', 'sentenced', ['inmate_number' => 'INM-2', 'sentence' => '6 months', 'release_date' => today()->toDateString(), 'cell' => 'C1'], ['occurs_on' => today()->subMonths(6)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Due for release')->assertSee('Moses Lungu');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'inmates', $due->id, 'release']))->assertSessionHas('flash.message', 'Moses Lungu released.');
        $this->assertSame('released', $due->fresh()->status);

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'inmates', $inmate->id]))->assertOk()->assertSee('Days in custody');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Population by cell');
    }
}
