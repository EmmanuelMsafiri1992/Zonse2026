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

class HealthcareAppsBatchFourTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_immunisation_register_follows_the_schedule_and_traces_children_who_fall_behind(): void
    {
        $app = 'immunisation-registers-community-health';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'children']), [
            'title' => 'Baby', 'status' => 'up_to_date', 'data' => ['date_of_birth' => today()->addDay()->toDateString()],
        ])->assertSessionHasErrors('data.date_of_birth');

        $born = today()->subDays(50);
        $child = $this->record($workspace, $app, 'children', 'Chikondi', 'up_to_date', ['date_of_birth' => $born->toDateString(), 'village' => 'Mtengo']);
        $this->assertSame('defaulter', $child->status);
        $this->assertSame($born->toDateString(), $child->due_on->toDateString());

        $dose = fn (string $vaccine, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'doses']), [
            'title' => $vaccine, 'status' => 'given', 'occurs_on' => today()->toDateString(), 'data' => ['child' => $child->id, ...$data],
        ]);
        $dose('Yellow fever', ['batch' => 'Y1'])->assertSessionHasErrors('title');
        $dose('bcg', [])->assertSessionHasErrors('data.batch');
        $dose('bcg', ['batch' => 'B1'])->assertSessionHasNoErrors();
        $this->assertSame('BCG', Record::query()->where('entity', 'doses')->firstOrFail()->title);
        $dose('BCG', ['batch' => 'B2'])->assertSessionHasErrors('data.dose_number');
        $dose('OPV', ['batch' => 'O1', 'dose_number' => 2])->assertSessionHasErrors('occurs_on');

        $child = $child->fresh();
        $this->assertSame('due', $child->status);
        $this->assertSame('OPV 1, Pentavalent 1, PCV 1, Rotavirus 1', $child->value('_next_doses'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Children to trace')->assertSee('Chikondi');

        $this->actingAs($owner)->post($child->url().'/actions/vaccinate', [])->assertSessionHasErrors('batch');
        $this->actingAs($owner)->post($child->url().'/actions/vaccinate', ['batch' => 'B12', 'site' => 'Mtengo outreach'])
            ->assertSessionHas('flash.message', '4 dose(s) given to Chikondi: OPV 1, Pentavalent 1, PCV 1, Rotavirus 1. Next due '.$born->copy()->addDays(70)->format('d M Y').'.');
        $child = $child->fresh();
        $this->assertSame('up_to_date', $child->status);
        $this->assertSame(5, $child->value('_doses_given'));

        $this->travel(21)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('due', $child->fresh()->status);
        $this->travelBack();

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'chw_visits']), [
            'title' => 'Phiri household', 'status' => 'referred', 'occurs_on' => today()->toDateString(), 'data' => ['village' => 'Mtengo', 'reason' => 'malaria'],
        ])->assertSessionHasErrors('data.referral');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Coverage by village')->assertSee('Mtengo');
    }

    public function test_medical_supplies_set_deadlines_guard_the_cold_chain_and_measure_late_deliveries(): void
    {
        $app = 'medical-supplies-distribution';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), [
            'title' => 'Kamuzu Clinic', 'status' => 'received', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(), 'data' => ['items' => 'Vaccines x 100'],
        ])->assertSessionHasErrors('due_on');

        $order = $this->record($workspace, $app, 'orders', 'Kamuzu Clinic', 'received', ['items' => 'Vaccines x 100', 'urgency' => 'urgent', 'cold_chain' => true], ['occurs_on' => today(), 'due_on' => null]);
        $this->assertSame(today()->addDays(3)->toDateString(), $order->due_on->toDateString());

        $this->actingAs($owner)->post($order->url().'/actions/approve')
            ->assertSessionHas('flash.message', 'Order for Kamuzu Clinic approved; deliver by '.today()->addDays(3)->format('d M Y').'.');
        $this->actingAs($owner)->post($order->url().'/actions/pick', ['temperature' => 12])->assertSessionHasErrors('temperature');
        $this->actingAs($owner)->post($order->url().'/actions/pick', ['temperature' => 5])->assertSessionHas('flash.message', 'Order for Kamuzu Clinic picked; cold chain at 5 °C.');
        $this->actingAs($owner)->post($order->url().'/actions/deliver', [])->assertSessionHasErrors('delivery_note');
        $this->actingAs($owner)->post($order->url().'/actions/deliver', ['delivery_note' => 'DN-1'])->assertSessionHas('flash.message', 'Delivered to Kamuzu Clinic in 0 day(s) on time.');

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'orders', $order->id]), [
            'title' => 'Kamuzu Clinic', 'status' => 'received', 'occurs_on' => today()->toDateString(), 'data' => ['items' => 'Vaccines x 100'],
        ])->assertSessionHasErrors('status');

        $late = $this->record($workspace, $app, 'orders', 'Zomba Hospital', 'approved', ['items' => 'Gloves', 'urgency' => 'routine', 'cold_chain' => false], ['occurs_on' => today()->subDays(20), 'due_on' => null]);
        $this->assertTrue($late->value('_late'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Dispatch queue')->assertSee('Zomba Hospital');
        $this->actingAs($owner)->post($late->url().'/actions/pick')->assertSessionHas('flash.message', 'Order for Zomba Hospital picked.');
        $this->actingAs($owner)->post($late->url().'/actions/deliver', ['delivery_note' => 'DN-2'])->assertSessionHas('flash.message', 'Delivered to Zomba Hospital in 20 day(s) — 6 day(s) late.');

        $cancel = $this->record($workspace, $app, 'orders', 'Mzuzu', 'received', ['items' => 'Syringes'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($cancel->url().'/actions/cancel', [])->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($cancel->url().'/actions/cancel', ['reason' => 'Duplicate'])->assertSessionHas('flash.message', 'Order for Mzuzu cancelled.');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Lead time by urgency')->assertSee('Kamuzu Clinic');
    }

    public function test_patient_portal_keeps_one_account_per_email_and_times_every_reply(): void
    {
        $app = 'patient-portal';
        [$owner, $workspace] = $this->appWorkspace($app);

        $alice = $this->record($workspace, $app, 'accounts', 'Alice', 'active', ['email' => 'ALICE@example.test ']);
        $this->assertSame('alice@example.test', $alice->value('email'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'accounts']), [
            'title' => 'Alice B', 'status' => 'invited', 'data' => ['email' => 'Alice@Example.test'],
        ])->assertSessionHasErrors('data.email');

        $bob = $this->record($workspace, $app, 'accounts', 'Bob', 'invited', ['email' => 'bob@example.test']);
        $request = fn (Record $account, string $title, string $status, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), [
            'title' => $title, 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['account' => $account->id, 'type' => 'appointment', 'message' => 'Next week please', ...$data],
        ]);
        $request($bob, 'Book a visit', 'new')->assertSessionHasErrors('data.account');
        $this->actingAs($owner)->post($bob->url().'/actions/activate')->assertSessionHas('flash.message', 'Bob\'s portal account is active.');
        $this->assertNotNull($bob->fresh()->value('last_login'));

        $request($alice, 'Book a visit', 'done')->assertSessionHasErrors('data.reply');
        $request($alice, 'Book a visit', 'new')->assertSessionHasNoErrors();
        $visit = Record::query()->where('entity', 'requests')->firstOrFail();
        $this->assertSame(1, $alice->fresh()->value('_open_requests'));
        $this->actingAs($owner)->post($visit->url().'/actions/take')->assertSessionHas('flash.message', 'You are handling "Book a visit".');
        $this->assertSame($owner->id, $visit->fresh()->assignee_id);
        $this->actingAs($owner)->post($visit->url().'/actions/reply', [])->assertSessionHasErrors('reply');
        $this->actingAs($owner)->post($visit->url().'/actions/reply', ['reply' => 'Tuesday at 9'])->assertSessionHas('flash.message', 'Reply sent to Alice.');
        $this->assertSame(0, $alice->fresh()->value('_open_requests'));

        $results = $this->record($workspace, $app, 'requests', 'My blood results', 'new', ['account' => $alice->id, 'type' => 'results', 'message' => 'Are they back?'], ['occurs_on' => today()->subDays(3)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Overdue replies')->assertSee('My blood results');
        $this->actingAs($owner)->post($results->url().'/actions/reply', ['reply' => 'All normal'])
            ->assertSessionHas('flash.message', 'Reply sent to Alice — later than the 48-hour target.');

        $this->actingAs($owner)->post($alice->url().'/actions/disable')->assertSessionHas('flash.message', 'Alice\'s portal account disabled.');
        $request($alice, 'Another', 'new')->assertSessionHasErrors('data.account');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Requests by type')->assertSee('Repeat prescription');
    }

    public function test_medical_billing_codes_claims_meets_deadlines_and_tracks_remittances(): void
    {
        $app = 'medical-billing-coding-for';
        [$owner, $workspace] = $this->appWorkspace($app);

        $claim = fn (array $attributes, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'claims']), [
            'title' => 'Mary', 'status' => 'draft', 'occurs_on' => today()->toDateString(), 'amount' => 100, ...$attributes,
            'data' => ['insurer' => 'MASM', 'member_number' => 'M1', 'icd10' => 'J18.9', ...$data],
        ]);
        $claim([], ['icd10' => 'J18.9, flu'])->assertSessionHasErrors('data.icd10');
        $claim(['status' => 'submitted', 'amount' => 0], [])->assertSessionHasErrors('amount');
        $claim(['status' => 'rejected'], [])->assertSessionHasErrors('data.rejection_reason');
        $claim(['status' => 'part_paid'], ['paid_amount' => 150])->assertSessionHasErrors('data.paid_amount');

        $mary = $this->record($workspace, $app, 'claims', 'Mary', 'draft', ['insurer' => 'MASM', 'member_number' => 'M1', 'icd10' => 'j18.9; e11'], ['occurs_on' => today()->subDays(10), 'due_on' => null, 'amount' => 1000]);
        $this->assertSame('J18.9, E11', $mary->value('icd10'));
        $this->assertSame(today()->addDays(80)->toDateString(), $mary->due_on->toDateString());

        $this->actingAs($owner)->post($mary->url().'/actions/submit')->assertSessionHas('flash.message', 'Claim for Mary submitted to MASM.');
        $this->actingAs($owner)->post($mary->url().'/actions/reject', [])->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($mary->url().'/actions/reject', ['reason' => 'Wrong diagnosis code'])->assertSessionHas('flash.message', 'Claim for Mary rejected: Wrong diagnosis code');
        $this->actingAs($owner)->post($mary->url().'/actions/resubmit', ['icd10' => 'BAD'])->assertSessionHasErrors('icd10');
        $this->actingAs($owner)->post($mary->url().'/actions/resubmit', ['icd10' => 'j18.0'])->assertSessionHas('flash.message', 'Claim for Mary corrected and resubmitted.');
        $this->assertSame('J18.0', $mary->fresh()->value('icd10'));

        $this->actingAs($owner)->post($mary->url().'/actions/remit', ['paid' => 600])
            ->assertSessionHas('flash.message', Money::format(600).' received for Mary; '.Money::format(400).' still short.');
        $this->assertSame('part_paid', $mary->fresh()->status);
        $this->actingAs($owner)->post($mary->url().'/actions/remit', ['paid' => 500])->assertSessionHasErrors('paid');
        $this->actingAs($owner)->post($mary->url().'/actions/remit', ['paid' => 400])->assertSessionHas('flash.message', 'Claim for Mary paid in full.');
        $mary = $mary->fresh();
        $this->assertSame('paid', $mary->status);
        $this->assertEquals(0, $mary->value('_shortfall'));

        $old = $this->record($workspace, $app, 'claims', 'Old claim', 'draft', ['insurer' => 'Liberty', 'member_number' => 'L9', 'icd10' => 'A09'], ['occurs_on' => today()->subDays(100), 'due_on' => null, 'amount' => 50]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Submit this week')->assertSee('Old claim');
        $this->actingAs($owner)->post($old->url().'/actions/submit')->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Claims by insurer')->assertSee('Wrong diagnosis code');
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
