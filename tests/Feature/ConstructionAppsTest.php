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

/** The construction apps' rules: tenders & BOQs, site diary, subcontractors, plant hire and snag lists. */
class ConstructionAppsTest extends TestCase
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

    public function test_tenders_are_priced_from_their_bill_of_quantities_plus_markup(): void
    {
        $app = 'construction';
        [$owner, $workspace] = $this->appWorkspace($app);
        $client = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Ministry of Health']);
        $clinic = $this->record($workspace, $app, 'tenders', 'Clinic extension', 'preparing', ['tender_number' => 'T-01', 'markup' => 10],
            ['contact_id' => $client->id, 'occurs_on' => today(), 'due_on' => today()->addDays(10)]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tenders']), ['title' => 'Greedy', 'status' => 'preparing', 'data' => ['markup' => 150]])
            ->assertSessionHasErrors('data.markup');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tenders']), [
            'title' => 'Backwards', 'status' => 'preparing', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(),
        ])->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'boq_items']), [
            'title' => 'Nothing', 'status' => 'priced', 'data' => ['tender' => $clinic->id, 'unit' => 'm3', 'quantity' => 0, 'rate' => 100],
        ])->assertSessionHasErrors('data.quantity');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'tenders', $clinic->id]), [
            'title' => 'Clinic extension', 'status' => 'submitted', 'contact_id' => $client->id, 'occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(10)->toDateString(), 'data' => ['tender_number' => 'T-01', 'markup' => 10],
        ])->assertSessionHasErrors('status');

        $concrete = $this->record($workspace, $app, 'boq_items', 'Concrete to foundations', 'priced', ['tender' => $clinic->id, 'item_number' => '1.1', 'unit' => 'm3', 'quantity' => 10, 'rate' => 150]);
        $this->record($workspace, $app, 'boq_items', 'Reinforcing steel', 'priced', ['tender' => $clinic->id, 'item_number' => '1.2', 'unit' => 't', 'quantity' => 2, 'rate' => 250]);
        $this->assertEquals(1500, $concrete->amount);
        $this->assertEquals(2000, $clinic->fresh()->value('_cost'));
        $this->assertEquals(2200, $clinic->fresh()->amount);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'boq_items']), [
            'title' => 'Duplicate', 'status' => 'priced', 'data' => ['tender' => $clinic->id, 'item_number' => '1.1', 'unit' => 'no', 'quantity' => 1, 'rate' => 1],
        ])->assertSessionHasErrors('data.item_number');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'tenders', $clinic->id, 'submit']))->assertRedirect();
        $this->assertSame('submitted', $clinic->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'tenders', $clinic->id, 'won']))->assertRedirect();
        $this->assertSame('won', $clinic->fresh()->status);

        $lost = $this->record($workspace, $app, 'tenders', 'Access road', 'lost', [], ['amount' => 5000, 'occurs_on' => today()]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'boq_items']), [
            'title' => 'Too late', 'status' => 'priced', 'data' => ['tender' => $lost->id, 'unit' => 'm', 'quantity' => 1, 'rate' => 1],
        ])->assertSessionHasErrors('data.tender');
        $this->record($workspace, $app, 'tenders', 'School hall', 'preparing', ['markup' => 5], ['occurs_on' => today(), 'due_on' => today()->addDays(5)]);

        $this->actingAs($owner)->get($clinic->url())->assertOk()->assertSee('Bill of quantities')->assertSee('Concrete to foundations');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Win rate')->assertSee('50%')->assertSee('Closing soon')->assertSee('School hall');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Tenders by outcome')->assertSee('Cost by tender')->assertSee('2,200.00');
    }

    public function test_site_diary_keeps_one_entry_per_site_per_day_and_locks_signed_off_entries(): void
    {
        $app = 'site-diary';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [
            'title' => 'Block A', 'status' => 'draft', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['work_done' => 'Guessing'],
        ])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [
            'title' => 'Block A', 'status' => 'draft', 'occurs_on' => today()->toDateString(), 'data' => ['work_done' => 'Formwork', 'workers_on_site' => -1],
        ])->assertSessionHasErrors('data.workers_on_site');

        $blockA = $this->record($workspace, $app, 'entries', 'Block A', 'draft', ['weather' => 'rain', 'workers_on_site' => 12, 'work_done' => 'Formwork to columns', 'delays' => 'Rain stopped work at 2pm'], ['occurs_on' => today()]);
        $this->assertTrue($blockA->value('_has_delays'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [
            'title' => 'Block A', 'status' => 'draft', 'occurs_on' => today()->toDateString(), 'data' => ['work_done' => 'Again'],
        ])->assertSessionHasErrors('title');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'entries', $blockA->id, 'submit']))->assertRedirect();
        $this->assertSame('submitted', $blockA->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'entries', $blockA->id, 'sign_off']))->assertRedirect();
        $this->assertSame('signed_off', $blockA->fresh()->status);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'entries', $blockA->id]), [
            'title' => 'Block A', 'status' => 'signed_off', 'occurs_on' => today()->toDateString(), 'data' => ['weather' => 'rain', 'workers_on_site' => 12, 'work_done' => 'Something else', 'delays' => 'Rain stopped work at 2pm'],
        ])->assertSessionHasErrors('status');

        $this->record($workspace, $app, 'entries', 'Block B', 'submitted', ['weather' => 'sunny', 'workers_on_site' => 8, 'work_done' => 'Brickwork'], ['occurs_on' => today()]);

        $this->actingAs($owner)->get($blockA->url())->assertOk()->assertSee('Delays reported')->assertSee('Rain stopped work at 2pm');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Rain days')->assertSee('Waiting for sign-off')->assertSee('Block B');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Labour by site')->assertSee('Weather')->assertSee('Delays & issues')->assertSee('Block A');
    }

    public function test_subcontractor_claims_are_certified_within_the_subcontract_and_hold_retention(): void
    {
        $app = 'subcontractors';
        [$owner, $workspace] = $this->appWorkspace($app);
        $sparky = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Volt Electrical']);
        $electrical = $this->record($workspace, $app, 'subcontracts', 'Electrical', 'awarded', ['project' => 'Clinic', 'retention' => 10, 'insurance_expiry' => today()->addYear()->toDateString()],
            ['contact_id' => $sparky->id, 'amount' => 100000, 'occurs_on' => today(), 'due_on' => today()->addMonths(3)]);
        $plumbing = $this->record($workspace, $app, 'subcontracts', 'Plumbing', 'tender', ['project' => 'Clinic'], ['amount' => 40000]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'subcontracts']), ['title' => 'Roofing', 'status' => 'awarded', 'data' => ['project' => 'Clinic', 'retention' => 150]])
            ->assertSessionHasErrors('data.retention');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'subcontracts', $electrical->id]), [
            'title' => 'Electrical', 'status' => 'on_site', 'contact_id' => $sparky->id, 'amount' => 100000, 'occurs_on' => today()->toDateString(), 'due_on' => today()->addMonths(3)->toDateString(),
            'data' => ['project' => 'Clinic', 'retention' => 10, 'insurance_expiry' => today()->subDay()->toDateString()],
        ])->assertSessionHasErrors('data.insurance_expiry');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'claims']), ['title' => 'Early', 'status' => 'submitted', 'data' => ['subcontract' => $plumbing->id, 'claimed' => 1000]])
            ->assertSessionHasErrors('data.subcontract');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'claims']), ['title' => 'Empty', 'status' => 'submitted', 'data' => ['subcontract' => $electrical->id, 'claimed' => 0]])
            ->assertSessionHasErrors('data.claimed');

        $first = $this->record($workspace, $app, 'claims', 'Claim 1', 'submitted', ['subcontract' => $electrical->id, 'claimed' => 40000], ['occurs_on' => today(), 'due_on' => today()->subDay()]);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'claims', $first->id, 'certify']), ['amount' => 50000, 'certificate_number' => 'PC-1'])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'claims', $first->id, 'certify']), ['amount' => 38000, 'certificate_number' => 'PC-1'])->assertRedirect();
        $this->assertSame('certified', $first->fresh()->status);
        $this->assertEquals(38000, $first->fresh()->amount);
        $this->assertEquals(3800, $first->fresh()->value('retention_held'));
        $this->assertEquals(38000, $electrical->fresh()->value('_certified'));
        $this->assertEquals(3800, $electrical->fresh()->value('_retention'));
        $this->assertEquals(62000, $electrical->fresh()->value('_remaining'));

        $second = $this->record($workspace, $app, 'claims', 'Claim 2', 'submitted', ['subcontract' => $electrical->id, 'claimed' => 70000], ['occurs_on' => today()]);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'claims', $second->id, 'certify']), ['amount' => 70000, 'certificate_number' => 'PC-2'])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'subcontracts', $electrical->id]), [
            'title' => 'Electrical', 'status' => 'final_account', 'contact_id' => $sparky->id, 'amount' => 100000, 'occurs_on' => today()->toDateString(), 'due_on' => today()->addMonths(3)->toDateString(),
            'data' => ['project' => 'Clinic', 'retention' => 10, 'insurance_expiry' => today()->addYear()->toDateString()],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Certified, unpaid')->assertSee('Claims to pay')->assertSee('Claim 1');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'claims', $first->id, 'mark_paid']))->assertRedirect();
        $this->assertSame('paid', $first->fresh()->status);
        $this->assertEquals(38000, $electrical->fresh()->value('_paid'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'claims', $first->id]), [
            'title' => 'Claim 1', 'status' => 'paid', 'amount' => 30000, 'occurs_on' => today()->toDateString(), 'data' => ['subcontract' => $electrical->id, 'claimed' => 40000, 'certificate_number' => 'PC-1'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->get($electrical->url())->assertOk()->assertSee('Retention held')->assertSee('Claims')->assertSee('PC-1');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Subcontract accounts')->assertSee('Volt Electrical')->assertSee('Claims by month');
    }

    public function test_plant_hire_tracks_machines_in_and_out_and_their_hour_meters(): void
    {
        $app = 'plant-equipment-hire-and';
        [$owner, $workspace] = $this->appWorkspace($app);
        $customer = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Lakeside Builders']);
        $excavator = $this->record($workspace, $app, 'plant', 'CAT 320', 'available', ['fleet_number' => 'EX-01', 'type' => 'excavator', 'hour_meter' => 1000, 'service_due_hours' => 1020]);
        $grader = $this->record($workspace, $app, 'plant', 'Grader', 'breakdown', ['fleet_number' => 'GR-01', 'type' => 'grader', 'hour_meter' => 500]);
        $roller = $this->record($workspace, $app, 'plant', 'Roller', 'available', ['fleet_number' => 'RL-01', 'type' => 'roller', 'hour_meter' => 200]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'plant']), ['title' => 'Another', 'status' => 'available', 'data' => ['fleet_number' => 'EX-01', 'type' => 'excavator']])
            ->assertSessionHasErrors('data.fleet_number');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'plant', $excavator->id]), [
            'title' => 'CAT 320', 'status' => 'available', 'data' => ['fleet_number' => 'EX-01', 'type' => 'excavator', 'hour_meter' => 900, 'service_due_hours' => 1020],
        ])->assertSessionHasErrors('data.hour_meter');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'hires']), ['title' => 'Quarry', 'status' => 'booked', 'data' => ['plant' => $grader->id]])
            ->assertSessionHasErrors('data.plant');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'hires']), [
            'title' => 'Backwards', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(), 'data' => ['plant' => $excavator->id],
        ])->assertSessionHasErrors('due_on');

        $hire = $this->record($workspace, $app, 'hires', 'Clinic site', 'booked', ['plant' => $excavator->id, 'rate_type' => 'daily', 'rate' => 500],
            ['contact_id' => $customer->id, 'occurs_on' => today()->subDays(6), 'due_on' => today()->subDay()]);
        $this->assertEquals(3000, $hire->amount);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'hires']), ['title' => 'Double', 'status' => 'booked', 'data' => ['plant' => $excavator->id]])
            ->assertSessionHasErrors('data.plant');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'hires', $hire->id, 'dispatch']))->assertRedirect();
        $this->assertSame('on_hire', $hire->fresh()->status);
        $this->assertSame('on_hire', $excavator->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Fleet')->assertSee('Overdue returns')->assertSee('Clinic site');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'hires', $hire->id, 'return_plant']), ['hours_used' => 25])->assertRedirect();
        $this->assertSame('returned', $hire->fresh()->status);
        $this->assertEquals(3000, $hire->fresh()->amount);
        $this->assertEquals(1025, $excavator->fresh()->value('hour_meter'));
        $this->assertSame('service', $excavator->fresh()->status);

        $hourly = $this->record($workspace, $app, 'hires', 'Car park', 'returned', ['plant' => $roller->id, 'rate_type' => 'hourly', 'rate' => 100, 'hours_used' => 8], ['contact_id' => $customer->id, 'occurs_on' => today()]);
        $this->assertEquals(800, $hourly->amount);
        $this->assertEquals(208, $roller->fresh()->value('hour_meter'));
        $this->assertSame('available', $roller->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'plant', $excavator->id, 'back_in_service']), ['service_due_hours' => 1275])->assertRedirect();
        $this->assertSame('available', $excavator->fresh()->status);
        $this->assertEquals(1275, $excavator->fresh()->value('service_due_hours'));

        $this->actingAs($owner)->get($excavator->url())->assertOk()->assertSee('Machine')->assertSee('Hire history')->assertSee('Clinic site');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Utilisation by machine')->assertSee('Revenue by customer')->assertSee('Lakeside Builders');
    }

    public function test_snag_lists_hand_over_only_once_every_snag_is_verified(): void
    {
        $app = 'snag-lists';
        [$owner, $workspace] = $this->appWorkspace($app);
        $unit = $this->record($workspace, $app, 'inspections', 'Unit 4', 'done', ['project' => 'Lakeside flats', 'inspector' => $owner->id, 'type' => 'pre_handover'], ['occurs_on' => today()->subDays(3)]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'snags']), [
            'title' => 'Too early', 'status' => 'open', 'due_on' => today()->subDays(5)->toDateString(), 'data' => ['inspection' => $unit->id],
        ])->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'snags']), ['title' => 'Skipped', 'status' => 'verified', 'data' => ['inspection' => $unit->id]])
            ->assertSessionHasErrors('status');

        $tile = $this->record($workspace, $app, 'snags', 'Cracked tile', 'open', ['inspection' => $unit->id, 'trade' => 'Tiling', 'location' => 'Bathroom'], ['due_on' => today()->subDay()]);
        $paint = $this->record($workspace, $app, 'snags', 'Paint runs', 'open', ['inspection' => $unit->id, 'trade' => 'Painting', 'location' => 'Lounge'], ['due_on' => today()->addDays(3)]);
        $this->assertEquals(2, $unit->fresh()->value('_snags'));
        $this->assertEquals(0, $unit->fresh()->value('_progress'));

        $handOver = fn () => $this->actingAs($owner)->put(route('apps.records.update', [$app, 'inspections', $unit->id]), [
            'title' => 'Unit 4', 'status' => 'handed_over', 'occurs_on' => today()->subDays(3)->toDateString(), 'data' => ['project' => 'Lakeside flats', 'inspector' => $owner->id, 'type' => 'pre_handover'],
        ]);
        $handOver()->assertSessionHasErrors('status');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Overdue snags')->assertSee('Cracked tile');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'snags', $tile->id, 'mark_fixed']))->assertRedirect();
        $this->assertSame('fixed', $tile->fresh()->status);
        $this->assertNotNull($tile->fresh()->value('_fixed_on'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'snags', $tile->id, 'reopen']))->assertRedirect();
        $this->assertSame('open', $tile->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'snags', $tile->id, 'mark_fixed']))->assertRedirect();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'snags', $tile->id, 'verify']))->assertRedirect();
        $this->assertSame('verified', $tile->fresh()->status);
        $this->assertEquals(50, $unit->fresh()->value('_progress'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'snags', $paint->id, 'mark_fixed']))->assertRedirect();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'snags', $paint->id, 'verify']))->assertRedirect();
        $this->assertEquals(100, $unit->fresh()->value('_progress'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'inspections', $unit->id, 'hand_over']))->assertRedirect();
        $this->assertSame('handed_over', $unit->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'snags']), ['title' => 'Late', 'status' => 'open', 'data' => ['inspection' => $unit->id]])
            ->assertSessionHasErrors('data.inspection');

        $this->actingAs($owner)->get($unit->url())->assertOk()->assertSee('Snag list')->assertSee('Cracked tile')->assertSee('100%');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Snags by trade')->assertSee('Tiling')->assertSee('Inspections')->assertSee('Time to fix');
    }
}
