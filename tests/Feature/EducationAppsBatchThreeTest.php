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

/** The education apps' rules, batch three: canteen, scholarships, training centres, discipline and sports academies. */
class EducationAppsBatchThreeTest extends TestCase
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

    public function test_canteen_cards_spend_only_their_balance_within_the_daily_limit(): void
    {
        $app = 'canteen';
        [$owner, $workspace] = $this->appWorkspace($app);
        $card = $this->record($workspace, $app, 'cards', 'Chanda Mwila', 'active', ['card_number' => 'cc001', 'daily_limit' => 300]);
        $this->assertSame('CC001', $card->value('card_number'));
        $this->assertEquals(0, $card->value('balance'));
        $this->assertTrue($card->value('_low'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'cards']), [
            'title' => 'Twin card', 'status' => 'active', 'data' => ['card_number' => 'CC001'],
        ])->assertSessionHasErrors('data.card_number');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'topups']), [
            'title' => 'Term 1 top-up', 'status' => 'received', 'amount' => 500, 'data' => ['card' => $card->id, 'method' => 'cash'],
        ])->assertSessionHasNoErrors();
        $this->assertEquals(500, $card->fresh()->value('balance'));
        $this->assertFalse($card->fresh()->value('_low'));

        $sale = ['title' => 'Samosa and juice', 'status' => 'completed', 'occurs_on' => today()->toDateString(), 'data' => ['card' => $card->id, 'payment' => 'card']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), [...$sale, 'amount' => 600])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), [...$sale, 'amount' => 350])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), [...$sale, 'amount' => 200])->assertSessionHasNoErrors();
        $this->assertEquals(300, $card->fresh()->value('balance'));
        $this->assertEquals(200, $card->fresh()->value('_spent_today'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), [...$sale, 'amount' => 150])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), ['title' => 'Cash sale', 'status' => 'completed', 'amount' => 20, 'data' => ['payment' => 'cash']])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'cards', $card->id, 'block']))->assertSessionHas('flash.message', 'Card CC001 is blocked.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), [...$sale, 'amount' => 50])->assertSessionHasErrors('data.card');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'cards', $card->id, 'unblock']))->assertSessionHas('flash.message', 'Card CC001 is active again.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'cards', $card->id, 'top_up']), ['amount' => 200, 'method' => 'mobile_money'])->assertSessionHasNoErrors();
        $this->assertEquals(500, $card->fresh()->value('balance'));

        $sold = Record::query()->ofEntity($app, 'sales')->where('data->payment', 'card')->firstOrFail();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'sales', $sold->id, 'refund']))->assertSessionHas('flash.message', 'Sale refunded to the card.');
        $this->assertSame('refunded', $sold->fresh()->status);
        $this->assertEquals(700, $card->fresh()->value('balance'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'cards', $card->id, 'report_lost']))->assertSessionHasNoErrors();
        $this->assertSame('lost', $card->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'topups']), [
            'title' => 'Late top-up', 'status' => 'received', 'amount' => 100, 'data' => ['card' => $card->id, 'method' => 'cash'],
        ])->assertSessionHasErrors('data.card');

        $empty = $this->record($workspace, $app, 'cards', 'Bwalya Zulu', 'active', ['card_number' => 'CC002']);
        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'cards', $card->id]))->assertOk()->assertSee('Recent sales')->assertSee('Samosa and juice');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Canteen')->assertSee('Cards running low')->assertSee('Bwalya Zulu');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Sales by month')->assertSee('Top-ups by method')->assertSee('Mobile money')->assertSee($empty->value('card_number'));
    }

    public function test_scholarships_are_awarded_within_slots_and_fund_and_only_on_open_schemes(): void
    {
        $app = 'scholarships';
        [$owner, $workspace] = $this->appWorkspace($app);
        $scheme = $this->record($workspace, $app, 'schemes', 'Mining bursary', 'open', ['type' => 'bursary', 'sponsor' => 'Copper Mines Plc', 'slots' => 1], ['amount' => 10000, 'due_on' => today()->addDays(30)]);
        $closed = $this->record($workspace, $app, 'schemes', 'Old scheme', 'closed', ['type' => 'scholarship']);
        $expired = $this->record($workspace, $app, 'schemes', 'Expired scheme', 'open', ['type' => 'scholarship'], ['due_on' => today()->subDay()]);
        $mary = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Mary Banda']);
        $peter = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Peter Daka']);

        $application = ['title' => 'Mary Banda', 'status' => 'submitted', 'contact_id' => $mary->id, 'data' => ['scheme' => $scheme->id, 'institution' => 'UNZA']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), [...$application, 'data' => ['scheme' => $closed->id]])->assertSessionHasErrors('data.scheme');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), [...$application, 'data' => ['scheme' => $expired->id]])->assertSessionHasErrors('data.scheme');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), $application)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), $application)->assertSessionHasErrors('contact_id');
        $first = Record::query()->ofEntity($app, 'applications')->latest('id')->firstOrFail();
        $this->assertEquals(1, $scheme->fresh()->value('_applications'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $first->id, 'shortlist']))->assertSessionHas('flash.message', 'Mary Banda shortlisted.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $first->id, 'award']), ['amount' => 15000])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $first->id, 'award']), ['amount' => 6000])->assertSessionHasNoErrors();
        $this->assertSame('awarded', $first->fresh()->status);
        $this->assertEquals(6000, $first->fresh()->amount);
        $this->assertEquals(1, $scheme->fresh()->value('_awarded'));
        $this->assertEquals(4000, $scheme->fresh()->value('_fund_remaining'));
        $this->assertEquals(0, $scheme->fresh()->value('_slots_remaining'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), [...$application, 'title' => 'Peter Daka', 'contact_id' => $peter->id])->assertSessionHasNoErrors();
        $second = Record::query()->ofEntity($app, 'applications')->latest('id')->firstOrFail();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $second->id, 'shortlist']))->assertRedirect();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $second->id, 'award']), ['amount' => 1000])->assertSessionHasErrors('status');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'applications', $second->id]), [...$application, 'title' => 'Peter Daka', 'contact_id' => $peter->id, 'status' => 'disbursed', 'amount' => 1000])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'applications', $first->id, 'disburse']))->assertSessionHasNoErrors();
        $this->assertSame('disbursed', $first->fresh()->status);
        $this->assertEquals(6000, $scheme->fresh()->value('_disbursed_amount'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'schemes', $scheme->id]), [
            'title' => 'Mining bursary', 'status' => 'open', 'amount' => 5000, 'due_on' => today()->addDays(30)->toDateString(), 'data' => ['type' => 'bursary', 'slots' => 1],
        ])->assertSessionHasErrors('amount');

        $this->artisan('zonseo:run-app-schedules', ['--app' => $app])->assertSuccessful();
        $this->assertSame('closed', $expired->fresh()->status);
        $this->assertSame('open', $scheme->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'schemes', $expired->id, 'reopen']), ['due_on' => today()->addDays(10)->toDateString()])->assertSessionHas('flash.message', 'Expired scheme is open until '.today()->addDays(10)->format('d M Y').'.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'schemes', $scheme->id]))->assertOk()->assertSee('Scheme')->assertSee('Applications')->assertSee('Peter Daka');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Scholarships')->assertSee('Closing soon')->assertSee('Expired scheme');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Schemes')->assertSee('Copper Mines Plc')->assertSee('Applications by institution')->assertSee('UNZA');
    }

    public function test_training_centres_seat_trainees_collect_fees_and_assess_them(): void
    {
        $app = 'training-centres-vocational-colleges';
        [$owner, $workspace] = $this->appWorkspace($app);
        $intake = $this->record($workspace, $app, 'intakes', 'Welding level 1', 'open', ['trade' => 'Welding', 'capacity' => 2, 'trainer' => $owner->id], ['amount' => 3000, 'occurs_on' => today()->subDay(), 'due_on' => today()->addDays(30)]);
        $john = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'John Tembo']);

        $trainee = ['title' => 'John Tembo', 'status' => 'enrolled', 'contact_id' => $john->id, 'amount' => 1000, 'data' => ['intake' => $intake->id, 'id_number' => 'nrc123']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trainees']), [...$trainee, 'amount' => 5000])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trainees']), [...$trainee, 'data' => [...$trainee['data'], 'attendance' => 120]])->assertSessionHasErrors('data.attendance');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trainees']), [...$trainee, 'status' => 'competent'])->assertSessionHasErrors('data.assessment_result');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trainees']), $trainee)->assertSessionHasNoErrors();
        $first = Record::query()->ofEntity($app, 'trainees')->latest('id')->firstOrFail();
        $this->assertSame('NRC123', $first->value('id_number'));
        $this->assertEquals(2000, $first->value('_balance'));
        $this->assertEquals(1, $intake->fresh()->value('_seated'));
        $this->assertEquals(2000, $intake->fresh()->value('_outstanding'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trainees']), [...$trainee, 'title' => 'Twin', 'contact_id' => null, 'data' => ['intake' => $intake->id, 'id_number' => 'NRC123']])->assertSessionHasErrors('data.id_number');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trainees']), ['title' => 'Grace Mulenga', 'status' => 'enrolled', 'amount' => 3000, 'data' => ['intake' => $intake->id, 'attendance' => 80]])->assertSessionHasNoErrors();
        $second = Record::query()->ofEntity($app, 'trainees')->latest('id')->firstOrFail();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trainees']), ['title' => 'Third', 'status' => 'enrolled', 'data' => ['intake' => $intake->id]])->assertSessionHasErrors('data.intake');
        $this->assertEquals(0, $intake->fresh()->value('_free'));

        $this->artisan('zonseo:run-app-schedules', ['--app' => $app])->assertSuccessful();
        $this->assertSame('running', $intake->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'intakes', $intake->id, 'complete']))->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'trainees', $first->id, 'record_payment']), ['amount' => 2500])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'trainees', $first->id, 'record_payment']), ['amount' => 2000])->assertSessionHasNoErrors();
        $this->assertEquals(3000, $first->fresh()->amount);
        $this->assertEquals(0, $first->fresh()->value('_balance'));
        $this->assertEquals(0, $intake->fresh()->value('_outstanding'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'trainees', $first->id, 'assess']), ['result' => 'Pass 82%', 'outcome' => 'competent'])->assertSessionHas('flash.message', 'John Tembo assessed competent.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'trainees', $second->id, 'drop']))->assertSessionHas('flash.message', 'Grace Mulenga dropped out.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'intakes', $intake->id, 'complete']))->assertSessionHas('flash.message', 'Welding level 1 completed with 1 trainees competent.');
        $this->assertEquals(100, $intake->fresh()->value('_pass_rate'));
        $this->assertEquals(1, $intake->fresh()->value('_dropped'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trainees']), ['title' => 'Late', 'status' => 'enrolled', 'data' => ['intake' => $intake->id]])->assertSessionHasErrors('data.intake');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'intakes', $intake->id]))->assertOk()->assertSee('Intake')->assertSee('Trainees')->assertSee('Pass rate');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Training centre')->assertSee('Fees outstanding');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Intakes by trade')->assertSee('Welding')->assertSee('Trainees by outcome');
    }

    public function test_discipline_entries_keep_a_running_total_and_serious_entries_need_the_parent(): void
    {
        $app = 'discipline-behaviour-tracking';
        [$owner, $workspace] = $this->appWorkspace($app);
        $parent = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Agnes Phiri']);

        $entry = ['title' => 'Joseph Phiri', 'status' => 'recorded', 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'merit', 'class_name' => '7B', 'description' => 'Helped a classmate']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [...$entry, 'data' => [...$entry['data'], 'points' => -2]])->assertSessionHasErrors('data.points');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [...$entry, 'data' => [...$entry['data'], 'type' => 'suspension']])->assertSessionHasErrors('contact_id');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [...$entry, 'occurs_on' => today()->addDay()->toDateString()])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [...$entry, 'status' => 'parent_informed'])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), $entry)->assertSessionHasNoErrors();
        $merit = Record::query()->ofEntity($app, 'entries')->latest('id')->firstOrFail();
        $this->assertSame(1, $merit->value('points'));
        $this->assertSame(1, $merit->value('_running_total'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [...$entry, 'contact_id' => $parent->id, 'data' => [...$entry['data'], 'type' => 'detention', 'description' => 'Late three times']])->assertSessionHasNoErrors();
        $detention = Record::query()->ofEntity($app, 'entries')->latest('id')->firstOrFail();
        $this->assertSame(-3, $detention->value('points'));
        $this->assertSame(-2, $detention->value('_running_total'));
        $suspension = $this->record($workspace, $app, 'entries', 'joseph  phiri', 'recorded', ['type' => 'suspension', 'class_name' => '7B', 'description' => 'Fighting'], ['contact_id' => $parent->id, 'occurs_on' => today()]);
        $this->assertSame(-12, $suspension->value('_running_total'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'entries', $merit->id, 'inform_parent']))->assertSessionHasErrors('contact_id');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'entries', $detention->id, 'inform_parent']), ['note' => 'Phoned'])->assertSessionHas('flash.message', 'Agnes Phiri was informed about Joseph Phiri.');
        $this->assertSame('parent_informed', $detention->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'entries', $detention->id, 'resolve']))->assertSessionHas('flash.message', 'Entry for Joseph Phiri resolved.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'entries', $merit->id]))->assertOk()->assertSee('Student record')->assertSee('-12')->assertSee('Fighting');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Behaviour')->assertSee('Students at risk')->assertSee('Joseph Phiri');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Entries by type')->assertSee('Entries by class')->assertSee('7B')->assertSee('Students by net points');
    }

    public function test_sports_academies_keep_athletes_in_active_squads_of_the_right_age_and_mark_sessions_held(): void
    {
        $app = 'sports-academies-coaching';
        [$owner, $workspace] = $this->appWorkspace($app);
        $squad = $this->record($workspace, $app, 'squads', 'U14 Boys', 'active', ['sport' => 'Football', 'age_group' => 'U14', 'coach' => $owner->id], ['amount' => 200]);
        $inactive = $this->record($workspace, $app, 'squads', 'Old squad', 'inactive', ['sport' => 'Football']);

        $athlete = ['title' => 'Mapalo Sakala', 'status' => 'active', 'data' => ['squad' => $squad->id, 'position' => 'Striker', 'date_of_birth' => today()->subYears(12)->toDateString()]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'athletes']), [...$athlete, 'data' => [...$athlete['data'], 'squad' => $inactive->id]])->assertSessionHasErrors('data.squad');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'athletes']), [...$athlete, 'data' => [...$athlete['data'], 'date_of_birth' => today()->subYears(15)->toDateString()]])->assertSessionHasErrors('data.date_of_birth');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'athletes']), $athlete)->assertSessionHasNoErrors();
        $mapalo = Record::query()->ofEntity($app, 'athletes')->latest('id')->firstOrFail();
        $this->assertSame(12, $mapalo->value('_age'));
        $this->assertEquals(1, $squad->fresh()->value('_athletes'));
        $this->assertEquals(200, $squad->fresh()->value('_monthly_fees'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), ['title' => 'Shooting', 'status' => 'held', 'occurs_on' => today()->toDateString(), 'data' => ['squad' => $squad->id, 'attendance' => 5]])->assertSessionHasErrors('data.attendance');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), ['title' => 'Shooting', 'status' => 'held', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['squad' => $squad->id, 'attendance' => 1]])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'squads', $squad->id, 'schedule_session']), ['focus' => 'Passing', 'occurs_on' => today()->toDateString(), 'start_time' => '16:00'])->assertSessionHas('flash.message', 'Session on '.today()->format('d M').' scheduled for U14 Boys.');
        $session = Record::query()->ofEntity($app, 'sessions')->latest('id')->firstOrFail();
        $this->assertSame('planned', $session->status);
        $this->assertSame($owner->id, $session->assignee_id);
        $this->assertSame(1, $session->value('_squad_size'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'sessions', $session->id, 'hold']), ['attendance' => 2])->assertSessionHasErrors('attendance');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'sessions', $session->id, 'hold']), ['attendance' => 1])->assertSessionHas('flash.message', 'Session held with 1 of 1 athletes.');
        $this->assertSame(100, $session->fresh()->value('_attendance_pct'));
        $this->assertEquals(100, $squad->fresh()->value('_average_attendance'));
        $this->assertEquals(1, $squad->fresh()->value('_sessions_month'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'squads', $squad->id, 'deactivate']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'athletes', $mapalo->id, 'injure']))->assertSessionHas('flash.message', 'Mapalo Sakala is injured.');
        $this->assertEquals(1, $squad->fresh()->value('_injured'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'athletes', $mapalo->id, 'recover']))->assertSessionHas('flash.message', 'Mapalo Sakala is fit again.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'athletes', $mapalo->id, 'move']), ['squad' => $inactive->id])->assertSessionHasErrors('squad');
        $older = $this->record($workspace, $app, 'squads', 'U16 Boys', 'active', ['sport' => 'Football', 'age_group' => 'Under 16', 'coach' => $owner->id], ['amount' => 250]);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'athletes', $mapalo->id, 'move']), ['squad' => $older->id])->assertSessionHas('flash.message', 'Mapalo Sakala moved to U16 Boys.');
        $this->assertEquals(0, $squad->fresh()->value('_athletes'));
        $this->assertEquals(250, $older->fresh()->value('_monthly_fees'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'squads', $squad->id, 'deactivate']))->assertSessionHas('flash.message', 'U14 Boys is inactive.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'squads', $older->id]))->assertOk()->assertSee('Squad')->assertSee('Athletes')->assertSee('Mapalo Sakala')->assertSee('Age 12');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Academy')->assertSee('sessions');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Squads')->assertSee('Sessions by month')->assertSee('Athletes by sport')->assertSee('Football');
    }
}
