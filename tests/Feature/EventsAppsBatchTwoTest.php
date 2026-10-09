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

/** The events & community apps' rules, batch two: mosque, fundraising, voting, cinema booking and sports leagues. */
class EventsAppsBatchTwoTest extends TestCase
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

    public function test_mosque_receipts_contributions_in_sequence_and_tracks_madrasa_students(): void
    {
        $app = 'mosque';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'contributions']), [
            'title' => 'Ahmed Said', 'status' => 'received', 'amount' => 0, 'data' => ['type' => 'zakat', 'method' => 'cash'],
        ])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'contributions']), [
            'title' => 'Ahmed Said', 'status' => 'received', 'amount' => 500, 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['type' => 'zakat', 'method' => 'cash'],
        ])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'contributions']), [
            'title' => 'Ahmed Said', 'status' => 'received', 'amount' => 500, 'data' => ['type' => 'zakat', 'method' => 'cash'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $zakat = Record::where('entity', 'contributions')->firstOrFail();
        $this->assertSame(today()->toDateString(), $zakat->occurs_on->toDateString());
        $this->assertNull($zakat->value('_receipt_number'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'contributions', $zakat->id, 'receipt']))->assertRedirect()->assertSessionHas('flash.message', 'Receipt RCT-000001 issued to Ahmed Said.');
        $this->assertSame('receipted', $zakat->fresh()->status);
        $this->assertSame(today()->toDateString(), $zakat->fresh()->value('_receipted_on'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'contributions']), [
            'title' => 'Halima Omar', 'status' => 'receipted', 'amount' => 200, 'data' => ['type' => 'sadaqah', 'method' => 'mobile_money'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('RCT-000002', Record::where('entity', 'contributions')->where('title', 'Halima Omar')->firstOrFail()->value('_receipt_number'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'students']), [
            'title' => 'Yusuf Ali', 'status' => 'enrolled', 'amount' => -5, 'data' => ['class' => 'Hifz 1'],
        ])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'students']), [
            'title' => 'Yusuf Ali', 'status' => 'completed', 'amount' => 300, 'data' => ['class' => 'Hifz 1'],
        ])->assertSessionHasErrors('data.hifz_progress');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'students']), [
            'title' => 'Yusuf Ali', 'status' => 'enrolled', 'amount' => 300, 'data' => ['class' => 'Hifz 1', 'guardian' => 'Ali Hassan'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $yusuf = Record::where('entity', 'students')->firstOrFail();
        $this->assertEquals(3600, $yusuf->value('_annual_fee'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'students', $yusuf->id, 'update_progress']), ['hifz_progress' => 'Juz 5'])->assertRedirect()->assertSessionHas('flash.message', 'Yusuf Ali: Juz 5.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'students', $yusuf->id, 'complete']), ['hifz_progress' => 'Juz 30'])->assertRedirect()->assertSessionHas('flash.message', 'Yusuf Ali completed the madrasa (Juz 30).');
        $this->assertSame('completed', $yusuf->fresh()->status);
        $this->assertSame(today()->toDateString(), $yusuf->fresh()->value('_left_on'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'members']), [
            'title' => 'Fatima Noor', 'status' => 'active', 'data' => ['household_size' => 0],
        ])->assertSessionHasErrors('data.household_size');
        $fatima = $this->record($workspace, $app, 'members', 'Fatima Noor', 'active', ['household_size' => 4, 'phone' => '0712']);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'members', $fatima->id, 'moved']))->assertRedirect()->assertSessionHas('flash.message', 'Fatima Noor has moved away.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'members', $fatima->id, 'reactivate']))->assertRedirect()->assertSessionHas('flash.message', 'Fatima Noor is active again.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'students', $yusuf->id]))->assertOk()->assertSee('Madrasa')->assertSee('Monthly fee')->assertSee('Juz 30');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Mosque')->assertSee('Zakat this year')->assertSee('Giving this month');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Contributions by type')->assertSee('Contributions by method')->assertSee('Contributions by month')->assertSee('Madrasa by class')->assertSee('Hifz 1');
    }

    public function test_fundraising_campaigns_count_received_donations_and_close_when_they_end(): void
    {
        $app = 'fundraising';
        [$owner, $workspace] = $this->appWorkspace($app);
        $campaign = $this->record($workspace, $app, 'campaigns', 'Borehole', 'planning', ['goal' => 10000]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'donations']), [
            'title' => 'Amina Yusuf', 'status' => 'pledged', 'amount' => 2000, 'data' => ['campaign' => $campaign->id, 'method' => 'bank'],
        ])->assertSessionHasErrors('data.campaign');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'campaigns', $campaign->id, 'launch']), ['due_on' => today()->addDays(30)->toDateString()])
            ->assertRedirect()->assertSessionHas('flash.message', 'Borehole is live until '.today()->addDays(30)->format('d M Y').'.');
        $this->assertSame('live', $campaign->fresh()->status);
        $this->assertEquals(30, $campaign->fresh()->value('_days_left'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'donations']), [
            'title' => 'Amina Yusuf', 'status' => 'pledged', 'amount' => 0, 'data' => ['campaign' => $campaign->id, 'method' => 'bank'],
        ])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'donations']), [
            'title' => 'Amina Yusuf', 'status' => 'pledged', 'amount' => 2000, 'data' => ['campaign' => $campaign->id, 'method' => 'bank', 'tax_certificate' => 1],
        ])->assertSessionHasErrors('data.tax_certificate');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'donations']), [
            'title' => 'Amina Yusuf', 'status' => 'pledged', 'amount' => 2000, 'data' => ['campaign' => $campaign->id, 'method' => 'bank'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $amina = Record::where('entity', 'donations')->firstOrFail();
        $this->assertEquals(0, $campaign->fresh()->value('raised'));
        $this->assertEquals(2000, $campaign->fresh()->value('_pledged'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'donations', $amina->id, 'receive']), ['method' => 'mobile_money'])->assertRedirect()->assertSessionHas('flash.message', 'Donation received from Amina Yusuf.');
        $this->assertSame('mobile_money', $amina->fresh()->value('method'));
        $this->assertEquals(2000, $campaign->fresh()->value('raised'));
        $this->assertEquals(20, $campaign->fresh()->value('_progress'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'donations', $amina->id, 'receipt']), ['tax_certificate' => 1])->assertRedirect()->assertSessionHas('flash.message', 'Receipt sent to Amina Yusuf with a tax certificate.');
        $this->assertTrue($amina->fresh()->value('tax_certificate'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'donations']), [
            'title' => 'Bob Mwangi', 'status' => 'received', 'amount' => 3000, 'data' => ['campaign' => $campaign->id, 'method' => 'card', 'recurring' => 1],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $bob = Record::where('entity', 'donations')->where('title', 'Bob Mwangi')->firstOrFail();
        $this->assertEquals(5000, $campaign->fresh()->value('raised'));
        $this->assertEquals(5000, $campaign->fresh()->value('_to_go'));
        $this->assertEquals(2, $campaign->fresh()->value('_donors'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'donations', $bob->id, 'refund']))->assertRedirect()->assertSessionHas('flash.message', 'Donation from Bob Mwangi refunded.');
        $this->assertEquals(2000, $campaign->fresh()->value('raised'));

        $old = $this->record($workspace, $app, 'campaigns', 'Old drive', 'live', ['goal' => 500], ['occurs_on' => today()->subMonths(2), 'due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('closed', $old->fresh()->status);
        $this->assertSame('live', $campaign->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'campaigns', $campaign->id, 'close']))->assertRedirect()->assertSessionHas('flash.message', 'Borehole closed at 20% of its goal.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'campaigns', $campaign->id]))->assertOk()->assertSee('Campaign')->assertSee('Donations')->assertSee('Amina Yusuf')->assertSee('20%');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Fundraising')->assertSee('Live campaigns');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Campaigns')->assertSee('Donations by method')->assertSee('Donations by month')->assertSee('Borehole');
    }

    public function test_elections_take_nominations_then_votes_and_publish_the_winner(): void
    {
        $app = 'voting-elections-online-polls';
        [$owner, $workspace] = $this->appWorkspace($app);
        $election = $this->record($workspace, $app, 'elections', 'AGM 2026', 'draft', ['method' => 'online', 'eligible_voters' => 10, 'positions' => 'Chair']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'candidates']), [
            'title' => 'Alice Wambui', 'status' => 'nominated', 'data' => ['election' => $election->id, 'position' => 'Chair'],
        ])->assertSessionHasErrors('data.election');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'elections', $election->id, 'open_nominations']))->assertRedirect()->assertSessionHas('flash.message', 'Nominations for AGM 2026 are open.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'candidates']), [
            'title' => 'Alice Wambui', 'status' => 'nominated', 'data' => ['election' => $election->id, 'position' => 'Chair'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $alice = Record::where('entity', 'candidates')->firstOrFail();
        $bob = $this->record($workspace, $app, 'candidates', 'Bob Otieno', 'nominated', ['election' => $election->id, 'position' => 'Chair']);
        $this->assertEquals(2, $election->fresh()->value('_nominated'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'elections', $election->id, 'open_voting']), ['occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(7)->toDateString()])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'candidates', $alice->id, 'approve']))->assertRedirect()->assertSessionHas('flash.message', 'Alice Wambui approved for Chair.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'candidates', $bob->id, 'approve']))->assertRedirect();
        $this->assertEquals(2, $election->fresh()->value('_candidates'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'voters']), [
            'title' => 'Voter One', 'status' => 'eligible', 'data' => ['election' => $election->id, 'member_number' => 'm001'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $one = Record::where('entity', 'voters')->firstOrFail();
        $this->assertSame('M001', $one->value('member_number'));
        $this->assertSame(6, strlen((string) $one->value('voting_code')));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'voters']), [
            'title' => 'Voter Two', 'status' => 'eligible', 'data' => ['election' => $election->id, 'member_number' => 'M001'],
        ])->assertSessionHasErrors('data.member_number');
        $two = $this->record($workspace, $app, 'voters', 'Voter Two', 'eligible', ['election' => $election->id, 'member_number' => 'M002']);
        $three = $this->record($workspace, $app, 'voters', 'Voter Three', 'eligible', ['election' => $election->id, 'member_number' => 'M003']);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'voters', $one->id, 'mark_voted']))->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'elections', $election->id, 'open_voting']), ['occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(7)->toDateString()])
            ->assertRedirect()->assertSessionHas('flash.message', 'Voting in AGM 2026 is open until '.today()->addDays(7)->format('d M Y').' with 2 candidates.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'voters', $one->id, 'mark_voted']))->assertRedirect()->assertSessionHas('flash.message', 'Voter One voted.');
        $this->assertEquals(10, $election->fresh()->value('turnout'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'candidates', $alice->id, 'record_votes']), ['votes' => 5])->assertSessionHasErrors('votes');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'voters', $two->id, 'mark_voted']))->assertRedirect();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'voters', $three->id, 'mark_voted']))->assertRedirect();
        $this->assertEquals(30, $election->fresh()->value('turnout'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'candidates', $alice->id, 'record_votes']), ['votes' => 2])->assertRedirect()->assertSessionHas('flash.message', 'Alice Wambui: 2 votes.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'candidates', $bob->id, 'record_votes']), ['votes' => 1])->assertRedirect();
        $this->assertSame('Alice Wambui', $election->fresh()->value('_leader'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'elections', $election->id, 'publish_results']))->assertNotFound();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'elections', $election->id, 'close_voting']))->assertRedirect()->assertSessionHas('flash.message', 'Voting in AGM 2026 closed; turnout 30%.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'elections', $election->id, 'publish_results']))->assertRedirect()->assertSessionHas('flash.message', 'Results published: Alice Wambui as Chair elected.');
        $this->assertSame('elected', $alice->fresh()->status);
        $this->assertSame('not_elected', $bob->fresh()->status);
        $this->assertSame('results_published', $election->fresh()->status);

        $old = $this->record($workspace, $app, 'elections', 'Old poll', 'voting', ['method' => 'paper'], ['occurs_on' => today()->subDays(10), 'due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('closed', $old->fresh()->status);

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'elections', $election->id]))->assertOk()->assertSee('Election')->assertSee('Candidates')->assertSee('Alice Wambui')->assertSee('Elected');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Elections')->assertSee('Open elections');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Elections')->assertSee('Results')->assertSee('Turnout by method')->assertSee('AGM 2026');
    }

    public function test_cinema_sells_each_seat_once_and_flips_shows_between_on_sale_and_sold_out(): void
    {
        $app = 'cinema-theatre-booking';
        [$owner, $workspace] = $this->appWorkspace($app);
        $show = $this->record($workspace, $app, 'shows', 'Dune', 'scheduled', ['screen' => 'Screen 1', 'start_time' => '19:00', 'seats' => 3], ['occurs_on' => today()]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Ann Kamau', 'status' => 'reserved', 'amount' => 200, 'data' => ['show' => $show->id, 'seats_booked' => 'A1, A2'],
        ])->assertSessionHasErrors('data.show');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'shows', $show->id, 'open_sales']))->assertRedirect()->assertSessionHas('flash.message', 'Dune is on sale: 3 seats in Screen 1.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Ann Kamau', 'status' => 'reserved', 'amount' => 200, 'data' => ['show' => $show->id, 'seats_booked' => ''],
        ])->assertSessionHasErrors('data.seats_booked');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Ann Kamau', 'status' => 'reserved', 'amount' => 200, 'data' => ['show' => $show->id, 'seats_booked' => 'a1, a2'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $ann = Record::where('entity', 'bookings')->firstOrFail();
        $this->assertSame('A1, A2', $ann->value('seats_booked'));
        $this->assertEquals(2, $show->fresh()->value('seats_sold'));
        $this->assertEquals(1, $show->fresh()->value('_remaining'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Ben Odhiambo', 'status' => 'reserved', 'amount' => 100, 'data' => ['show' => $show->id, 'seats_booked' => 'A2'],
        ])->assertSessionHasErrors('data.seats_booked');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Ben Odhiambo', 'status' => 'reserved', 'amount' => 200, 'data' => ['show' => $show->id, 'seats_booked' => 'A3, A4'],
        ])->assertSessionHasErrors('data.seats_booked');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Ben Odhiambo', 'status' => 'reserved', 'amount' => 100, 'data' => ['show' => $show->id, 'seats_booked' => 'A3'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $ben = Record::where('entity', 'bookings')->where('title', 'Ben Odhiambo')->firstOrFail();
        $this->assertSame('sold_out', $show->fresh()->status);
        $this->assertEquals(2, $show->fresh()->value('_unpaid'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bookings', $ann->id, 'pay']), ['amount' => 200])->assertRedirect()->assertSessionHas('flash.message', 'Booking paid; seats A1, A2 held for Ann Kamau.');
        $this->assertEquals(200, $show->fresh()->value('_takings'));
        $this->assertSame(today()->toDateString(), $ann->fresh()->value('_paid_on'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bookings', $ann->id, 'collect']))->assertRedirect()->assertSessionHas('flash.message', 'Tickets for seats A1, A2 collected by Ann Kamau.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bookings', $ben->id, 'cancel']))->assertRedirect()->assertSessionHas('flash.message', 'Booking cancelled; seats A3 released.');
        $this->assertSame('on_sale', $show->fresh()->status);
        $this->assertEquals(1, $show->fresh()->value('_remaining'));
        $this->assertEquals(67, $show->fresh()->value('_occupancy'));

        $tomorrow = $this->record($workspace, $app, 'shows', 'Tomorrow', 'on_sale', ['screen' => 'Screen 2', 'start_time' => '20:00', 'seats' => 50], ['occurs_on' => today()->addDay()]);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'shows', $tomorrow->id, 'complete']))->assertSessionHasErrors('status');
        $past = $this->record($workspace, $app, 'shows', 'Yesterday', 'scheduled', ['screen' => 'Screen 2', 'start_time' => '20:00', 'seats' => 50], ['occurs_on' => today()->subDay()]);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'shows', $past->id, 'open_sales']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'shows', $show->id, 'complete']))->assertRedirect()->assertSessionHas('flash.message', 'Dune screened to 2 of 3 seats.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'shows', $show->id]))->assertOk()->assertSee('Box office')->assertSee('Bookings')->assertSee('Ann Kamau')->assertSee('67%');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Cinema')->assertSee("Today's shows")->assertSee('Dune');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Shows')->assertSee('Takings by screen')->assertSee('Bookings by month')->assertSee('Screen 1');
    }

    public function test_sports_league_keeps_a_table_from_played_fixtures_and_moves_players_by_transfer(): void
    {
        $app = 'sports-leagues';
        [$owner, $workspace] = $this->appWorkspace($app);
        $lions = $this->record($workspace, $app, 'teams', 'Lions', 'active', ['division' => 'Premier']);
        $tigers = $this->record($workspace, $app, 'teams', 'Tigers', 'active', ['division' => 'Premier']);
        $bears = $this->record($workspace, $app, 'teams', 'Bears', 'active', ['division' => 'Premier']);
        $quitters = $this->record($workspace, $app, 'teams', 'Quitters', 'withdrawn', ['division' => 'Premier']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'players']), [
            'title' => 'Sam Kiprop', 'status' => 'registered', 'data' => ['team' => $lions->id, 'position' => 'Striker', 'jersey_number' => 9, 'date_of_birth' => today()->subYears(24)->toDateString()],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $sam = Record::where('entity', 'players')->firstOrFail();
        $this->assertEquals(24, $sam->value('_age'));
        $this->assertEquals(1, $lions->fresh()->value('_players'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'players']), [
            'title' => 'Dan Mutua', 'status' => 'registered', 'data' => ['team' => $lions->id, 'jersey_number' => 9],
        ])->assertSessionHasErrors('data.jersey_number');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'players']), [
            'title' => 'Dan Mutua', 'status' => 'registered', 'data' => ['team' => $quitters->id, 'jersey_number' => 10],
        ])->assertSessionHasErrors('data.team');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'players']), [
            'title' => 'Dan Mutua', 'status' => 'registered', 'data' => ['team' => $lions->id, 'jersey_number' => 10, 'date_of_birth' => today()->addYear()->toDateString()],
        ])->assertSessionHasErrors('data.date_of_birth');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'fixtures']), [
            'title' => 'Match', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['home_team' => $lions->id, 'away_team' => $lions->id],
        ])->assertSessionHasErrors('data.away_team');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'fixtures']), [
            'title' => 'Match', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['home_team' => $lions->id, 'away_team' => $quitters->id],
        ])->assertSessionHasErrors('data.away_team');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'fixtures']), [
            'title' => 'Match', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['home_team' => $lions->id, 'away_team' => $tigers->id, 'venue' => 'City Park'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $derby = Record::where('entity', 'fixtures')->firstOrFail();
        $this->assertSame('Lions v Tigers', $derby->title);
        $this->assertSame(today()->toDateString(), $lions->fresh()->value('_next_fixture'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'fixtures']), [
            'title' => 'Match', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['home_team' => $bears->id, 'away_team' => $lions->id],
        ])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'fixtures']), [
            'title' => 'Match', 'status' => 'scheduled', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['home_team' => $bears->id, 'away_team' => $lions->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $second = Record::where('entity', 'fixtures')->where('title', 'Bears v Lions')->firstOrFail();

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'fixtures', $second->id, 'record_result']), ['home_score' => 1, 'away_score' => 1])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'fixtures', $derby->id, 'record_result']), ['home_score' => 2, 'away_score' => 1])->assertRedirect()->assertSessionHas('flash.message', 'Lions 2–1 Tigers.');
        $this->assertSame('2–1', $derby->fresh()->value('_result'));
        $this->assertSame('Lions', $derby->fresh()->value('_winner'));
        $this->assertEquals(3, $lions->fresh()->value('_points'));
        $this->assertEquals(1, $lions->fresh()->value('_goal_difference'));
        $this->assertEquals(1, $tigers->fresh()->value('_lost'));
        $this->assertEquals(0, $tigers->fresh()->value('_points'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'teams', $lions->id, 'withdraw']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'fixtures', $second->id, 'postpone']), ['occurs_on' => today()->addDays(7)->toDateString()])
            ->assertRedirect()->assertSessionHas('flash.message', 'Bears v Lions moved to '.today()->addDays(7)->format('d M Y').'.');
        $this->assertSame(today()->addDays(7)->toDateString(), $second->fresh()->occurs_on->toDateString());
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'fixtures', $second->id, 'abandon']))->assertRedirect()->assertSessionHas('flash.message', 'Bears v Lions abandoned.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'teams', $lions->id, 'withdraw']))->assertRedirect()->assertSessionHas('flash.message', 'Lions withdrew from the league.');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'players', $sam->id, 'transfer']), ['team' => $quitters->id])->assertSessionHasErrors('team');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'players', $sam->id, 'transfer']), ['team' => $tigers->id])->assertRedirect()->assertSessionHas('flash.message', 'Sam Kiprop transferred from Lions to Tigers.');
        $this->assertNull($sam->fresh()->value('jersey_number'));
        $this->assertEquals(1, $tigers->fresh()->value('_players'));
        $this->assertEquals(0, $lions->fresh()->value('_players'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'players', $sam->id, 'injure']))->assertRedirect()->assertSessionHas('flash.message', 'Sam Kiprop is injured.');
        $this->assertEquals(1, $tigers->fresh()->value('_unavailable'));

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'teams', $tigers->id]))->assertOk()->assertSee('Team')->assertSee('Squad')->assertSee('Sam Kiprop')->assertSee('Fixtures');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('League')->assertSee('League table')->assertSee('Fixtures this week')->assertSee('Tigers');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('League table')->assertSee('Fixtures')->assertSee('Players by team')->assertSee('Lions v Tigers');
    }
}
