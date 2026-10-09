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

class HealthcareAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_pharmacy_keeps_stock_and_the_controlled_drug_register_in_step(): void
    {
        $app = 'pharmacy';
        [$owner, $workspace] = $this->appWorkspace($app);
        $pharmacist = $this->memberOf($workspace);

        $morphine = $this->record($workspace, $app, 'drugs', 'Morphine', 'in_stock', ['quantity' => 0, 'reorder_level' => 5, 'schedule' => 'controlled']);
        $this->assertSame('out_of_stock', $morphine->status);
        $paracetamol = $this->record($workspace, $app, 'drugs', 'Paracetamol', 'in_stock', ['quantity' => 3, 'reorder_level' => 5]);
        $this->assertSame('low_stock', $paracetamol->status);
        $this->assertSame('otc', $paracetamol->value('schedule'));

        $this->actingAs($owner)->post($morphine->url().'/actions/receive', ['quantity' => 100, 'expiry' => today()->addYear()->toDateString(), 'supplier' => 'MedSup'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', '100 Morphine received; 100 in stock.');
        $morphine = $morphine->fresh();
        $this->assertSame('in_stock', $morphine->status);
        $this->assertEquals(100, Record::query()->where('entity', 'controlled')->latest('id')->first()->value('balance'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'dispensings']), [
            'title' => 'Mary', 'status' => 'dispensed', 'occurs_on' => today()->toDateString(), 'data' => ['drug' => $morphine->id, 'quantity' => 10],
        ])->assertSessionHasErrors(['data.prescriber', 'data.script_number']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'dispensings']), [
            'title' => 'Mary', 'status' => 'dispensed', 'occurs_on' => today()->toDateString(), 'data' => ['drug' => $morphine->id, 'quantity' => 500, 'prescriber' => 'Dr Banda', 'script_number' => 'S1'],
        ])->assertSessionHasErrors('data.quantity');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'dispensings']), [
            'title' => 'Mary', 'status' => 'dispensed', 'occurs_on' => today()->toDateString(), 'data' => ['drug' => $morphine->id, 'quantity' => 10, 'prescriber' => 'Dr Banda', 'script_number' => 'S1'],
        ])->assertSessionHasNoErrors();
        $dispensing = Record::query()->where('entity', 'dispensings')->firstOrFail();
        $this->assertEquals(90, $morphine->fresh()->value('quantity'));
        $entry = Record::query()->where('entity', 'controlled')->latest('id')->first();
        $this->assertSame('dispensed', $entry->value('movement'));
        $this->assertEquals(90, $entry->value('balance'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'dispensings', $dispensing->id]), [
            'title' => 'Mary', 'status' => 'dispensed', 'occurs_on' => today()->toDateString(), 'data' => ['drug' => $morphine->id, 'quantity' => 15, 'prescriber' => 'Dr Banda', 'script_number' => 'S1'],
        ])->assertSessionHasNoErrors();
        $this->assertEquals(85, $morphine->fresh()->value('quantity'));
        $this->actingAs($owner)->post($dispensing->url().'/actions/return')->assertSessionHas('flash.message', 'Morphine returned to stock.');
        $this->assertEquals(100, $morphine->fresh()->value('quantity'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'controlled']), [
            'title' => 'Broken ampoule', 'status' => 'recorded', 'occurs_on' => today()->toDateString(), 'data' => ['drug' => $morphine->id, 'movement' => 'destroyed', 'quantity' => 1],
        ])->assertSessionHasErrors('data.witness');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'controlled']), [
            'title' => 'Broken ampoule', 'status' => 'recorded', 'occurs_on' => today()->toDateString(), 'data' => ['drug' => $paracetamol->id, 'movement' => 'received', 'quantity' => 1],
        ])->assertSessionHasErrors('data.drug');

        $this->actingAs($owner)->post($entry->url().'/actions/verify', ['witness' => $owner->id])->assertSessionHasErrors('witness');
        $this->actingAs($owner)->post($entry->url().'/actions/verify', ['witness' => $pharmacist->id])->assertSessionHas('flash.message', 'Register entry verified.');
        $this->assertSame('verified', $entry->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Reorder')->assertSee('Paracetamol');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Controlled-drug register')->assertSee('Stock on hand');
    }

    public function test_hospital_fills_beds_moves_patients_and_keeps_surgeons_from_double_booking(): void
    {
        $app = 'hospital';
        [$owner, $workspace] = $this->appWorkspace($app);

        $general = $this->record($workspace, $app, 'wards', 'General', 'open', ['type' => 'general', 'beds' => 2]);
        $icu = $this->record($workspace, $app, 'wards', 'ICU', 'open', ['type' => 'icu', 'beds' => 1]);
        $this->assertSame(0, $general->value('occupied'));

        $alice = $this->record($workspace, $app, 'admissions', 'Alice', 'admitted', ['ward' => $general->id, 'bed' => '1'], ['occurs_on' => today()]);
        $this->assertSame(1, $general->fresh()->value('occupied'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'admissions']), [
            'title' => 'Bob', 'status' => 'admitted', 'occurs_on' => today()->toDateString(), 'data' => ['ward' => $general->id, 'bed' => '1'],
        ])->assertSessionHasErrors('data.bed');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'admissions']), [
            'title' => 'Bob', 'status' => 'admitted', 'occurs_on' => today()->toDateString(), 'data' => ['ward' => $general->id, 'bed' => '2'],
        ])->assertSessionHasNoErrors();
        $bob = Record::query()->where('entity', 'admissions')->where('title', 'Bob')->firstOrFail();
        $this->assertSame(0, $general->fresh()->value('_free'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'admissions']), [
            'title' => 'Cara', 'status' => 'admitted', 'occurs_on' => today()->toDateString(), 'data' => ['ward' => $general->id],
        ])->assertSessionHasErrors('data.ward');

        $this->actingAs($owner)->post($alice->url().'/actions/transfer', ['ward' => $icu->id, 'bed' => 'I1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Alice moved from General to ICU.');
        $this->assertSame(1, $general->fresh()->value('occupied'));
        $this->assertSame(1, $icu->fresh()->value('occupied'));
        $this->actingAs($owner)->post($bob->url().'/actions/transfer', ['ward' => $icu->id])->assertSessionHasErrors('ward');

        $this->actingAs($owner)->post($alice->url().'/actions/discharge')->assertSessionHasErrors('discharge_summary');
        $this->actingAs($owner)->post($alice->url().'/actions/discharge', ['discharge_summary' => 'Recovered.'])->assertSessionHas('flash.message', 'Alice discharged after 1 day.');
        $this->assertSame(0, $icu->fresh()->value('occupied'));

        $this->record($workspace, $app, 'theatre', 'Appendectomy', 'scheduled', ['admission' => $bob->id, 'surgeon' => $owner->id, 'start_time' => '09:00'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'theatre']), [
            'title' => 'Hernia repair', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['admission' => $bob->id, 'surgeon' => $owner->id, 'start_time' => '09:00'],
        ])->assertSessionHasErrors('data.start_time');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'theatre']), [
            'title' => 'Wound check', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['admission' => $alice->id, 'surgeon' => $owner->id, 'start_time' => '11:00'],
        ])->assertSessionHasErrors('data.admission');

        $booking = Record::query()->where('entity', 'theatre')->firstOrFail();
        $this->actingAs($owner)->post($booking->url().'/actions/start')->assertSessionHas('flash.message', 'Appendectomy started.');
        $this->actingAs($owner)->post($booking->url().'/actions/complete')->assertSessionHas('flash.message', 'Appendectomy completed.');
        $this->assertSame(0, $booking->fresh()->value('_minutes'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'wards', $general->id]), [
            'title' => 'General', 'status' => 'closed', 'data' => ['type' => 'general', 'beds' => 2],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Beds by ward')->assertSee('General');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Theatre by surgeon')->assertSee('Admissions by month');
    }

    public function test_telemedicine_links_calls_times_them_and_only_prescribes_from_real_consults(): void
    {
        $app = 'telemedicine';
        [$owner, $workspace] = $this->appWorkspace($app);

        $ann = $this->record($workspace, $app, 'consults', 'Ann', 'booked', ['start_time' => '10:00', 'doctor' => $owner->id], ['occurs_on' => today()]);
        $this->assertStringStartsWith('https://meet.zonseo.test/', $ann->value('meeting_link'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'consults']), [
            'title' => 'Ben', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['start_time' => '10:00', 'doctor' => $owner->id],
        ])->assertSessionHasErrors('data.start_time');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'consults']), [
            'title' => 'Ben', 'status' => 'booked', 'occurs_on' => today()->subDay()->toDateString(), 'data' => ['start_time' => '11:00', 'doctor' => $owner->id],
        ])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'eprescriptions']), [
            'title' => 'Amoxicillin', 'status' => 'issued', 'occurs_on' => today()->toDateString(), 'data' => ['consult' => $ann->id, 'dosage' => '500mg tds'],
        ])->assertSessionHasErrors('data.consult');

        $this->actingAs($owner)->post($ann->url().'/actions/admit')->assertSessionHas('flash.message', 'Ann is in the waiting room.');
        $this->actingAs($owner)->post($ann->url().'/actions/join')->assertSessionHas('flash.message', 'Call with Ann started: '.$ann->value('meeting_link'));
        $this->actingAs($owner)->post($ann->url().'/actions/end')->assertSessionHasErrors('notes');
        $this->actingAs($owner)->post($ann->url().'/actions/end', ['notes' => 'Tonsillitis.'])->assertSessionHas('flash.message', 'Call with Ann ended after 1 min.');
        $this->assertSame('completed', $ann->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'eprescriptions']), [
            'title' => 'Amoxicillin', 'status' => 'issued', 'occurs_on' => today()->toDateString(), 'data' => ['consult' => $ann->id, 'dosage' => '500mg tds'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $ann->fresh()->value('_prescriptions'));
        $prescription = Record::query()->where('entity', 'eprescriptions')->firstOrFail();
        $this->actingAs($owner)->post($prescription->url().'/actions/send')->assertSessionHasErrors('pharmacy');
        $this->actingAs($owner)->post($prescription->url().'/actions/send', ['pharmacy' => 'City Pharmacy'])->assertSessionHas('flash.message', 'Amoxicillin sent to City Pharmacy.');
        $this->actingAs($owner)->post($prescription->url().'/actions/dispense')->assertSessionHas('flash.message', 'Amoxicillin dispensed by City Pharmacy.');

        $missed = $this->record($workspace, $app, 'consults', 'Carl', 'booked', ['start_time' => '08:00', 'doctor' => $owner->id], ['occurs_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('no_show', $missed->fresh()->status);

        $this->actingAs($owner)->get($ann->url())->assertOk()->assertSee('Prescriptions')->assertSee('Amoxicillin');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Consults by doctor')->assertSee('City Pharmacy');
    }

    public function test_specialist_plans_need_acceptance_check_fdi_teeth_and_flag_overruns(): void
    {
        $app = 'specialist-practice';
        [$owner, $workspace] = $this->appWorkspace($app);

        $plan = $this->record($workspace, $app, 'plans', 'Tim', 'proposed', ['speciality' => 'dental', 'findings' => 'Caries 11, 21', 'sessions' => 2], ['amount' => 1000]);
        $procedure = fn (string $title, string $teeth, int $fee) => [
            'title' => $title, 'status' => 'scheduled', 'amount' => $fee, 'occurs_on' => today()->toDateString(), 'data' => ['plan' => $plan->id, 'tooth_or_site' => $teeth],
        ];

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'procedures']), $procedure('Filling', '11', 600))->assertSessionHasErrors('data.plan');
        $this->actingAs($owner)->post($plan->url().'/actions/accept')->assertSessionHasNoErrors();
        $this->assertSame('accepted', $plan->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'procedures']), $procedure('Filling', '11 59', 600))->assertSessionHasErrors('data.tooth_or_site');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'procedures']), $procedure('Filling', '11 21', 600))->assertSessionHasNoErrors();
        $filling = Record::query()->where('entity', 'procedures')->firstOrFail();
        $this->assertSame('11, 21', $filling->value('tooth_or_site'));

        $this->actingAs($owner)->post($filling->url().'/actions/done')->assertSessionHas('flash.message', 'Filling done.');
        $plan = $plan->fresh();
        $this->assertSame('in_progress', $plan->status);
        $this->assertSame(1, $plan->value('_done'));
        $this->assertSame(50, $plan->value('_progress'));
        $this->assertFalse($plan->value('_over_estimate'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'procedures']), [...$procedure('Crown', '36', 600), 'status' => 'done'])->assertSessionHasNoErrors();
        $plan = $plan->fresh();
        $this->assertTrue($plan->value('_over_estimate'));
        $this->assertSame(100, $plan->value('_progress'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'procedures']), $procedure('Polish', '11', 100))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($plan->url().'/actions/complete')->assertSessionHasErrors('status');
        $polish = Record::query()->where('entity', 'procedures')->where('title', 'Polish')->firstOrFail();
        $this->actingAs($owner)->post($polish->url().'/actions/cancel')->assertSessionHas('flash.message', 'Polish cancelled.');
        $this->actingAs($owner)->post($plan->url().'/actions/complete')->assertSessionHas('flash.message', 'Tim\'s plan completed.');

        $this->actingAs($owner)->get($plan->url())->assertOk()->assertSee('Progress')->assertSee('Crown');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Plans over estimate')->assertSee('Tim');
    }

    public function test_radiology_books_scans_and_reports_and_flags_late_reports(): void
    {
        $app = 'radiology-diagnostic-imaging-ris';
        [$owner, $workspace] = $this->appWorkspace($app);

        $study = $this->record($workspace, $app, 'studies', 'Joe', 'requested', ['modality' => 'x_ray', 'body_part' => 'Chest', 'referring_doctor' => 'Dr Moyo']);
        $this->actingAs($owner)->post($study->url().'/actions/book', ['date' => today()->toDateString()])->assertSessionHas('flash.message', 'Joe\'s X-ray booked for '.today()->format('d M Y').'.');
        $this->actingAs($owner)->post($study->url().'/actions/scan')->assertSessionHasNoErrors();
        $this->assertNotNull($study->fresh()->value('_scanned_at'));
        $this->actingAs($owner)->post($study->url().'/actions/report')->assertSessionHasErrors('report');
        $this->actingAs($owner)->post($study->url().'/actions/report', ['report' => 'Clear lung fields.'])->assertSessionHas('flash.message', 'Report for Joe ready for Dr Moyo.');
        $study = $study->fresh();
        $this->assertSame('reported', $study->status);
        $this->assertSame(0, $study->value('_report_hours'));
        $this->assertFalse($study->value('_report_late'));
        $this->assertSame($owner->id, $study->assignee_id);

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'studies', $study->id]), [
            'title' => 'Joe', 'status' => 'scanned', 'occurs_on' => today()->toDateString(), 'data' => ['modality' => 'x_ray', 'body_part' => 'Chest', 'report' => 'Clear lung fields.'],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'studies']), [
            'title' => 'Kim', 'status' => 'reported', 'occurs_on' => today()->toDateString(), 'data' => ['modality' => 'ct', 'body_part' => 'Head'],
        ])->assertSessionHasErrors('data.report');

        $late = $this->record($workspace, $app, 'studies', 'Liz', 'scanned', ['modality' => 'mri', 'body_part' => 'Knee', '_scanned_at' => now()->subDays(3)->toDateTimeString()], ['occurs_on' => today()->subDays(3)]);
        $this->assertTrue($late->value('_report_late'));

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Reporting worklist')->assertSee('Liz');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Studies by modality')->assertSee('Dr Moyo');
    }

    public function test_blood_bank_spaces_donations_quarantines_units_and_issues_the_oldest_first(): void
    {
        $app = 'blood-bank';
        [$owner, $workspace] = $this->appWorkspace($app);

        $dave = $this->record($workspace, $app, 'donors', 'Dave', 'eligible', ['blood_group' => 'o_pos', 'last_donation' => today()->subDays(30)->toDateString()]);
        $eve = $this->record($workspace, $app, 'donors', 'Eve', 'eligible', ['blood_group' => 'o_pos', 'last_donation' => today()->subDays(60)->toDateString()]);
        $unit = fn (string $number, Record $donor, string $component, string $status = 'quarantine') => [
            'title' => $number, 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['donor' => $donor->id, 'blood_group' => 'o_pos', 'component' => $component],
        ];

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'units']), $unit('BU-1', $dave, 'platelets'))->assertSessionHasErrors('data.donor');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'units']), $unit('BU-2', $eve, 'platelets', 'available'))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'units']), [...$unit('BU-2', $eve, 'platelets'), 'data' => [...$unit('BU-2', $eve, 'platelets')['data'], 'blood_group' => 'a_neg']])->assertSessionHasErrors('data.blood_group');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'units']), $unit('BU-2', $eve, 'platelets'))->assertSessionHasNoErrors();
        $platelets = Record::query()->where('entity', 'units')->where('title', 'BU-2')->firstOrFail();
        $this->assertSame(today()->addDays(5)->toDateString(), $platelets->due_on->toDateString());
        $eve = $eve->fresh();
        $this->assertSame(today()->toDateString(), $eve->value('last_donation'));
        $this->assertSame(1, $eve->value('_donations'));

        $this->actingAs($owner)->post($platelets->url().'/actions/release')->assertSessionHas('flash.message', 'BU-2 released to stock.');
        $this->assertSame('available', $platelets->fresh()->status);

        $fay = $this->record($workspace, $app, 'donors', 'Fay', 'eligible', ['blood_group' => 'o_pos']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'units']), $unit('BU-3', $fay, 'plasma'))->assertSessionHasNoErrors();
        $plasma = Record::query()->where('entity', 'units')->where('title', 'BU-3')->firstOrFail();
        $this->actingAs($owner)->post($plasma->url().'/actions/reactive')->assertSessionHas('flash.message', 'BU-3 discarded after a reactive screen; the donor is deferred.');
        $this->assertSame('discarded', $plasma->fresh()->status);
        $this->assertSame('deferred', $fay->fresh()->status);

        $older = $this->record($workspace, $app, 'units', 'RC-OLD', 'available', ['blood_group' => 'o_neg', 'component' => 'red_cells', 'screening' => 'negative'], ['occurs_on' => today()->subDays(30)]);
        $newer = $this->record($workspace, $app, 'units', 'RC-NEW', 'available', ['blood_group' => 'o_neg', 'component' => 'red_cells', 'screening' => 'negative'], ['occurs_on' => today()]);
        $this->assertSame(today()->addDays(12)->toDateString(), $older->due_on->toDateString());
        $this->actingAs($owner)->post($newer->url().'/actions/issue', ['issued_to' => 'Ward 3'])->assertSessionHasErrors('issued_to');
        $this->actingAs($owner)->post($older->url().'/actions/issue', ['issued_to' => 'Ward 3'])->assertSessionHas('flash.message', 'RC-OLD (O− red cells) issued to Ward 3.');

        $stale = $this->record($workspace, $app, 'units', 'WB-OLD', 'available', ['blood_group' => 'b_pos', 'component' => 'whole_blood', 'screening' => 'negative'], ['occurs_on' => today()->subDays(40)]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $stale->fresh()->status);
        $this->assertSame('available', $newer->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Stock by blood group')->assertSee('O−');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Wastage reasons')->assertSee('Reactive screen');
    }

    public function test_ambulance_dispatch_keeps_one_call_per_ambulance_and_times_the_response(): void
    {
        $app = 'ambulance-emergency-dispatch';
        [$owner, $workspace] = $this->appWorkspace($app);

        $chest = $this->record($workspace, $app, 'incidents', 'Chest pain', 'received', ['priority' => 'p1_critical', 'location' => 'Area 47']);
        $fall = $this->record($workspace, $app, 'incidents', 'Fall', 'received', ['priority' => 'p3_routine', 'location' => 'Area 3']);

        $this->actingAs($owner)->post($chest->url().'/actions/dispatch')->assertSessionHasErrors('ambulance');
        $this->actingAs($owner)->post($chest->url().'/actions/dispatch', ['ambulance' => 'amb 1'])->assertSessionHas('flash.message', 'AMB 1 dispatched to Chest pain.');
        $this->actingAs($owner)->post($fall->url().'/actions/dispatch', ['ambulance' => 'Amb 1 '])->assertSessionHasErrors('ambulance');

        $chest = $chest->fresh();
        $chest->data = [...$chest->data, '_received_at' => now()->subMinutes(20)->toDateTimeString()];
        $chest->saveQuietly();
        $this->actingAs($owner)->post($chest->url().'/actions/arrive')->assertSessionHas('flash.message', 'AMB 1 on scene after 20 min — over the 15-minute target.');
        $this->assertFalse($chest->fresh()->value('_within_target'));

        $this->actingAs($owner)->post($chest->url().'/actions/transport')->assertSessionHasErrors('hospital');
        $this->actingAs($owner)->post($chest->url().'/actions/transport', ['hospital' => 'Kamuzu Central'])->assertSessionHas('flash.message', 'Patient on the way to Kamuzu Central.');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'incidents', $chest->id]), [
            'title' => 'Chest pain', 'status' => 'on_scene', 'occurs_on' => today()->toDateString(), 'data' => ['priority' => 'p1_critical', 'location' => 'Area 47', 'ambulance' => 'AMB 1', 'hospital' => 'Kamuzu Central'],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($chest->url().'/actions/handover')->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($chest->url().'/actions/close')->assertSessionHasErrors('patient_report');
        $this->actingAs($owner)->post($chest->url().'/actions/close', ['patient_report' => 'Aspirin given, stable.'])->assertSessionHas('flash.message', 'Chest pain closed.');
        $this->assertGreaterThanOrEqual(20, $chest->fresh()->value('_call_minutes'));

        $this->actingAs($owner)->post($fall->url().'/actions/dispatch', ['ambulance' => 'AMB 1'])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get($chest->url())->assertOk()->assertSee('Timeline')->assertSee('20 min');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Open calls')->assertSee('Fall');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Response times by priority')->assertSee('Kamuzu Central');
    }

    /**
     * A workspace owner with the given app switched on.
     *
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
