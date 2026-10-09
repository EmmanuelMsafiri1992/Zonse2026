<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The construction apps' rules, batch two: drawings & document control, practice management, surveying, contractor job cards and solar installer. */
class ConstructionAppsBatchTwoTest extends TestCase
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

    public function test_drawings_keep_one_current_revision_and_transmittals_only_send_current_drawings(): void
    {
        $app = 'drawings-document-control';
        [$owner, $workspace] = $this->appWorkspace($app);
        $revA = $this->record($workspace, $app, 'drawings', 'Ground floor plan', 'for_approval', ['drawing_number' => 'A-100', 'revision' => 'a', 'discipline' => 'architectural'], ['occurs_on' => today()->subDays(10)]);
        $this->assertSame('A', $revA->value('revision'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'drawings']), [
            'title' => 'Ground floor plan', 'status' => 'for_approval', 'data' => ['drawing_number' => 'A-100', 'revision' => 'A', 'discipline' => 'architectural'],
        ])->assertSessionHasErrors('data.revision');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'drawings']), [
            'title' => 'Ground floor plan', 'status' => 'as_built', 'data' => ['drawing_number' => 'A-100', 'revision' => 'B', 'discipline' => 'architectural'],
        ])->assertSessionHasErrors('status');

        $revB = $this->record($workspace, $app, 'drawings', 'Ground floor plan', 'for_approval', ['drawing_number' => 'A-100', 'revision' => 'B', 'discipline' => 'architectural'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transmittals']), [
            'title' => 'Main contractor', 'status' => 'sent', 'data' => ['drawings' => "A-100 rev B\nA-999 rev A", 'purpose' => 'construction'],
        ])->assertSessionHasErrors('data.drawings');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transmittals']), [
            'title' => 'Main contractor', 'status' => 'sent', 'data' => ['drawings' => 'A-100 rev B', 'purpose' => 'construction'],
        ])->assertSessionHasErrors('data.drawings');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'drawings', $revB->id, 'issue']))->assertRedirect();
        $this->assertSame('for_construction', $revB->fresh()->status);
        $this->assertSame('superseded', $revA->fresh()->status);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'drawings', $revA->id]), [
            'title' => 'Ground floor plan', 'status' => 'for_approval', 'data' => ['drawing_number' => 'A-100', 'revision' => 'A', 'discipline' => 'architectural'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transmittals']), [
            'title' => 'Main contractor', 'status' => 'sent', 'data' => ['drawings' => "A-100 rev B\n", 'purpose' => 'construction'],
        ])->assertRedirect();
        $transmittal = Record::query()->ofEntity($app, 'transmittals')->latest('id')->firstOrFail();
        $this->assertSame(1, $transmittal->value('_count'));

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'drawings', $revB->id]))->assertOk()->assertSee('Revision history')->assertSee('Rev A');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Register')->assertSee('Awaiting acknowledgement')->assertSee('Main contractor');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'transmittals', $transmittal->id, 'acknowledge']))->assertRedirect();
        $this->assertSame('acknowledged', $transmittal->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Register by discipline')->assertSee('Transmittals by purpose');
    }

    public function test_practice_management_bills_time_within_the_fee_and_moves_commissions_through_stages(): void
    {
        $app = 'architecture-engineering-practice-management';
        [$owner, $workspace] = $this->appWorkspace($app);
        $client = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Lusaka Heights Ltd']);
        $proposal = $this->record($workspace, $app, 'commissions', 'Clinic extension', 'proposal', ['stage' => 'inception'], ['amount' => 5000]);
        $house = $this->record($workspace, $app, 'commissions', 'Hillside house', 'appointed', ['stage' => 'concept', 'fee_basis' => 'lump_sum'], ['amount' => 10000, 'contact_id' => $client->id]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'timesheets']), [
            'title' => 'Sketches', 'status' => 'logged', 'data' => ['commission' => $proposal->id, 'hours' => 2],
        ])->assertSessionHasErrors('data.commission');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'timesheets']), [
            'title' => 'Sketches', 'status' => 'logged', 'data' => ['commission' => $house->id, 'hours' => 30],
        ])->assertSessionHasErrors('data.hours');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'timesheets']), [
            'title' => 'Sketches', 'status' => 'logged', 'data' => ['commission' => $house->id, 'hours' => 4],
        ])->assertRedirect();
        $entry = Record::query()->ofEntity($app, 'timesheets')->latest('id')->firstOrFail();
        $this->assertSame('concept', $entry->value('stage'));
        $this->assertEquals(4, $house->fresh()->value('_hours'));
        $this->assertEquals(4, $house->fresh()->value('_unbilled_hours'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'commissions', $house->id]), [
            'title' => 'Hillside house', 'status' => 'completed', 'amount' => 10000, 'data' => ['stage' => 'concept', 'fee_basis' => 'lump_sum'],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'commissions', $house->id]), [
            'title' => 'Hillside house', 'status' => 'in_progress', 'amount' => 10000, 'data' => ['stage' => 'concept', 'fee_basis' => 'lump_sum', 'fee_invoiced' => 20000],
        ])->assertSessionHasErrors('data.fee_invoiced');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'commissions', $house->id, 'bill_time']), ['fee_invoiced' => 3000])->assertRedirect();
        $this->assertSame('billed', $entry->fresh()->status);
        $this->assertEquals(0, $house->fresh()->value('_unbilled_hours'));
        $this->assertEquals(7000, $house->fresh()->value('_fee_remaining'));
        $this->assertEquals(30, $house->fresh()->value('_invoiced_pct'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'timesheets', $entry->id]), [
            'title' => 'Sketches', 'status' => 'billed', 'data' => ['commission' => $house->id, 'hours' => 6],
        ])->assertSessionHasErrors('data.hours');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'commissions', $house->id, 'advance_stage']))->assertRedirect();
        $this->assertSame('design_development', $house->fresh()->value('stage'));
        $this->assertSame('in_progress', $house->fresh()->status);

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'commissions', $house->id]))->assertOk()->assertSee('Fees & time')->assertSee('Hours by stage')->assertSee('7,000.00');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Practice')->assertSee('Fees to invoice')->assertSee('Hillside house');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Fees by client')->assertSee('Lusaka Heights Ltd')->assertSee('Hours by stage');
    }

    public function test_surveying_jobs_go_to_the_field_with_a_free_surveyor_and_only_cadastral_surveys_are_lodged(): void
    {
        $app = 'surveying-gis-jobs';
        [$owner, $workspace] = $this->appWorkspace($app);
        $plot = $this->record($workspace, $app, 'jobs', 'Plot 1234 Chalala', 'booked', ['type' => 'cadastral', 'surveyor' => $owner->id, 'coordinates' => '-15.4167, 28.2833'], ['occurs_on' => today(), 'due_on' => today()->addDays(10), 'amount' => 2500]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), [
            'title' => 'Farm boundary', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'topographic', 'coordinates' => 'somewhere north'],
        ])->assertSessionHasErrors('data.coordinates');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), [
            'title' => 'Farm boundary', 'status' => 'fieldwork', 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'topographic'],
        ])->assertSessionHasErrors('data.surveyor');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $plot->id, 'start_fieldwork']))->assertRedirect();
        $this->assertSame('fieldwork', $plot->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), [
            'title' => 'Farm boundary', 'status' => 'fieldwork', 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'topographic', 'surveyor' => $owner->id],
        ])->assertSessionHasErrors('data.surveyor');
        $farm = $this->record($workspace, $app, 'jobs', 'Farm boundary', 'processing', ['type' => 'topographic', 'surveyor' => $owner->id, 'deliverables' => 'Contour plan'], ['occurs_on' => today()->subDay()]);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'jobs', $farm->id]), [
            'title' => 'Farm boundary', 'status' => 'lodged', 'occurs_on' => today()->subDay()->toDateString(), 'data' => ['type' => 'topographic', 'surveyor' => $owner->id, 'deliverables' => 'Contour plan', 'diagram_number' => 'SG 1/2026'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $plot->id, 'process']))->assertRedirect();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $plot->id, 'deliver']), ['deliverables' => ''])->assertSessionHasErrors('deliverables');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $plot->id, 'deliver']), ['deliverables' => 'Survey diagram and beacon certificate'])->assertRedirect();
        $this->assertSame('delivered', $plot->fresh()->status);
        $this->assertSame(today()->toDateString(), $plot->fresh()->value('_delivered_on'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $plot->id, 'lodge']), ['diagram_number' => 'SG 1/2026'])->assertRedirect();
        $this->assertSame('lodged', $plot->fresh()->status);

        $other = $this->record($workspace, $app, 'jobs', 'Plot 1235 Chalala', 'delivered', ['type' => 'cadastral', 'surveyor' => $owner->id, 'deliverables' => 'Diagram'], ['occurs_on' => today()->subDays(3)]);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $other->id, 'lodge']), ['diagram_number' => 'SG 1/2026'])->assertSessionHasErrors('diagram_number');
        $this->assertSame('delivered', $other->fresh()->status);

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'jobs', $plot->id]))->assertOk()->assertSee('Survey')->assertSee('Cadastral');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Jobs')->assertSee("This week's field work");
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Jobs by type')->assertSee('Surveyor workload')->assertSee($owner->name);
    }

    public function test_contractor_job_cards_send_one_technician_at_a_time_and_need_certificates_and_sign_off(): void
    {
        $app = 'contractor-jobs';
        [$owner, $workspace] = $this->appWorkspace($app);
        $customer = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Mrs Phiri']);
        $rewire = $this->record($workspace, $app, 'jobs', 'Rewire kitchen', 'booked', ['trade' => 'electrical', 'address' => '12 Kabulonga Rd', 'technician' => $owner->id], ['occurs_on' => today(), 'amount' => 1800, 'contact_id' => $customer->id]);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $rewire->id, 'dispatch']))->assertRedirect();
        $this->assertSame('on_the_way', $rewire->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), [
            'title' => 'Fix geyser', 'status' => 'on_the_way', 'occurs_on' => today()->toDateString(), 'data' => ['trade' => 'plumbing', 'address' => '4 Leopards Hill', 'technician' => $owner->id],
        ])->assertSessionHasErrors('data.technician');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), [
            'title' => 'Fix geyser', 'status' => 'in_progress', 'occurs_on' => today()->toDateString(), 'data' => ['trade' => 'plumbing', 'address' => '4 Leopards Hill', 'hours' => 30],
        ])->assertSessionHasErrors(['data.technician', 'data.hours']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), [
            'title' => 'Fix geyser', 'status' => 'complete', 'occurs_on' => today()->toDateString(), 'data' => ['trade' => 'gas', 'address' => '4 Leopards Hill', 'hours' => 2],
        ])->assertSessionHasErrors(['data.certificate_number', 'data.customer_signature']);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $rewire->id, 'start']))->assertRedirect();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $rewire->id, 'complete']), ['hours' => 5, 'customer_signature' => 'B. Phiri'])->assertSessionHasErrors('certificate_number');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $rewire->id, 'complete']), ['hours' => 5, 'certificate_number' => 'COC-4411', 'customer_signature' => 'B. Phiri'])->assertRedirect();
        $this->assertSame('complete', $rewire->fresh()->status);
        $this->assertSame(today()->toDateString(), $rewire->fresh()->value('_completed_on'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'jobs', $rewire->id, 'invoice']))->assertRedirect();
        $this->assertSame('invoiced', $rewire->fresh()->status);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'jobs', $rewire->id]), [
            'title' => 'Rewire kitchen', 'status' => 'invoiced', 'amount' => 2500, 'occurs_on' => today()->toDateString(),
            'data' => ['trade' => 'electrical', 'address' => '12 Kabulonga Rd', 'technician' => $owner->id, 'hours' => 5, 'certificate_number' => 'COC-4411', 'customer_signature' => 'B. Phiri'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'jobs', $rewire->id]))->assertOk()->assertSee('Job card')->assertSee('COC-4411');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee("Today's jobs")->assertSee('Invoiced this month')->assertSee('1,800.00');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Jobs by trade')->assertSee('Technicians')->assertSee($owner->name);
    }

    public function test_solar_installer_sizes_systems_from_usage_and_installs_only_won_surveys(): void
    {
        $app = 'solar-installer';
        [$owner, $workspace] = $this->appWorkspace($app);
        $customer = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Banda household']);
        $survey = $this->record($workspace, $app, 'surveys', 'Banda residence', 'booked', ['address' => 'Plot 77 Ibex Hill', 'monthly_usage' => 450, 'roof_type' => 'ir_sheet'], ['contact_id' => $customer->id, 'occurs_on' => today()]);
        $this->assertEquals(3, $survey->value('recommended_size'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'installations']), [
            'title' => 'Banda residence', 'status' => 'scheduled', 'data' => ['survey' => $survey->id],
        ])->assertSessionHasErrors('data.survey');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'surveys', $survey->id, 'done']))->assertRedirect();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'surveys', $survey->id, 'quote']), ['recommended_size' => 3.5, 'amount' => 0])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'surveys', $survey->id, 'quote']), ['recommended_size' => 3.5, 'amount' => 60000])->assertRedirect();
        $this->assertSame('quoted', $survey->fresh()->status);
        $this->assertEquals(60000, $survey->fresh()->amount);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'surveys', $survey->id, 'win']))->assertRedirect();
        $this->assertSame('won', $survey->fresh()->status);
        $installation = Record::query()->ofEntity($app, 'installations')->latest('id')->firstOrFail();
        $this->assertSame($customer->id, $installation->contact_id);
        $this->assertEquals(60000, $installation->amount);
        $this->assertSame($survey->id, $installation->value('survey'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'installations']), [
            'title' => 'Banda residence again', 'status' => 'scheduled', 'data' => ['survey' => $survey->id],
        ])->assertSessionHasErrors('data.survey');

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'installations', $installation->id]), [
            'title' => 'Banda residence', 'status' => 'commissioned', 'occurs_on' => today()->toDateString(), 'data' => ['survey' => $survey->id],
        ])->assertSessionHasErrors(['data.panels', 'data.inverter', 'data.serial_numbers']);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'installations', $installation->id, 'start']))->assertRedirect();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'installations', $installation->id, 'commission']), ['panels' => 'Jinko 550W × 7', 'inverter' => 'Deye 5kW hybrid', 'serial_numbers' => "INV-001\nPNL-001"])->assertRedirect();
        $this->assertSame('commissioned', $installation->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'installations', $installation->id, 'hand_over']))->assertRedirect();
        $this->assertSame('handed_over', $installation->fresh()->status);
        $this->assertSame(today()->addWeeks(2)->addYears(5)->toDateString(), $installation->fresh()->value('warranty_until'));

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'surveys', $survey->id]))->assertOk()->assertSee('System sizing')->assertSee('3.5 kW');
        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'installations', $installation->id]))->assertOk()->assertSee('Deye 5kW hybrid');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Pipeline')->assertSee('Upcoming installations');
        $this->actingAs($owner)->get(route('apps.reports', [$app, 'to' => today()->addMonth()->toDateString()]))->assertOk()->assertSee('Survey funnel')->assertSee('Wins by roof type')->assertSee('Installations')->assertSee('Banda household');
    }
}
