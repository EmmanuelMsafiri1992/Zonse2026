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

class GovernmentAppsTest extends TestCase
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

    public function test_permit_applications_are_inspected_approved_and_issued_with_unique_licence_numbers(): void
    {
        [$owner, $workspace] = $this->appWorkspace('permits');
        $app = 'permits';

        $application = $this->record($workspace, $app, 'applications', 'Mama Njeri Kiosk', 'received', ['type' => 'business_licence', 'property' => 'Plot 12 Market St'], ['amount' => 2000, 'occurs_on' => today()->subDays(40)]);
        $this->assertSame(today()->subDays(10)->toDateString(), $application->fresh()->due_on->toDateString());
        $this->assertTrue((bool) $application->fresh()->value('_overdue'));

        $this->actingAs($owner)->post($application->url().'/actions/review')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Reviewing Mama Njeri Kiosk.');
        $this->assertSame('under_review', $application->fresh()->status);

        $this->actingAs($owner)->post($application->url().'/actions/inspect', ['occurs_on' => today()->addDays(3)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Inspection of Plot 12 Market St scheduled for '.today()->addDays(3)->format('d M Y').'.');
        $application = $application->fresh();
        $this->assertSame('inspection', $application->status);
        $this->assertSame(1, $application->value('_inspections'));
        $this->assertSame(today()->addDays(3)->toDateString(), $application->value('_next_inspection'));

        $this->actingAs($owner)->post($application->url().'/actions/approve')->assertSessionHasErrors('status');

        $inspection = Record::query()->where('entity', 'inspections')->firstOrFail();
        $this->actingAs($owner)->post($inspection->url().'/actions/pass')->assertSessionHasErrors('status');

        $inspection->update(['occurs_on' => today()->subDay()]);
        $this->actingAs($owner)->post($inspection->url().'/actions/fail', ['findings' => ''])->assertSessionHasErrors('findings');
        $this->actingAs($owner)->post($inspection->url().'/actions/fail', ['findings' => 'No fire extinguisher'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Inspection of Plot 12 Market St failed; a re-inspection is needed.');
        $this->assertSame('failed', $inspection->fresh()->status);
        $this->assertFalse((bool) $application->fresh()->value('_passed'));

        $this->actingAs($owner)->post($inspection->url().'/actions/reinspect', ['occurs_on' => today()->addDays(2)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Re-inspection of Plot 12 Market St scheduled for '.today()->addDays(2)->format('d M Y').'.');
        $this->assertSame('re_inspect', $inspection->fresh()->status);
        $this->assertSame(2, $application->fresh()->value('_inspections'));

        $second = Record::query()->where('entity', 'inspections')->where('status', 'scheduled')->firstOrFail();
        $second->update(['occurs_on' => today()]);
        $this->actingAs($owner)->post($second->url().'/actions/pass')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Inspection of Plot 12 Market St passed.');
        $application = $application->fresh();
        $this->assertSame('approved', $application->status);
        $this->assertTrue((bool) $application->value('_passed'));

        $this->actingAs($owner)->post($application->url().'/actions/issue', ['licence_number' => 'bl-2026-001', 'valid_until' => today()->subDay()->toDateString()])->assertSessionHasErrors('valid_until');
        $this->actingAs($owner)->post($application->url().'/actions/issue', ['licence_number' => 'bl-2026-001', 'valid_until' => today()->addDays(365)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Licence BL-2026-001 issued to Mama Njeri Kiosk, valid until '.today()->addDays(365)->format('d M Y').'.');
        $application = $application->fresh();
        $this->assertSame('issued', $application->status);
        $this->assertSame('BL-2026-001', $application->value('licence_number'));
        $this->assertSame(365, $application->value('_days_left'));
        $this->assertFalse((bool) $application->value('_overdue'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), [
            'title' => 'Copycat Shop', 'status' => 'received', 'amount' => 500,
            'data' => ['type' => 'trading_permit', 'licence_number' => 'BL-2026-001'],
        ])->assertSessionHasErrors('data.licence_number');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), [
            'title' => 'Back-dated', 'status' => 'received', 'amount' => 500,
            'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(),
            'data' => ['type' => 'signage'],
        ])->assertSessionHasErrors('due_on');

        $this->actingAs($owner)->get($application->url())->assertOk()->assertSee('Decision due');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Decisions overdue');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Applications by type');
    }

    public function test_risk_register_scores_risks_blocks_accepting_critical_ones_and_tracks_reviews(): void
    {
        [$owner, $workspace] = $this->appWorkspace('risk-management-register');
        $app = 'risk-management-register';

        $flood = $this->record($workspace, $app, 'risks', 'Data centre flood', 'identified', ['category' => 'it_cyber', 'likelihood' => '2_unlikely', 'impact' => '5_catastrophic']);
        $this->assertSame(10, $flood->fresh()->value('_score'));
        $this->assertSame('high', $flood->fresh()->value('_rating'));

        $this->actingAs($owner)->post($flood->url().'/actions/assess', ['likelihood' => '4_likely', 'impact' => '4_major', 'due_on' => today()->addDays(90)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Data centre flood assessed as critical (16).');
        $flood = $flood->fresh();
        $this->assertSame('assessed', $flood->status);
        $this->assertSame('critical', $flood->value('_rating'));

        $this->actingAs($owner)->post($flood->url().'/actions/accept')->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($flood->url().'/actions/mitigate', ['mitigation' => ''])->assertSessionHasErrors('mitigation');
        $this->actingAs($owner)->post($flood->url().'/actions/mitigate', ['mitigation' => 'Move the servers to the first floor'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Mitigating Data centre flood.');
        $this->assertSame('mitigating', $flood->fresh()->status);

        $this->actingAs($owner)->post($flood->url().'/actions/review', ['likelihood' => '2_unlikely', 'impact' => '4_major', 'due_on' => today()->toDateString()])->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post($flood->url().'/actions/review', ['likelihood' => '2_unlikely', 'impact' => '4_major', 'due_on' => today()->addDays(180)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Data centre flood reviewed: now medium (8).');
        $flood = $flood->fresh();
        $this->assertSame('mitigating', $flood->status);
        $this->assertSame(1, $flood->value('_reviews'));
        $this->assertSame(today()->addDays(180)->toDateString(), $flood->due_on->toDateString());

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'risks']), [
            'title' => 'Ransomware', 'status' => 'accepted', 'due_on' => today()->addDays(30)->toDateString(),
            'data' => ['category' => 'it_cyber', 'likelihood' => '5_almost_certain', 'impact' => '5_catastrophic'],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'risks']), [
            'title' => 'Key supplier failure', 'status' => 'assessed',
            'data' => ['category' => 'operational', 'likelihood' => '3_possible', 'impact' => '3_moderate'],
        ])->assertSessionHasErrors('due_on');

        $stale = $this->record($workspace, $app, 'risks', 'Stale review', 'assessed', ['category' => 'compliance', 'likelihood' => '1_rare', 'impact' => '1_insignificant'], ['due_on' => today()->subDays(5)]);
        $this->assertTrue((bool) $stale->fresh()->value('_review_overdue'));
        $this->assertSame('low', $stale->fresh()->value('_rating'));

        $this->actingAs($owner)->post($flood->url().'/actions/close')->assertSessionHas('flash.message', 'Data centre flood closed.');
        $this->assertSame(today()->toDateString(), $flood->fresh()->value('_closed_on'));
        $this->actingAs($owner)->post($flood->url().'/actions/reopen')->assertSessionHas('flash.message', 'Data centre flood reopened.');
        $this->assertSame('assessed', $flood->fresh()->status);

        $this->actingAs($owner)->get($flood->url())->assertOk()->assertSee('Rating');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Top risks');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Heat map');
    }

    public function test_incidents_raise_corrective_actions_and_close_only_with_a_root_cause_and_no_open_actions(): void
    {
        [$owner, $workspace] = $this->appWorkspace('health-safety-incident-reporting');
        $app = 'health-safety-incident-reporting';

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'incidents']), [
            'title' => 'Future slip', 'status' => 'reported', 'occurs_on' => today()->addDay()->toDateString(),
            'data' => ['type' => 'injury', 'severity' => 'serious', 'location' => 'Yard'],
        ])->assertSessionHasErrors(['occurs_on', 'data.reportable']);

        $incident = $this->record($workspace, $app, 'incidents', 'Forklift collision', 'reported', ['type' => 'injury', 'severity' => 'lost_time', 'location' => 'Warehouse B'], ['occurs_on' => today()->subDays(3)]);
        $this->assertTrue((bool) $incident->fresh()->value('_lost_time'));
        $this->assertSame(3, $incident->fresh()->value('_days_open'));

        $this->actingAs($owner)->post($incident->url().'/actions/investigate')->assertSessionHas('flash.message', 'Investigating Forklift collision.');

        $this->actingAs($owner)->post($incident->url().'/actions/raise_action', ['title' => 'Repaint floor markings', 'due_on' => today()->addDays(7)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Action "Repaint floor markings" due '.today()->addDays(7)->format('d M Y').'.');
        $incident = $incident->fresh();
        $this->assertSame('actions_open', $incident->status);
        $this->assertSame(1, $incident->value('_open_actions'));

        $this->actingAs($owner)->post($incident->url().'/actions/close', ['root_cause' => 'Blind corner'])->assertSessionHasErrors('status');

        $action = Record::query()->where('entity', 'actions')->firstOrFail();
        $this->actingAs($owner)->post($action->url().'/actions/done')->assertSessionHas('flash.message', 'Repaint floor markings done.');
        $this->assertSame(0, $incident->fresh()->value('_open_actions'));
        $this->actingAs($owner)->post($action->url().'/actions/verify')->assertSessionHas('flash.message', 'Repaint floor markings verified.');
        $this->assertSame(1, $incident->fresh()->value('_verified_actions'));

        $late = $this->record($workspace, $app, 'actions', 'Replace mirror', 'open', ['incident' => $incident->id], ['due_on' => today()->subDays(2)]);
        $this->assertTrue((bool) $late->fresh()->value('_overdue'));
        $this->assertSame(1, $incident->fresh()->value('_overdue_actions'));
        $this->actingAs($owner)->post($late->url().'/actions/done')->assertSessionHasNoErrors();

        $this->actingAs($owner)->post($incident->url().'/actions/close', ['root_cause' => ''])->assertSessionHasErrors('root_cause');
        $this->actingAs($owner)->post($incident->url().'/actions/close', ['root_cause' => 'Blind corner with no mirror'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Incident Forklift collision closed.');
        $incident = $incident->fresh();
        $this->assertSame('closed', $incident->status);
        $this->assertNull($incident->value('_days_open'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'actions']), [
            'title' => 'Too late', 'status' => 'open', 'due_on' => today()->addDay()->toDateString(),
            'data' => ['incident' => $incident->id],
        ])->assertSessionHasErrors('data.incident');

        $this->actingAs($owner)->get($incident->url())->assertOk()->assertSee('Corrective actions');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Days since lost time');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Incidents by severity');
    }

    public function test_service_desk_sets_service_levels_escalates_breaches_and_measures_resolution(): void
    {
        [$owner, $workspace] = $this->appWorkspace('e-citizen-service-desk-complaints');
        $app = 'e-citizen-service-desk-complaints';

        $pipe = $this->record($workspace, $app, 'requests', 'Burst pipe on Moi Avenue', 'received', ['type' => 'fault_report', 'channel' => 'phone', 'ward' => 'Central', 'description' => 'Water everywhere'], ['occurs_on' => today()->subDays(5)]);
        $pipe = $pipe->fresh();
        $this->assertSame(3, $pipe->value('_sla_days'));
        $this->assertSame(today()->subDays(2)->toDateString(), $pipe->due_on->toDateString());
        $this->assertTrue((bool) $pipe->value('_breached'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), [
            'title' => 'Tomorrow', 'status' => 'assigned', 'occurs_on' => today()->addDay()->toDateString(),
            'data' => ['type' => 'enquiry', 'description' => 'x'],
        ])->assertSessionHasErrors(['occurs_on', 'data.department']);

        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('escalated', $pipe->fresh()->status);

        $this->actingAs($owner)->post($pipe->url().'/actions/assign', ['department' => 'Water Department'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Burst pipe on Moi Avenue assigned to Water Department.');
        $this->assertSame('escalated', $pipe->fresh()->status);

        $this->actingAs($owner)->post($pipe->url().'/actions/resolve', ['resolution' => ''])->assertSessionHasErrors('resolution');
        $this->actingAs($owner)->post($pipe->url().'/actions/resolve', ['resolution' => 'Pipe replaced'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Burst pipe on Moi Avenue resolved 2 days past its service level.');
        $pipe = $pipe->fresh();
        $this->assertSame('resolved', $pipe->status);
        $this->assertFalse((bool) $pipe->value('_within_sla'));
        $this->assertSame(5, $pipe->value('_days_to_resolve'));

        $noise = $this->record($workspace, $app, 'requests', 'Noise complaint', 'received', ['type' => 'complaint', 'channel' => 'website', 'ward' => 'Westlands', 'description' => 'Bar music past midnight']);
        $this->assertSame(today()->addDays(5)->toDateString(), $noise->fresh()->due_on->toDateString());
        $this->actingAs($owner)->post($noise->url().'/actions/assign', ['department' => 'Environment'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($noise->url().'/actions/start')->assertSessionHas('flash.message', 'Work on Noise complaint started.');
        $this->actingAs($owner)->post($noise->url().'/actions/resolve', ['resolution' => 'Owner warned'])
            ->assertSessionHas('flash.message', 'Noise complaint resolved within its service level.');
        $this->actingAs($owner)->post($noise->url().'/actions/close')->assertSessionHas('flash.message', 'Noise complaint closed.');
        $this->assertSame('closed', $noise->fresh()->status);

        $this->actingAs($owner)->post($pipe->url().'/actions/reopen')->assertSessionHas('flash.message', 'Burst pipe on Moi Avenue reopened.');
        $pipe = $pipe->fresh();
        $this->assertSame('assigned', $pipe->status);
        $this->assertNull($pipe->value('_resolved_on'));

        $this->actingAs($owner)->get($pipe->url())->assertOk()->assertSee('Service level');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Past service level');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Requests by ward');
    }

    public function test_council_bills_rateable_properties_collects_payments_and_flags_arrears(): void
    {
        [$owner, $workspace] = $this->appWorkspace('council-revenue');
        $app = 'council-revenue';

        $plot = $this->record($workspace, $app, 'properties', 'Plot 45 Ngong Road', 'active', ['owner' => 'J. Kamau', 'category' => 'residential', 'valuation' => 5000000]);

        $this->actingAs($owner)->post($plot->url().'/actions/bill', ['title' => 'Oct 2026', 'due_on' => today()->addDays(30)->toDateString()])->assertSessionHasErrors('rates');
        $this->actingAs($owner)->post($plot->url().'/actions/bill', ['title' => 'Oct 2026', 'rates' => 1200, 'refuse' => 300, 'due_on' => today()->addDays(30)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Rates bill for Oct 2026 issued to Plot 45 Ngong Road.');
        $bill = Record::query()->where('entity', 'bills')->firstOrFail();
        $this->assertSame(1500.0, (float) $bill->amount);
        $this->assertSame(1500.0, (float) $bill->value('_balance'));
        $this->assertSame(1500.0, (float) $plot->fresh()->value('balance'));
        $this->assertSame(1, $plot->fresh()->value('_unpaid_bills'));

        $this->actingAs($owner)->post($bill->url().'/actions/pay', ['amount' => 2000])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post($bill->url().'/actions/pay', ['amount' => 500])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Payment received; the bill for Oct 2026 still has a balance.');
        $bill = $bill->fresh();
        $this->assertSame('part_paid', $bill->status);
        $this->assertSame(1000.0, (float) $bill->value('_balance'));

        $september = $this->record($workspace, $app, 'bills', 'Sep 2026', 'billed', ['property' => $plot->id, 'rates' => 800], ['occurs_on' => today()->subDays(40), 'due_on' => today()->subDays(10)]);
        $this->assertSame(800.0, (float) $september->fresh()->amount);
        $this->assertSame(1800.0, (float) $plot->fresh()->value('balance'));

        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('overdue', $september->fresh()->status);
        $plot = $plot->fresh();
        $this->assertSame('in_arrears', $plot->status);
        $this->assertSame(1, $plot->value('_overdue_bills'));
        $this->actingAs($owner)->post($plot->url().'/actions/exempt')->assertNotFound();

        $church = $this->record($workspace, $app, 'properties', 'St Peter Church', 'exempt', ['owner' => 'Diocese', 'category' => 'residential']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bills']), [
            'title' => 'Oct 2026', 'status' => 'billed', 'amount' => 100, 'data' => ['property' => $church->id, 'rates' => 100],
        ])->assertSessionHasErrors('data.property');

        $this->actingAs($owner)->post($september->url().'/actions/pay', ['amount' => 800])
            ->assertSessionHas('flash.message', 'Payment received; the bill for Sep 2026 is paid in full.');
        $this->assertSame('paid', $september->fresh()->status);
        $plot = $plot->fresh();
        $this->assertSame('active', $plot->status);
        $this->assertSame(1000.0, (float) $plot->value('balance'));

        $this->actingAs($owner)->post($bill->url().'/actions/pay', ['amount' => 1000])->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $plot->fresh()->value('balance'));
        $this->assertSame(2300.0, (float) $plot->fresh()->value('_paid_year'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'properties']), [
            'title' => 'Plot 9', 'status' => 'handed_over', 'data' => ['owner' => 'Nobody', 'category' => 'vacant_land'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->get($plot->url())->assertOk()->assertSee('Overdue bills');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Largest arrears');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Billing by month');
    }

    public function test_court_files_keep_case_numbers_unique_set_hearings_down_without_clashes_and_follow_the_case_to_judgment(): void
    {
        [$owner, $workspace] = $this->appWorkspace('court-case-management-cause');
        $app = 'court-case-management-cause';

        $otieno = $this->record($workspace, $app, 'cases', 'Republic v Otieno', 'filed', ['case_number' => 'cr-123/2026', 'court' => 'Milimani', 'type' => 'criminal'], ['occurs_on' => today()->subDays(20)]);
        $this->assertSame('CR-123/2026', $otieno->fresh()->value('case_number'));
        $this->assertSame(20, $otieno->fresh()->value('_days_pending'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'cases']), [
            'title' => 'Republic v Otieno (dup)', 'status' => 'filed', 'occurs_on' => today()->addDay()->toDateString(),
            'data' => ['case_number' => 'CR-123/2026', 'type' => 'criminal'],
        ])->assertSessionHasErrors(['data.case_number', 'occurs_on']);

        $this->actingAs($owner)->post($otieno->url().'/actions/set_down', ['title' => 'Plea', 'occurs_on' => today()->addDays(7)->toDateString(), 'time' => '09:00', 'courtroom' => 'Court 3'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Plea in Republic v Otieno set down for '.today()->addDays(7)->format('d M Y').' at 09:00 in Court 3.');
        $otieno = $otieno->fresh();
        $this->assertSame('pending', $otieno->status);
        $this->assertSame(today()->addDays(7)->toDateString(), $otieno->value('_next_hearing'));
        $this->assertSame('Court 3', $otieno->value('_next_courtroom'));

        $wanjiku = $this->record($workspace, $app, 'cases', 'Wanjiku v Kamau', 'filed', ['case_number' => 'civ-45/2026', 'court' => 'Milimani', 'type' => 'civil']);
        $this->actingAs($owner)->post($wanjiku->url().'/actions/set_down', ['title' => 'Mention', 'occurs_on' => today()->addDays(7)->toDateString(), 'time' => '09:00', 'courtroom' => 'court 3'])->assertSessionHasErrors('courtroom');
        $this->actingAs($owner)->post($wanjiku->url().'/actions/set_down', ['title' => 'Mention', 'occurs_on' => today()->addDays(7)->toDateString(), 'time' => '11:00', 'courtroom' => 'Court 3'])->assertSessionHasNoErrors();

        $plea = Record::query()->where('entity', 'hearings')->where('title', 'Plea')->firstOrFail();
        $this->actingAs($owner)->post($plea->url().'/actions/heard', ['outcome' => 'Not guilty'])->assertSessionHasErrors('status');
        $plea->update(['occurs_on' => today()]);
        $this->actingAs($owner)->post($plea->url().'/actions/heard', ['outcome' => 'Plea of not guilty'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Plea heard: Plea of not guilty.');
        $otieno = $otieno->fresh();
        $this->assertSame('part_heard', $otieno->status);
        $this->assertSame(1, $otieno->value('_heard'));
        $this->assertSame('Plea of not guilty', $otieno->value('_last_outcome'));

        $mention = Record::query()->where('entity', 'hearings')->where('title', 'Mention')->firstOrFail();
        $this->actingAs($owner)->post($mention->url().'/actions/postpone', ['occurs_on' => today()->addDays(14)->toDateString()])->assertSessionHasErrors('status');
        $mention->update(['occurs_on' => today()->subDay()]);
        $this->actingAs($owner)->post($mention->url().'/actions/postpone', ['occurs_on' => today()->addDays(14)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Mention postponed to '.today()->addDays(14)->format('d M Y').'.');
        $this->assertSame('postponed', $mention->fresh()->status);
        $wanjiku = $wanjiku->fresh();
        $this->assertSame(1, $wanjiku->value('_postponed'));
        $this->assertSame(2, $wanjiku->value('_hearings'));
        $this->assertSame(today()->addDays(14)->toDateString(), $wanjiku->value('_next_hearing'));

        $this->actingAs($owner)->post($otieno->url().'/actions/reserve_judgment')->assertSessionHas('flash.message', 'Judgment reserved in Republic v Otieno.');
        $this->actingAs($owner)->post($otieno->url().'/actions/decide')->assertSessionHas('flash.message', 'Republic v Otieno decided.');
        $this->assertSame(today()->toDateString(), $otieno->fresh()->value('_decided_on'));
        $this->actingAs($owner)->post($otieno->url().'/actions/appeal')->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($otieno->url().'/actions/close')->assertSessionHas('flash.message', 'Republic v Otieno closed.');
        $this->assertSame('closed', $otieno->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'hearings']), [
            'title' => 'Mention', 'status' => 'scheduled', 'occurs_on' => today()->addDays(3)->toDateString(),
            'data' => ['case' => $otieno->id],
        ])->assertSessionHasErrors('data.case');

        $this->actingAs($owner)->get($otieno->url())->assertOk()->assertSee('Next hearing');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('cause list');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Hearings by courtroom');
    }
}
