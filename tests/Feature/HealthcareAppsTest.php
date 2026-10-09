<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Sms\SmsService;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class HealthcareAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_patient_queue_numbers_tokens_per_service_and_calls_emergencies_first(): void
    {
        $app = 'patient-queue';
        [$owner, $workspace] = $this->appWorkspace($app);

        $alice = $this->record($workspace, $app, 'tokens', 'Alice', 'waiting', ['service' => 'consultation', 'priority' => 'normal']);
        $bob = $this->record($workspace, $app, 'tokens', 'Bob', 'waiting', ['service' => 'consultation', 'priority' => 'elderly']);
        $cara = $this->record($workspace, $app, 'tokens', 'Cara', 'waiting', ['service' => 'consultation', 'priority' => 'emergency']);
        $dan = $this->record($workspace, $app, 'tokens', 'Dan', 'waiting', ['service' => 'pharmacy']);
        $this->assertSame(['C-001', 'C-002', 'C-003', 'P-001'], [$alice->value('_number'), $bob->value('_number'), $cara->value('_number'), $dan->value('_number')]);
        $this->assertSame('normal', $dan->value('priority'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tokens']), [
            'title' => 'Eve', 'status' => 'waiting', 'occurs_on' => today()->subDay()->toDateString(), 'data' => ['service' => 'consultation'],
        ])->assertSessionHasErrors('occurs_on');

        $this->actingAs($owner)->post($alice->url().'/actions/call', ['room' => 'Room 1'])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($cara->url().'/actions/call')->assertSessionHasErrors('room');
        $this->actingAs($owner)->post($cara->url().'/actions/call', ['room' => 'Room 1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'C-003 to Room 1.');
        $this->actingAs($owner)->post($dan->url().'/actions/call', ['room' => 'Counter 2'])->assertSessionHas('flash.message', 'P-001 to Counter 2.');

        $this->actingAs($owner)->post($cara->url().'/actions/start')->assertSessionHas('flash.message', 'Serving C-003.');
        $this->actingAs($owner)->post($cara->url().'/actions/done')->assertSessionHas('flash.message', 'C-003 done.');
        $cara = $cara->fresh();
        $this->assertSame('done', $cara->status);
        $this->assertSame(0, $cara->value('_wait_minutes'));
        $this->assertNotNull($cara->value('_done_at'));

        $this->actingAs($owner)->post($alice->url().'/actions/call', ['room' => 'Room 1'])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($bob->url().'/actions/call', ['room' => 'Room 1'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($bob->url().'/actions/done')->assertNotFound();

        Record::query()->whereKey($alice->id)->update(['occurs_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('no_show', $alice->fresh()->status);
        $this->assertSame('called', $bob->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Now serving')->assertSee('C-002');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Waiting times by service')->assertSee('Tokens by day');
    }

    public function test_medical_claims_need_valid_codes_are_not_stale_and_never_pay_more_than_claimed(): void
    {
        $app = 'medical-claims';
        [$owner, $workspace] = $this->appWorkspace($app);

        $claim = $this->record($workspace, $app, 'claims', 'Peter Phiri', 'draft', ['medical_aid' => 'Discovery', 'icd10_codes' => 'j06.9, z00 j06.9'], ['amount' => 800, 'occurs_on' => today()->subDays(10)]);
        $this->assertSame('J06.9, Z00', $claim->value('icd10_codes'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'claims']), [
            'title' => 'Bad codes', 'status' => 'draft', 'amount' => 100, 'occurs_on' => today()->toDateString(), 'data' => ['medical_aid' => 'Discovery', 'icd10_codes' => 'ABC'],
        ])->assertSessionHasErrors('data.icd10_codes');

        $this->actingAs($owner)->post($claim->url().'/actions/submit')->assertSessionHasErrors('data.member_number');
        $claim->update(['data' => [...$claim->data, 'member_number' => 'DH123']]);
        $this->actingAs($owner)->post($claim->url().'/actions/submit', ['reference' => 'REF-1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Claim for Peter Phiri submitted to Discovery; follow up by '.today()->addDays(30)->format('d M Y').'.');
        $claim = $claim->fresh();
        $this->assertSame('submitted', $claim->status);
        $this->assertSame('REF-1', $claim->value('reference'));
        $this->assertSame(today()->toDateString(), $claim->value('_submitted_on'));

        $this->actingAs($owner)->post($claim->url().'/actions/pay', ['amount_paid' => 900])->assertSessionHasErrors('amount_paid');
        $this->actingAs($owner)->post($claim->url().'/actions/pay', ['amount_paid' => 650])->assertSessionHasNoErrors();
        $claim = $claim->fresh();
        $this->assertSame('paid', $claim->status);
        $this->assertSame(150.0, (float) $claim->value('_shortfall'));
        $this->assertSame(0, $claim->value('_days_to_pay'));

        $stale = $this->record($workspace, $app, 'claims', 'Old Service', 'draft', ['medical_aid' => 'Bonitas', 'member_number' => 'B9', 'icd10_codes' => 'I10'], ['amount' => 300, 'occurs_on' => today()->subDays(150)]);
        $this->actingAs($owner)->post($stale->url().'/actions/submit')->assertSessionHasErrors('occurs_on');

        $queried = $this->record($workspace, $app, 'claims', 'Mary Tembo', 'submitted', ['medical_aid' => 'Bonitas', 'member_number' => 'B1', 'icd10_codes' => 'E11.9'], ['amount' => 500, 'occurs_on' => today()->subDays(5)]);
        $this->actingAs($owner)->post($queried->url().'/actions/query')->assertSessionHasErrors('query_reason');
        $this->actingAs($owner)->post($queried->url().'/actions/query', ['query_reason' => 'Missing tariff code'])
            ->assertSessionHas('flash.message', 'Claim for Mary Tembo queried.');
        $this->actingAs($owner)->post($queried->url().'/actions/resubmit', ['note' => 'Added tariff 0190'])
            ->assertSessionHas('flash.message', 'Claim for Mary Tembo resubmitted.');
        $queried = $queried->fresh();
        $this->assertSame('submitted', $queried->status);
        $this->assertSame(1, $queried->value('_resubmissions'));

        $late = $this->record($workspace, $app, 'claims', 'Late Follow-up', 'submitted', ['medical_aid' => 'Bonitas', 'member_number' => 'B2', 'icd10_codes' => 'I10'], ['amount' => 200, 'occurs_on' => today()->subDays(60), 'due_on' => today()->subDay()]);
        $this->assertTrue((bool) $late->value('_overdue'));

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Follow up')->assertSee('Late Follow-up');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Claims by medical aid')->assertSee('Queries and rejections')->assertSee('Bonitas');
    }

    public function test_laboratory_sets_due_dates_by_urgency_and_tracks_turnaround(): void
    {
        $app = 'laboratory';
        [$owner, $workspace] = $this->appWorkspace($app);

        $stat = $this->record($workspace, $app, 'requests', 'Grace', 'requested', ['tests' => 'FBC', 'urgency' => 'stat', 'referred_by' => 'Dr Banda'], ['occurs_on' => today()]);
        $urgent = $this->record($workspace, $app, 'requests', 'Henry', 'requested', ['tests' => 'U&E', 'urgency' => 'urgent'], ['occurs_on' => today()]);
        $routine = $this->record($workspace, $app, 'requests', 'Ida', 'requested', ['tests' => 'Lipids'], ['occurs_on' => today()]);
        $this->assertSame(today()->toDateString(), $stat->due_on->toDateString());
        $this->assertSame(today()->addDay()->toDateString(), $urgent->due_on->toDateString());
        $this->assertSame(today()->addDays(3)->toDateString(), $routine->due_on->toDateString());
        $this->assertSame('routine', $routine->value('urgency'));

        $this->actingAs($owner)->post($stat->url().'/actions/collect', ['sample_type' => 'saliva'])->assertSessionHasErrors('sample_type');
        $this->actingAs($owner)->post($stat->url().'/actions/collect', ['sample_type' => 'blood'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Blood sample collected from Grace.');
        $this->actingAs($owner)->post($stat->url().'/actions/result', ['results' => 'x'])->assertNotFound();
        $this->actingAs($owner)->post($stat->url().'/actions/process')->assertSessionHas('flash.message', 'Processing Grace\'s sample.');
        $this->actingAs($owner)->post($stat->url().'/actions/result')->assertSessionHasErrors('results');
        $this->actingAs($owner)->post($stat->url().'/actions/result', ['results' => 'Hb 7.2 g/dL', 'abnormal' => '1'])
            ->assertSessionHas('flash.message', 'Results for Grace ready — abnormal, tell Dr Banda.');
        $stat = $stat->fresh();
        $this->assertSame('resulted', $stat->status);
        $this->assertTrue((bool) $stat->value('abnormal'));
        $this->assertSame(0, $stat->value('_turnaround_hours'));
        $this->assertFalse((bool) $stat->value('_late'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'requests', $stat->id]), [
            'title' => 'Grace', 'status' => 'processing', 'occurs_on' => today()->toDateString(), 'data' => ['tests' => 'FBC', 'sample_type' => 'blood', 'urgency' => 'stat'],
        ])->assertSessionHasErrors('status');

        $late = $this->record($workspace, $app, 'requests', 'Jack', 'requested', ['tests' => 'HbA1c'], ['occurs_on' => today()->subDays(5)]);
        $this->assertTrue((bool) $late->value('_late'));

        $this->actingAs($owner)->post($urgent->url().'/actions/cancel')->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($urgent->url().'/actions/cancel', ['reason' => 'Sample haemolysed'])->assertSessionHas('flash.message', 'Request for Henry cancelled.');
        $this->assertSame('cancelled', $urgent->fresh()->status);

        $this->actingAs($owner)->get($stat->url())->assertOk()->assertSee('Turnaround');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Work queue')->assertSee('Abnormal results')->assertSee('Jack');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Turnaround by urgency')->assertSee('Dr Banda');
    }

    public function test_veterinary_clinic_books_the_next_dose_and_marks_overdue_vaccinations(): void
    {
        $app = 'veterinary';
        [$owner, $workspace] = $this->appWorkspace($app);

        $rex = $this->record($workspace, $app, 'animals', 'Rex', 'active', ['species' => 'dog', 'microchip' => 'abc123', 'date_of_birth' => today()->subMonths(26)->toDateString()]);
        $this->assertSame('ABC123', $rex->value('microchip'));
        $this->assertSame('2 yrs 2 mths', $rex->value('_age'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'animals']), [
            'title' => 'Copy', 'status' => 'active', 'data' => ['species' => 'cat', 'microchip' => ' Abc123 '],
        ])->assertSessionHasErrors('data.microchip');

        $rabies = $this->record($workspace, $app, 'vaccinations', 'Rabies', 'due', ['animal' => $rex->id], ['due_on' => today()->subDays(3)]);
        $this->assertSame('overdue', $rabies->status);
        $parvo = $this->record($workspace, $app, 'vaccinations', 'Parvo', 'due', ['animal' => $rex->id], ['due_on' => today()->addDays(5)]);
        $this->assertSame('due', $parvo->status);

        $this->actingAs($owner)->post($rabies->url().'/actions/give', ['batch_number' => 'B12'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Rabies given to Rex; next dose due '.today()->addMonths(12)->format('d M Y').'.');
        $rabies = $rabies->fresh();
        $this->assertSame('given', $rabies->status);
        $this->assertSame('B12', $rabies->value('batch_number'));
        $this->assertSame(today()->toDateString(), $rabies->occurs_on->toDateString());
        $next = Record::query()->where('entity', 'vaccinations')->where('status', 'due')->where('title', 'Rabies')->sole();
        $this->assertSame(today()->addMonths(12)->toDateString(), $next->due_on->toDateString());

        Record::query()->whereKey($parvo->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('overdue', $parvo->fresh()->status);

        $visit = $this->record($workspace, $app, 'consultations', 'Limping', 'open', ['animal' => $rex->id], ['amount' => 40]);
        $this->actingAs($owner)->post($visit->url().'/actions/complete')->assertSessionHasErrors('findings');
        $visit->update(['data' => [...$visit->data, 'weight' => 30.5]]);
        $this->actingAs($owner)->post($visit->url().'/actions/complete', ['findings' => 'Sprain, left foreleg', 'treatment' => 'Rest and NSAIDs'])
            ->assertSessionHas('flash.message', 'Consultation for Rex completed.');
        $this->assertSame(30.5, (float) $rex->fresh()->value('_weight'));

        $this->actingAs($owner)->get($rex->url())->assertOk()->assertSee('Vaccinations overdue')->assertSee('30.5 kg');

        $this->actingAs($owner)->post($rex->url().'/actions/deceased')
            ->assertSessionHas('flash.message', 'Rex recorded as deceased; upcoming vaccinations removed.');
        $this->assertSame(0, Record::query()->where('entity', 'vaccinations')->whereIn('status', ['due', 'overdue'])->count());
        $this->assertSame(1, Record::query()->where('entity', 'vaccinations')->where('status', 'given')->count());

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'consultations']), [
            'title' => 'Check-up', 'status' => 'open', 'occurs_on' => today()->toDateString(), 'data' => ['animal' => $rex->id],
        ])->assertSessionHasErrors('data.animal');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Vaccinations due');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Patients by species')->assertSee('Rabies');
    }

    public function test_patient_appointments_stop_double_booking_send_reminders_and_offer_cancelled_slots(): void
    {
        $app = 'patient-appointments';
        [$owner, $workspace] = $this->appWorkspace($app);
        app(SmsService::class)->configure($workspace->fresh(), 'test', [], []);
        $tomorrow = today()->addDay();

        $ruth = $this->record($workspace, $app, 'appointments', 'Ruth', 'booked', ['start_time' => '09:00', 'practitioner' => $owner->id, 'phone' => '+265991234567', 'reminder' => 'sms', 'reason' => 'Check-up'], ['occurs_on' => $tomorrow]);

        $slot = ['title' => 'Clash', 'status' => 'booked', 'occurs_on' => $tomorrow->toDateString(), 'data' => ['start_time' => '09:00', 'practitioner' => $owner->id, 'reminder' => 'none']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'appointments']), $slot)->assertSessionHasErrors('data.start_time');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'appointments']), [...$slot, 'occurs_on' => today()->subDay()->toDateString(), 'data' => [...$slot['data'], 'start_time' => '10:00']])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'appointments']), [...$slot, 'data' => [...$slot['data'], 'start_time' => '10:00', 'reminder' => 'sms']])->assertSessionHasErrors('data.phone');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'appointments']), [...$slot, 'data' => [...$slot['data'], 'start_time' => '10:00']])->assertSessionHasNoErrors();

        $missed = $this->record($workspace, $app, 'appointments', 'Missed', 'confirmed', ['start_time' => '08:00', 'practitioner' => $owner->id], ['occurs_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('no_show', $missed->fresh()->status);
        $this->assertTrue((bool) $ruth->fresh()->value('reminder_sent'));
        $this->assertSame(1, SmsMessage::query()->where('purpose', 'appointment')->count());
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame(1, SmsMessage::query()->where('purpose', 'appointment')->count());

        $sam = $this->record($workspace, $app, 'waitlist', 'Sam', 'waiting', ['phone' => '+265881111111', 'urgency' => 'routine'], ['occurs_on' => today()->subDays(5)]);
        $tia = $this->record($workspace, $app, 'waitlist', 'Tia', 'waiting', ['phone' => '+265882222222', 'urgency' => 'urgent'], ['occurs_on' => today()]);

        $this->actingAs($owner)->post($ruth->url().'/actions/cancel')
            ->assertSessionHas('flash.message', 'Ruth\'s appointment cancelled; the slot is offered to Tia (+265882222222).');
        $this->assertSame('offered', $tia->fresh()->status);

        $this->actingAs($owner)->post($tia->url().'/actions/book')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Tia booked for '.$tomorrow->format('d M Y').' at 09:00.');
        $this->assertSame('booked', $tia->fresh()->status);
        $tiaAppointment = Record::query()->find($tia->fresh()->value('_appointment'));
        $this->assertSame('09:00', $tiaAppointment->value('start_time'));
        $this->assertSame((int) $owner->id, (int) $tiaAppointment->value('practitioner'));

        $this->actingAs($owner)->post($tiaAppointment->url().'/actions/arrive')->assertSessionHasErrors('status');

        $this->actingAs($owner)->post($tiaAppointment->url().'/actions/cancel')->assertSessionHas('flash.message', 'Tia\'s appointment cancelled; the slot is offered to Sam (+265881111111).');
        $this->actingAs($owner)->post($sam->url().'/actions/decline')->assertSessionHas('flash.message', 'Sam declined the slot and stays on the waitlist.');
        $sam = $sam->fresh();
        $this->assertSame('waiting', $sam->status);
        $this->assertSame(1, $sam->value('_declined'));
        $this->assertNull($sam->value('_offered_date'));

        $today = $this->record($workspace, $app, 'appointments', 'Uma', 'booked', ['start_time' => '11:00', 'practitioner' => $owner->id], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($today->url().'/actions/arrive')->assertSessionHas('flash.message', 'Uma arrived.');
        $this->actingAs($owner)->post($today->url().'/actions/seen')->assertSessionHas('flash.message', 'Uma seen.');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Day list')->assertSee('Uma');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Appointments by practitioner')->assertSee($owner->name);
    }

    public function test_emr_locks_signed_encounters_and_keeps_the_chart_summary(): void
    {
        $app = 'emr';
        [$owner, $workspace] = $this->appWorkspace($app);

        $chart = $this->record($workspace, $app, 'records', 'John Banda', 'active', ['file_number' => 'f-100', 'date_of_birth' => today()->subYears(40)->toDateString(), 'allergies' => 'Penicillin']);
        $this->assertSame('F-100', $chart->value('file_number'));
        $this->assertSame(40, $chart->value('_age'));
        $this->assertTrue((bool) $chart->value('_has_allergies'));
        $this->assertFalse((bool) $this->record($workspace, $app, 'records', 'Nkda Patient', 'active', ['file_number' => 'F-101', 'allergies' => 'NKDA'])->value('_has_allergies'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'records']), [
            'title' => 'Duplicate', 'status' => 'active', 'data' => ['file_number' => 'F-100'],
        ])->assertSessionHasErrors('data.file_number');

        $cough = $this->record($workspace, $app, 'encounters', 'Cough', 'open', ['chart' => $chart->id, 'diagnosis' => 'Upper respiratory tract infection', 'icd10' => 'j06.9'], ['occurs_on' => today(), 'assignee_id' => $owner->id]);
        $this->assertSame('J06.9', $cough->value('icd10'));
        $chart = $chart->fresh();
        $this->assertSame(1, $chart->value('_visits'));
        $this->assertSame(1, $chart->value('_unsigned'));
        $this->assertSame('J06.9 Upper respiratory tract infection', $chart->value('_last_diagnosis'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'encounters']), [
            'title' => 'Bad code', 'status' => 'open', 'occurs_on' => today()->toDateString(), 'data' => ['chart' => $chart->id, 'diagnosis' => 'x', 'icd10' => 'XYZ'],
        ])->assertSessionHasErrors('data.icd10');

        $this->actingAs($owner)->post($cough->url().'/actions/sign')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Encounter for John Banda signed and locked.');
        $cough = $cough->fresh();
        $this->assertSame('signed', $cough->status);
        $this->assertNotNull($cough->value('_signed_at'));
        $this->assertSame($owner->id, (int) $cough->value('_signed_by'));
        $this->assertSame(0, $chart->fresh()->value('_unsigned'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'encounters', $cough->id]), [
            'title' => 'Cough', 'status' => 'signed', 'occurs_on' => today()->toDateString(), 'data' => ['chart' => $chart->id, 'diagnosis' => 'Changed', 'icd10' => 'J06.9'],
        ])->assertSessionHasErrors('status');
        $this->assertSame('Upper respiratory tract infection', $cough->fresh()->value('diagnosis'));

        $this->actingAs($owner)->post($cough->url().'/actions/sign')->assertNotFound();
        $this->actingAs($owner)->post($cough->url().'/actions/addendum')->assertSessionHasErrors('note');
        $this->actingAs($owner)->post($cough->url().'/actions/addendum', ['note' => 'Culture negative.'])->assertSessionHas('flash.message', 'Addendum added to the encounter.');
        $cough = $cough->fresh();
        $this->assertCount(1, $cough->value('_addenda'));
        $this->assertSame('signed', $cough->status);
        $this->actingAs($owner)->get($cough->url())->assertOk()->assertSee('Culture negative.');

        $uncoded = $this->record($workspace, $app, 'encounters', 'Headache', 'open', ['chart' => $chart->id, 'diagnosis' => 'Tension headache'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($uncoded->url().'/actions/sign')->assertSessionHasErrors('data.icd10');

        $this->actingAs($owner)->get($chart->url())->assertOk()->assertSee('History')->assertSee('Penicillin');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Notes to sign')->assertSee('Headache');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Top diagnoses')->assertSee('J06.9');

        $this->actingAs($owner)->post($chart->url().'/actions/deceased', ['date_of_death' => today()->toDateString()])
            ->assertSessionHas('flash.message', 'John Banda\'s chart closed: deceased '.today()->format('d M Y').'.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'encounters']), [
            'title' => 'Late note', 'status' => 'open', 'occurs_on' => today()->toDateString(), 'data' => ['chart' => $chart->id, 'diagnosis' => 'x'],
        ])->assertSessionHasErrors('data.chart');
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
