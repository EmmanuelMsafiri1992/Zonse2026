<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The events & community apps' rules, batch one: NGO grants, volunteers, memberships, ticketing, event registration and venue booking. */
class EventsAppsTest extends TestCase
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

    public function test_ngo_funds_beneficiaries_only_from_awarded_grants_and_tracks_reports(): void
    {
        $app = 'ngo';
        [$owner, $workspace] = $this->appWorkspace($app);
        $grant = $this->record($workspace, $app, 'grants', 'Clean water', 'pipeline', ['funder' => 'Sida', 'programme' => 'WASH', 'report_frequency' => 'quarterly']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'beneficiaries']), [
            'title' => 'Mary Atieno', 'status' => 'active', 'data' => ['grant' => $grant->id, 'location' => 'Kisumu', 'sex' => 'female', 'age' => 34],
        ])->assertSessionHasErrors('data.grant');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'grants', $grant->id, 'apply']))->assertRedirect()->assertSessionHas('flash.message', 'Application to Sida recorded.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'grants', $grant->id, 'award']), ['occurs_on' => today()->toDateString(), 'due_on' => today()->addYear()->toDateString()])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'grants', $grant->id, 'award']), ['amount' => 50000, 'occurs_on' => today()->toDateString(), 'due_on' => today()->addYear()->toDateString()])
            ->assertRedirect()->assertSessionHas('flash.message', 'Sida awarded Clean water until '.today()->addYear()->format('d M Y').'.');
        $this->assertSame('awarded', $grant->fresh()->status);
        $this->assertEquals(50000, $grant->fresh()->amount);
        $this->assertSame(today()->addMonths(3)->toDateString(), $grant->fresh()->value('_next_report'));
        $this->assertSame(today()->toDateString(), $grant->fresh()->value('_awarded_on'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'beneficiaries']), [
            'title' => 'Mary Atieno', 'status' => 'active', 'occurs_on' => today()->subDay()->toDateString(), 'data' => ['grant' => $grant->id, 'location' => 'Kisumu', 'sex' => 'female', 'age' => 34],
        ])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'beneficiaries']), [
            'title' => 'Mary Atieno', 'status' => 'active', 'data' => ['grant' => $grant->id, 'location' => 'Kisumu', 'sex' => 'female', 'age' => 200],
        ])->assertSessionHasErrors('data.age');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'beneficiaries']), [
            'title' => 'Mary Atieno', 'status' => 'active', 'data' => ['grant' => $grant->id, 'location' => 'Kisumu', 'sex' => 'female', 'age' => 34],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $mary = Record::where('entity', 'beneficiaries')->firstOrFail();
        $this->assertSame('WASH', $mary->value('programme'));
        $this->assertEquals(1, $grant->fresh()->value('_active'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'grants', $grant->id, 'close']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'beneficiaries', $mary->id, 'graduate']))->assertRedirect()->assertSessionHas('flash.message', 'Mary Atieno graduated.');
        $this->assertSame(today()->toDateString(), $mary->fresh()->value('_exited_on'));
        $this->assertEquals(1, $grant->fresh()->value('_graduated'));
        $this->assertEquals(0, $grant->fresh()->value('_active'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'grants', $grant->id, 'start_reporting']))->assertRedirect()
            ->assertSessionHas('flash.message', 'Clean water is in reporting; next report due '.today()->addMonths(3)->format('d M Y').'.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'grants', $grant->id, 'close']))->assertRedirect()->assertSessionHas('flash.message', 'Clean water closed.');
        $this->assertNull($grant->fresh()->value('_next_report'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'beneficiaries', $mary->id, 'reactivate']))->assertSessionHasErrors('data.grant');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'grants', $grant->id]))->assertOk()->assertSee('Grant')->assertSee('Beneficiaries')->assertSee('Mary Atieno');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('NGO')->assertSee('Reports due');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Grants by funder')->assertSee('Beneficiaries by programme')->assertSee('Beneficiaries by location')->assertSee('WASH')->assertSee('Kisumu');
    }

    public function test_volunteers_are_scheduled_on_days_they_are_available_and_tracked_for_reliability(): void
    {
        $app = 'volunteers';
        [$owner, $workspace] = $this->appWorkspace($app);
        $grace = $this->record($workspace, $app, 'volunteers', 'Grace Njeri', 'active', ['availability' => 'weekdays']);
        $paul = $this->record($workspace, $app, 'volunteers', 'Paul Otieno', 'active', ['availability' => 'any']);
        $retired = $this->record($workspace, $app, 'volunteers', 'Old Hand', 'inactive', ['availability' => 'any']);
        $saturday = today()->next(Carbon::SATURDAY);
        $monday = today()->next(Carbon::MONDAY);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shifts']), [
            'title' => 'Sorting', 'status' => 'scheduled', 'occurs_on' => $monday->toDateString(), 'data' => ['volunteer' => $retired->id],
        ])->assertSessionHasErrors('data.volunteer');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shifts']), [
            'title' => 'Sorting', 'status' => 'scheduled', 'occurs_on' => $saturday->toDateString(), 'data' => ['volunteer' => $grace->id],
        ])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shifts']), [
            'title' => 'Sorting', 'status' => 'scheduled', 'occurs_on' => $monday->toDateString(), 'data' => ['volunteer' => $grace->id, 'hours' => 20],
        ])->assertSessionHasErrors('data.hours');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'volunteers', $grace->id, 'schedule']), ['task' => 'Food bank', 'occurs_on' => $saturday->toDateString(), 'hours' => 3, 'location' => 'Kibera'])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'volunteers', $grace->id, 'schedule']), ['task' => 'Food bank', 'occurs_on' => $monday->toDateString(), 'hours' => 3, 'location' => 'Kibera'])
            ->assertRedirect()->assertSessionHas('flash.message', 'Grace Njeri is scheduled for Food bank on '.$monday->format('d M Y').'.');
        $foodBank = Record::where('entity', 'shifts')->where('title', 'Food bank')->firstOrFail();
        $this->assertEquals(1, $grace->fresh()->value('_scheduled'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'shifts', $foodBank->id, 'complete']))->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shifts']), [
            'title' => 'Soup kitchen', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['volunteer' => $paul->id, 'location' => 'Mathare'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $soup = Record::where('entity', 'shifts')->where('title', 'Soup kitchen')->firstOrFail();
        $this->assertEquals(2, $soup->value('hours'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'shifts', $soup->id, 'complete']), ['hours' => 2.5])->assertRedirect()->assertSessionHas('flash.message', 'Shift completed: 2.5 hours.');
        $this->assertEquals(2.5, $paul->fresh()->value('_hours'));
        $this->assertEquals(100, $paul->fresh()->value('_reliability'));
        $this->assertSame(today()->toDateString(), $paul->fresh()->value('_last_shift'));

        $missed = $this->record($workspace, $app, 'shifts', 'Fundraiser', 'scheduled', ['volunteer' => $paul->id, 'hours' => 4], ['occurs_on' => today()->subDay()]);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'shifts', $missed->id, 'miss']))->assertRedirect()->assertSessionHas('flash.message', 'Shift marked as missed.');
        $this->assertEquals(1, $paul->fresh()->value('_missed'));
        $this->assertEquals(50, $paul->fresh()->value('_reliability'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'volunteers', $grace->id, 'deactivate']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'volunteers', $paul->id, 'deactivate']))->assertRedirect()->assertSessionHas('flash.message', 'Paul Otieno is inactive.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'volunteers', $paul->id, 'activate']))->assertRedirect()->assertSessionHas('flash.message', 'Paul Otieno is active.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'volunteers', $paul->id]))->assertOk()->assertSee('Volunteer')->assertSee('Reliability')->assertSee('50%')->assertSee('Soup kitchen');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Volunteers')->assertSee('Upcoming shifts')->assertSee('Food bank');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Hours by month')->assertSee('Shifts by location')->assertSee('Mathare')->assertSee('Paul Otieno');
    }

    public function test_memberships_number_members_set_renewals_and_lapse_overdue_ones(): void
    {
        $app = 'memberships';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'members']), [
            'title' => 'Jane Doe', 'status' => 'active', 'amount' => 2000, 'occurs_on' => today()->toDateString(), 'data' => ['tier' => 'gold'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $jane = Record::where('entity', 'members')->where('title', 'Jane Doe')->firstOrFail();
        $this->assertSame(today()->addYear()->toDateString(), $jane->due_on->toDateString());
        $this->assertStringStartsWith('M-'.today()->format('y'), $jane->value('membership_number'));
        $this->assertFalse((bool) $jane->value('_overdue'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'members']), [
            'title' => 'John Smith', 'status' => 'active', 'amount' => 500, 'occurs_on' => today()->toDateString(), 'data' => ['tier' => 'standard', 'membership_number' => 'ab12'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $john = Record::where('entity', 'members')->where('title', 'John Smith')->firstOrFail();
        $this->assertSame('AB12', $john->value('membership_number'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'members']), [
            'title' => 'Twin', 'status' => 'active', 'amount' => 500, 'data' => ['tier' => 'standard', 'membership_number' => 'AB12'],
        ])->assertSessionHasErrors('data.membership_number');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'members']), [
            'title' => 'Honoured', 'status' => 'active', 'amount' => 100, 'data' => ['tier' => 'honorary'],
        ])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'members']), [
            'title' => 'Backwards', 'status' => 'active', 'amount' => 100, 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(), 'data' => ['tier' => 'standard'],
        ])->assertSessionHasErrors('due_on');

        $larry = $this->record($workspace, $app, 'members', 'Lapsing Larry', 'active', ['tier' => 'silver'], ['amount' => 800, 'occurs_on' => today()->subYears(2), 'due_on' => today()->subDays(40)]);
        $olga = $this->record($workspace, $app, 'members', 'Overdue Olga', 'active', ['tier' => 'standard'], ['amount' => 500, 'occurs_on' => today()->subYear(), 'due_on' => today()->subDays(5)]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('lapsed', $larry->fresh()->status);
        $this->assertSame(today()->toDateString(), $larry->fresh()->value('_lapsed_on'));
        $this->assertSame('active', $olga->fresh()->status);
        $this->assertTrue((bool) $olga->fresh()->value('_overdue'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'members', $olga->id, 'renew']), ['amount' => 600, 'due_on' => today()->subDay()->toDateString()])->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'members', $olga->id, 'renew']), ['amount' => 600, 'due_on' => today()->addYear()->toDateString()])
            ->assertRedirect()->assertSessionHas('flash.message', 'Overdue Olga renewed until '.today()->addYear()->format('d M Y').'.');
        $this->assertFalse((bool) $olga->fresh()->value('_overdue'));
        $this->assertEquals(600, $olga->fresh()->amount);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'members', $larry->id, 'reinstate']), ['due_on' => today()->addYear()->toDateString()])
            ->assertRedirect()->assertSessionHas('flash.message', 'Lapsing Larry reinstated until '.today()->addYear()->format('d M Y').'.');
        $this->assertSame('active', $larry->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'members', $jane->id, 'change_tier']), ['tier' => 'honorary'])->assertRedirect()->assertSessionHas('flash.message', 'Jane Doe is now a honorary member.');
        $this->assertEquals(0, $jane->fresh()->amount);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'members', $john->id, 'cancel']))->assertRedirect()->assertSessionHas('flash.message', 'John Smith\'s membership is cancelled.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'members', $jane->id]))->assertOk()->assertSee('Membership')->assertSee('Renews on')->assertSee('Honorary');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Members')->assertSee('Renewals due');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Members by tier')->assertSee('Renewals by month')->assertSee('Joined by month')->assertSee('Gold');
    }

    public function test_ticketing_sells_within_capacity_codes_tickets_and_checks_them_in_once(): void
    {
        $app = 'ticketing';
        [$owner, $workspace] = $this->appWorkspace($app);
        $jazz = $this->record($workspace, $app, 'events', 'Jazz Night', 'draft', ['venue' => 'Alliance', 'capacity' => 2], ['occurs_on' => today()->addDays(10)]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tickets']), [
            'title' => 'Ann', 'status' => 'issued', 'amount' => 500, 'data' => ['event' => $jazz->id, 'tier' => 'general'],
        ])->assertSessionHasErrors('data.event');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'events', $jazz->id, 'publish']))->assertRedirect()->assertSessionHas('flash.message', 'Jazz Night is on sale: 2 tickets.');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tickets']), [
            'title' => 'Ann', 'status' => 'issued', 'amount' => 500, 'data' => ['event' => $jazz->id, 'tier' => 'general'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $ann = Record::where('entity', 'tickets')->where('title', 'Ann')->firstOrFail();
        $this->assertStringStartsWith('TK-', $ann->value('ticket_code'));
        $this->assertEquals(1, $jazz->fresh()->value('tickets_sold'));
        $this->assertEquals(1, $jazz->fresh()->value('_remaining'));
        $this->assertEquals(500, $jazz->fresh()->value('_revenue'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tickets']), [
            'title' => 'Bob', 'status' => 'issued', 'amount' => 100, 'data' => ['event' => $jazz->id, 'tier' => 'complimentary'],
        ])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tickets']), [
            'title' => 'Bob', 'status' => 'issued', 'amount' => 0, 'data' => ['event' => $jazz->id, 'tier' => 'complimentary', 'ticket_code' => 'vip001', 'email' => 'BOB@Example.com'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $bob = Record::where('entity', 'tickets')->where('title', 'Bob')->firstOrFail();
        $this->assertSame('VIP001', $bob->value('ticket_code'));
        $this->assertSame('bob@example.com', $bob->value('email'));
        $this->assertSame('sold_out', $jazz->fresh()->status);
        $this->assertEquals(0, $jazz->fresh()->value('_remaining'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tickets']), [
            'title' => 'Cid', 'status' => 'issued', 'amount' => 500, 'data' => ['event' => $jazz->id, 'tier' => 'general'],
        ])->assertSessionHasErrors('data.event');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'tickets', $ann->id]), [
            'title' => 'Ann', 'status' => 'issued', 'amount' => 500, 'data' => ['event' => $jazz->id, 'tier' => 'general', 'ticket_code' => 'vip001'],
        ])->assertSessionHasErrors('data.ticket_code');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'events', $jazz->id, 'check_in_code']), ['code' => 'nope'])->assertSessionHasErrors('code');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'events', $jazz->id, 'check_in_code']), ['code' => 'vip001'])->assertRedirect()->assertSessionHas('flash.message', 'Bob checked in (Complimentary).');
        $this->assertSame('checked_in', $bob->fresh()->status);
        $this->assertEquals(1, $jazz->fresh()->value('_checked_in'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'events', $jazz->id, 'check_in_code']), ['code' => 'VIP001'])->assertSessionHasErrors('code');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'tickets', $ann->id, 'refund']))->assertRedirect()->assertSessionHas('flash.message', 'Ticket '.$ann->value('ticket_code').' refunded.');
        $this->assertSame('on_sale', $jazz->fresh()->status);
        $this->assertEquals(1, $jazz->fresh()->value('_remaining'));
        $this->assertEquals(0, $jazz->fresh()->value('_revenue'));
        $this->assertEquals(500, $jazz->fresh()->value('_refunded'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'events', $jazz->id, 'complete']))->assertSessionHasErrors('status');
        $past = $this->record($workspace, $app, 'events', 'Past Gig', 'on_sale', ['venue' => 'Garden', 'capacity' => 10], ['occurs_on' => today()->subDay()]);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'events', $past->id, 'complete']))->assertRedirect()->assertSessionHas('flash.message', 'Past Gig completed: 0 of 0 ticket holders came.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'events', $jazz->id, 'cancel']))->assertRedirect()->assertSessionHas('flash.message', 'Jazz Night cancelled; 1 tickets to refund.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'events', $jazz->id]))->assertOk()->assertSee('Box office')->assertSee('Sales by tier')->assertSee('Complimentary');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Ticketing')->assertSee('Upcoming events');
        $this->actingAs($owner)->get(route('apps.reports', [$app, 'to' => today()->addMonth()->toDateString()]))->assertOk()->assertSee('Events')->assertSee('Sales by tier')->assertSee('Sales by month')->assertSee('Jazz Night');
    }

    public function test_event_registration_takes_one_registration_per_email_collects_fees_and_checks_in_on_the_day(): void
    {
        $app = 'event-registration';
        [$owner, $workspace] = $this->appWorkspace($app);
        $summit = $this->record($workspace, $app, 'events', 'Tech Summit', 'open', ['venue' => 'KICC', 'capacity' => 2, 'fee' => 1000], ['occurs_on' => today()->addDays(5), 'due_on' => today()->addDays(6)]);

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'events', $summit->id]), [
            'title' => 'Tech Summit', 'status' => 'open', 'occurs_on' => today()->addDays(5)->toDateString(), 'due_on' => today()->addDays(4)->toDateString(), 'data' => ['venue' => 'KICC', 'capacity' => 2, 'fee' => 1000],
        ])->assertSessionHasErrors('due_on');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Ann', 'status' => 'registered', 'data' => ['event' => $summit->id, 'email' => 'ANN@Example.com', 'organisation' => 'Safaricom'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $ann = Record::where('entity', 'registrations')->where('title', 'Ann')->firstOrFail();
        $this->assertSame('ann@example.com', $ann->value('email'));
        $this->assertEquals(1000, $ann->value('_balance'));
        $this->assertEquals(1, $summit->fresh()->value('_registered'));
        $this->assertEquals(1, $summit->fresh()->value('_badges_to_print'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Ann again', 'status' => 'registered', 'data' => ['event' => $summit->id, 'email' => 'ann@example.com'],
        ])->assertSessionHasErrors('data.email');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Bob', 'status' => 'paid', 'amount' => 500, 'data' => ['event' => $summit->id, 'email' => 'bob@example.com'],
        ])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Bob', 'status' => 'paid', 'amount' => 1000, 'data' => ['event' => $summit->id, 'email' => 'bob@example.com', 'organisation' => 'Safaricom'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(1, $summit->fresh()->value('_paid'));
        $this->assertEquals(0, $summit->fresh()->value('_remaining'));
        $this->assertEquals(1000, $summit->fresh()->value('_outstanding'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Cid', 'status' => 'registered', 'data' => ['event' => $summit->id, 'email' => 'cid@example.com'],
        ])->assertSessionHasErrors('data.event');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'registrations', $ann->id]), [
            'title' => 'Ann', 'status' => 'attended', 'amount' => 1000, 'data' => ['event' => $summit->id, 'email' => 'ann@example.com'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $ann->id, 'record_payment']), ['amount' => 400])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('registered', $ann->fresh()->status);
        $this->assertEquals(600, $ann->fresh()->value('_balance'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $ann->id, 'record_payment']), ['amount' => 600])->assertRedirect()->assertSessionHas('flash.message', 'Ann has paid in full.');
        $this->assertSame('paid', $ann->fresh()->status);
        $this->assertEquals(0, $summit->fresh()->value('_outstanding'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $ann->id, 'check_in']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $ann->id, 'print_badge']))->assertRedirect()->assertSessionHas('flash.message', 'Badge printed for Ann.');
        $this->assertEquals(1, $summit->fresh()->value('_badges_to_print'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'events', $summit->id, 'close']))->assertRedirect()->assertSessionHas('flash.message', 'Registration for Tech Summit is closed.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'events', $summit->id, 'reopen']))->assertRedirect()->assertSessionHas('flash.message', 'Registration for Tech Summit is open.');

        $past = $this->record($workspace, $app, 'events', 'Past Conf', 'open', ['venue' => 'Sarova', 'fee' => 0], ['occurs_on' => today()->subDay(), 'due_on' => today()->subDay()]);
        $eve = $this->record($workspace, $app, 'registrations', 'Eve', 'paid', ['event' => $past->id, 'email' => 'eve@example.com'], ['occurs_on' => today()->subDays(3)]);
        $dan = $this->record($workspace, $app, 'registrations', 'Dan', 'registered', ['event' => $past->id, 'email' => 'dan@example.com'], ['occurs_on' => today()->subDays(3)]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('closed', $past->fresh()->status);
        $this->assertSame('open', $summit->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $eve->id, 'check_in']))->assertRedirect()->assertSessionHas('flash.message', 'Eve checked in.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'events', $past->id, 'complete']))->assertRedirect()->assertSessionHas('flash.message', 'Past Conf completed; 1 marked as no-shows.');
        $this->assertSame('no_show', $dan->fresh()->status);
        $this->assertEquals(1, $past->fresh()->value('_attended'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $ann->id, 'cancel']))->assertRedirect()->assertSessionHas('flash.message', 'Ann\'s registration is cancelled.');
        $this->assertEquals(1, $summit->fresh()->value('_registered'));

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'events', $summit->id]))->assertOk()->assertSee('Registrations')->assertSee('Attendees')->assertSee('Badges to print')->assertSee('Bob');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Events')->assertSee('Upcoming events')->assertSee('Tech Summit');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Registrations by organisation')->assertSee('Registrations by month')->assertSee('Past Conf');
    }

    public function test_venue_booking_prices_by_the_hour_and_never_double_books_a_facility(): void
    {
        $app = 'venue-booking';
        [$owner, $workspace] = $this->appWorkspace($app);
        $hall = $this->record($workspace, $app, 'facilities', 'Main Hall', 'available', ['type' => 'hall', 'hourly_rate' => 500, 'capacity' => 200]);
        $court = $this->record($workspace, $app, 'facilities', 'Closed Court', 'closed', ['type' => 'court', 'hourly_rate' => 300]);
        $tomorrow = today()->addDay();

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Netball', 'status' => 'requested', 'occurs_on' => $tomorrow->toDateString(), 'data' => ['facility' => $court->id, 'start_time' => '09:00', 'end_time' => '10:00'],
        ])->assertSessionHasErrors('data.facility');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Backwards', 'status' => 'requested', 'occurs_on' => $tomorrow->toDateString(), 'data' => ['facility' => $hall->id, 'start_time' => '11:00', 'end_time' => '09:00'],
        ])->assertSessionHasErrors('data.end_time');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Church choir', 'status' => 'confirmed', 'occurs_on' => $tomorrow->toDateString(), 'data' => ['facility' => $hall->id, 'start_time' => '09:00', 'end_time' => '11:00', 'purpose' => 'Rehearsal'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $choir = Record::where('entity', 'bookings')->where('title', 'Church choir')->firstOrFail();
        $this->assertEquals(2, $choir->value('_hours'));
        $this->assertEquals(1000, $choir->amount);
        $this->assertSame($tomorrow->format('d M').' 09:00', $hall->fresh()->value('_next_booking'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Clash', 'status' => 'requested', 'occurs_on' => $tomorrow->toDateString(), 'data' => ['facility' => $hall->id, 'start_time' => '10:00', 'end_time' => '12:00'],
        ])->assertSessionHasErrors('data.start_time');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Debate club', 'status' => 'requested', 'occurs_on' => $tomorrow->toDateString(), 'data' => ['facility' => $hall->id, 'start_time' => '11:00', 'end_time' => '12:00'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $debate = Record::where('entity', 'bookings')->where('title', 'Debate club')->firstOrFail();
        $this->assertEquals(500, $debate->amount);
        $this->assertEquals(1, $hall->fresh()->value('_requested'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'facilities', $hall->id, 'book']), ['booked_by' => 'Yoga', 'occurs_on' => $tomorrow->toDateString(), 'start_time' => '10:30', 'end_time' => '11:30'])->assertSessionHasErrors('start_time');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'facilities', $hall->id, 'book']), ['booked_by' => 'Yoga', 'occurs_on' => $tomorrow->toDateString(), 'start_time' => '13:00', 'end_time' => '14:30', 'purpose' => 'Class'])
            ->assertRedirect()->assertSessionHas('flash.message', 'Main Hall booked for Yoga on '.$tomorrow->format('d M Y').' 13:00–14:30 (1.5 hours).');
        $yoga = Record::where('entity', 'bookings')->where('title', 'Yoga')->firstOrFail();
        $this->assertEquals(750, $yoga->amount);
        $this->assertSame('confirmed', $yoga->status);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bookings', $debate->id, 'confirm']))->assertRedirect()->assertSessionHas('flash.message', 'Booking confirmed for '.$tomorrow->format('d M Y').' 11:00–12:00.');
        $this->assertEquals(0, $hall->fresh()->value('_requested'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bookings', $debate->id, 'complete']))->assertSessionHasErrors('status');
        $old = $this->record($workspace, $app, 'bookings', 'Wedding', 'confirmed', ['facility' => $hall->id, 'start_time' => '08:00', 'end_time' => '18:00'], ['occurs_on' => today()->subDay()]);
        $this->assertEquals(5000, $old->amount);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bookings', $old->id, 'complete']))->assertRedirect()->assertSessionHas('flash.message', 'Booking completed.');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'facilities', $hall->id, 'close']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'facilities', $court->id, 'reopen']))->assertRedirect()->assertSessionHas('flash.message', 'Closed Court is open for bookings.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bookings', $yoga->id, 'cancel']))->assertRedirect()->assertSessionHas('flash.message', 'Booking cancelled.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'facilities', $hall->id]))->assertOk()->assertSee('Facility')->assertSee('Upcoming bookings')->assertSee('Debate club')->assertSee('Next booking');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Bookings')->assertSee('Today&#039;s bookings', false);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Facilities')->assertSee('Bookings by month')->assertSee('Bookings by type')->assertSee('Hall');
    }
}
