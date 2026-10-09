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

class HealthcareAppsBatchThreeTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_therapy_practice_needs_consent_and_flags_clients_who_stop_coming(): void
    {
        $app = 'mental-health-counselling-therapy';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'clients']), [
            'title' => 'Jane', 'status' => 'active', 'data' => ['risk_level' => 'high'],
        ])->assertSessionHasErrors(['data.consent_signed', 'assignee_id']);

        $jane = $this->record($workspace, $app, 'clients', 'Jane', 'intake', ['risk_level' => 'low']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), [
            'title' => 'Anxiety', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['client' => $jane->id],
        ])->assertSessionHasErrors('data.client');

        $this->actingAs($owner)->post($jane->url().'/actions/consent')->assertSessionHas('flash.message', 'Jane signed consent and is now active.');
        $this->assertSame('active', $jane->fresh()->status);

        foreach ([3, 2, 1] as $daysAgo) {
            $session = $this->record($workspace, $app, 'sessions', 'Week '.$daysAgo, 'booked', ['client' => $jane->id], ['occurs_on' => today()->subDays($daysAgo)]);
            $response = $this->actingAs($owner)->post($session->url().'/actions/miss');
        }
        $response->assertSessionHas('flash.message', 'Session marked missed; Jane has missed 3 in a row — follow up.');
        $this->assertTrue($jane->fresh()->value('_disengaged'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Clients to watch')->assertSee('Missed 3 in a row');

        $today = $this->record($workspace, $app, 'sessions', 'Grounding', 'booked', ['client' => $jane->id, 'modality' => 'video'], ['occurs_on' => today(), 'amount' => 400]);
        $this->actingAs($owner)->post($today->url().'/actions/attend', [])->assertSessionHasErrors('notes');
        $this->actingAs($owner)->post($today->url().'/actions/attend', ['notes' => 'Breathing work', 'homework' => 'Journal'])
            ->assertSessionHas('flash.message', 'Session with Jane recorded.');
        $jane = $jane->fresh();
        $this->assertSame(1, $jane->value('_attended'));
        $this->assertSame(0, $jane->value('_missed_in_a_row'));
        $this->assertFalse($jane->value('_disengaged'));

        $this->actingAs($owner)->post($jane->url().'/actions/discharge')->assertSessionHas('flash.message', 'Jane discharged after 1 session(s).');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), [
            'title' => 'Follow-up', 'status' => 'booked', 'occurs_on' => today()->addWeek()->toDateString(), 'data' => ['client' => $jane->id],
        ])->assertSessionHasErrors('data.client');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Sessions by modality')->assertSee('Video');
    }

    public function test_dietitian_tracks_bmi_keeps_one_active_plan_and_lists_reviews(): void
    {
        $app = 'nutrition-dietitian-plans';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'clients']), [
            'title' => 'Tom', 'status' => 'active', 'data' => ['height' => 300, 'start_weight' => 100],
        ])->assertSessionHasErrors('data.height');

        $tom = $this->record($workspace, $app, 'clients', 'Tom', 'active', ['goal' => 'weight_loss', 'height' => 180, 'start_weight' => 100]);
        $this->assertEquals(30.9, $tom->value('_bmi_start'));

        $first = $this->record($workspace, $app, 'plans', 'Low carb', 'draft', ['client' => $tom->id, 'calories' => 1800, 'meals' => 'Eggs, salad']);
        $review = today()->addWeeks(4);
        $this->actingAs($owner)->post($first->url().'/actions/activate', ['review' => $review->toDateString()])
            ->assertSessionHas('flash.message', 'Meal plan started for Tom; next review '.$review->format('d M Y').'.');
        $this->assertSame('active', $first->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'plans']), [
            'title' => 'Mediterranean', 'status' => 'active', 'occurs_on' => today()->toDateString(), 'data' => ['client' => $tom->id, 'meals' => 'Fish'],
        ])->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'plans']), [
            'title' => 'Mediterranean', 'status' => 'active', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(3)->toDateString(), 'data' => ['client' => $tom->id, 'calories' => 500, 'meals' => 'Fish'],
        ])->assertSessionHasErrors('data.calories');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'plans']), [
            'title' => 'Mediterranean', 'status' => 'active', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(3)->toDateString(), 'data' => ['client' => $tom->id, 'calories' => 2000, 'meals' => 'Fish'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('ended', $first->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Reviews due')->assertSee('Mediterranean');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'checkins']), [
            'title' => 'Week 1', 'status' => 'recorded', 'occurs_on' => today()->toDateString(), 'data' => ['client' => $tom->id],
        ])->assertSessionHasErrors('data.weight');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'checkins']), [
            'title' => 'Week 1', 'status' => 'recorded', 'occurs_on' => today()->toDateString(), 'data' => ['client' => $tom->id, 'weight' => 95],
        ])->assertSessionHasNoErrors();
        $tom = $tom->fresh();
        $this->assertEquals(95, $tom->value('_current_weight'));
        $this->assertEquals(-5, $tom->value('_change'));
        $this->assertEquals(29.3, $tom->value('_bmi_now'));

        $this->actingAs($owner)->post($tom->url().'/actions/complete')->assertSessionHas('flash.message', 'Tom completed the programme (-5 kg).');
        $this->assertSame(0, Record::query()->where('entity', 'plans')->where('status', 'active')->count());
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'checkins']), [
            'title' => 'Week 2', 'status' => 'recorded', 'occurs_on' => today()->toDateString(), 'data' => ['client' => $tom->id, 'weight' => 94],
        ])->assertSessionHasErrors('data.client');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Client progress')->assertSee('Overweight');
    }

    public function test_rehab_scores_progress_and_flags_low_attendance(): void
    {
        $app = 'rehabilitation-occupational-therapy';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'cases']), [
            'title' => 'Sam', 'status' => 'active', 'data' => ['condition' => 'Stroke'],
        ])->assertSessionHasErrors('data.goals');

        $sam = $this->record($workspace, $app, 'cases', 'Sam', 'assessment', ['condition' => 'Stroke', 'goals' => 'Walk unaided', 'funder' => 'MASM']);
        $this->actingAs($owner)->post($sam->url().'/actions/session', ['activities' => 'Gait training', 'progress_score' => 3])
            ->assertSessionHas('flash.message', 'Session recorded for Sam; progress 3/10.');
        $this->assertSame('active', $sam->fresh()->status);
        $this->actingAs($owner)->post($sam->url().'/actions/session', ['activities' => 'Stairs', 'progress_score' => 7])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), [
            'title' => 'Balance', 'status' => 'attended', 'occurs_on' => today()->toDateString(), 'data' => ['case' => $sam->id],
        ])->assertSessionHasErrors('data.progress_score');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), [
            'title' => 'Balance', 'status' => 'attended', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['case' => $sam->id, 'progress_score' => 5],
        ])->assertSessionHasErrors('occurs_on');

        $this->record($workspace, $app, 'sessions', 'Balance', 'missed', ['case' => $sam->id], ['occurs_on' => today()]);
        $this->record($workspace, $app, 'sessions', 'Balance', 'missed', ['case' => $sam->id], ['occurs_on' => today()]);
        $sam = $sam->fresh();
        $this->assertSame(4, $sam->value('_sessions'));
        $this->assertSame(50, $sam->value('_attendance'));
        $this->assertTrue($sam->value('_low_attendance'));
        $this->assertEquals(4, $sam->value('_improvement'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Low attendance')->assertSee('Sam');

        $this->actingAs($owner)->post($sam->url().'/actions/discharge')->assertSessionHas('flash.message', 'Sam discharged with progress +4 points.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), [
            'title' => 'Extra', 'status' => 'attended', 'occurs_on' => today()->toDateString(), 'data' => ['case' => $sam->id, 'progress_score' => 8],
        ])->assertSessionHasErrors('data.case');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Outcomes by condition')->assertSee('MASM');
    }

    public function test_home_care_books_carers_without_clashes_and_holds_visits_while_in_hospital(): void
    {
        $app = 'home-care-elderly-care';
        [$owner, $workspace] = $this->appWorkspace($app);
        $carer = $this->memberOf($workspace);

        $grace = $this->record($workspace, $app, 'clients', 'Grace', 'active', ['address' => '12 Elm Road', 'visits_per_day' => 2]);
        $peter = $this->record($workspace, $app, 'clients', 'Peter', 'active', ['address' => '4 Oak Lane']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [
            'title' => 'Morning', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['client' => $grace->id, 'carer' => $carer->id, 'start_time' => '09:00'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [
            'title' => 'Morning', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['client' => $peter->id, 'carer' => $carer->id, 'start_time' => '09:00'],
        ])->assertSessionHasErrors('data.start_time');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [
            'title' => 'Morning', 'status' => 'scheduled', 'occurs_on' => today()->subDay()->toDateString(), 'data' => ['client' => $peter->id, 'carer' => $carer->id, 'start_time' => '10:00'],
        ])->assertSessionHasErrors('occurs_on');

        $visit = Record::query()->where('entity', 'visits')->firstOrFail();
        $this->actingAs($carer)->post($visit->url().'/actions/start')->assertSessionHas('flash.message', 'Visit to Grace started.');
        $this->actingAs($carer)->post($visit->url().'/actions/complete', [])->assertSessionHasErrors('tasks_done');
        $this->actingAs($carer)->post($visit->url().'/actions/complete', ['tasks_done' => 'Bathing, meds', 'concerns' => 'Swollen ankle'])
            ->assertSessionHas('flash.message', 'Visit to Grace completed after 0 min; concern raised.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Concerns raised')->assertSee('Swollen ankle');

        $tomorrow = $this->record($workspace, $app, 'visits', 'Morning', 'scheduled', ['client' => $grace->id, 'carer' => $carer->id, 'start_time' => '09:00'], ['occurs_on' => today()->addDay()]);
        $this->actingAs($owner)->post($tomorrow->url().'/actions/start')->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($grace->url().'/actions/hospital')->assertSessionHas('flash.message', 'Grace is in hospital; 1 upcoming visit(s) called off.');
        $this->assertSame('missed', $tomorrow->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [
            'title' => 'Evening', 'status' => 'scheduled', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['client' => $grace->id, 'carer' => $carer->id, 'start_time' => '18:00'],
        ])->assertSessionHasErrors(['data.client' => 'Grace is in hospital; visits are on hold.']);

        $stale = $this->record($workspace, $app, 'visits', 'Morning', 'scheduled', ['client' => $peter->id, 'carer' => $carer->id, 'start_time' => '08:00'], ['occurs_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('missed', $stale->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Visits by carer')->assertSee($carer->name);
    }

    public function test_maternity_dates_pregnancies_flags_high_blood_pressure_and_records_deliveries(): void
    {
        $app = 'maternity-antenatal-care';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'pregnancies']), [
            'title' => 'Ruth', 'status' => 'antenatal', 'data' => ['lmp' => today()->addDay()->toDateString(), 'gravida' => 2, 'para' => 2],
        ])->assertSessionHasErrors(['data.lmp', 'data.para']);

        $lmp = today()->subWeeks(20);
        $ruth = $this->record($workspace, $app, 'pregnancies', 'Ruth', 'antenatal', ['lmp' => $lmp->toDateString(), 'gravida' => 2, 'para' => 1, 'risk' => 'low']);
        $this->assertSame($lmp->copy()->addDays(280)->toDateString(), $ruth->due_on->toDateString());
        $this->assertSame(20, $ruth->value('_weeks'));

        $this->actingAs($owner)->post($ruth->url().'/actions/visit', ['blood_pressure' => 'high'])->assertSessionHasErrors('blood_pressure');
        $this->actingAs($owner)->post($ruth->url().'/actions/visit', ['blood_pressure' => '150/95', 'weight' => 70])
            ->assertSessionHas('flash.message', 'Visit at 20 weeks recorded for Ruth — blood pressure 150/95 is high; she is now high risk.');
        $ruth = $ruth->fresh();
        $this->assertSame('high', $ruth->value('risk'));
        $this->assertSame(1, $ruth->value('_visits'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [
            'title' => 'Visit 2', 'status' => 'done', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['pregnancy' => $ruth->id, 'blood_pressure' => '120/80'],
        ])->assertSessionHasErrors('occurs_on');

        $this->record($workspace, $app, 'pregnancies', 'Esther', 'antenatal', ['lmp' => today()->subWeeks(39)->toDateString(), 'gravida' => 1, 'para' => 0]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Due soon')->assertSee('Esther');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliveries']), [
            'title' => 'Baby girl', 'status' => 'live_birth', 'occurs_on' => today()->toDateString(), 'data' => ['pregnancy' => $ruth->id, 'mode' => 'caesarean', 'birth_weight' => 9],
        ])->assertSessionHasErrors('data.birth_weight');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliveries']), [
            'title' => 'Baby girl', 'status' => 'live_birth', 'occurs_on' => today()->toDateString(), 'data' => ['pregnancy' => $ruth->id, 'mode' => 'caesarean', 'birth_weight' => 2.1, 'sex' => 'female'],
        ])->assertSessionHasNoErrors();
        $delivery = Record::query()->where('entity', 'deliveries')->firstOrFail();
        $this->assertTrue($delivery->value('_preterm'));
        $this->assertTrue($delivery->value('_low_birth_weight'));
        $this->assertSame('delivered', $ruth->fresh()->status);

        $this->actingAs($owner)->post($ruth->url().'/actions/close')->assertSessionHas('flash.message', 'Ruth\'s maternity record closed.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliveries']), [
            'title' => 'Twin', 'status' => 'live_birth', 'occurs_on' => today()->toDateString(), 'data' => ['pregnancy' => $ruth->id, 'mode' => 'caesarean', 'birth_weight' => 2],
        ])->assertSessionHasErrors('data.pregnancy');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Deliveries by mode')->assertSee('Caesarean')->assertSee('100%');
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
