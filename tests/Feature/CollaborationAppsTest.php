<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Contacts\Models\Contact;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The collaboration apps' rules: time tracking, documents, team chat, video meetings, calendars, wiki, forms & approvals, notes, OKRs, issues, meeting minutes, client portal and freelancer. */
class CollaborationAppsTest extends TestCase
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

    public function test_time_tracking_values_entries_caps_the_day_and_bills_a_client_in_one_go(): void
    {
        $app = 'time-tracking';
        [$owner, $workspace] = $this->appWorkspace($app);
        $client = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Acme Traders']);
        $design = $this->record($workspace, $app, 'entries', 'Homepage design', 'unbilled', ['project' => 'Website', 'start_time' => '09:00', 'end_time' => '12:30', 'rate' => 40],
            ['contact_id' => $client->id, 'occurs_on' => today(), 'assignee_id' => $owner->id]);
        $this->assertEquals(3.5, $design->value('hours'));
        $this->assertEquals(140, $design->amount);
        $internal = $this->record($workspace, $app, 'entries', 'Team meeting', 'non_billable', ['project' => 'Admin', 'hours' => 2, 'rate' => 40], ['occurs_on' => today(), 'assignee_id' => $owner->id]);
        $this->assertEquals(0, $internal->amount);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [
            'title' => 'Backwards', 'status' => 'unbilled', 'occurs_on' => today()->toDateString(), 'data' => ['hours' => 1, 'start_time' => '14:00', 'end_time' => '13:00'],
        ])->assertSessionHasErrors('data.end_time');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [
            'title' => 'Marathon', 'status' => 'unbilled', 'occurs_on' => today()->toDateString(), 'assignee_id' => $owner->id, 'data' => ['hours' => 20],
        ])->assertSessionHasErrors('data.hours');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('This week')->assertSee('Unbilled by client')->assertSee('Acme Traders')->assertSee('140.00');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'entries', $design->id, 'bill_client']))->assertRedirect();
        $this->assertSame('billed', $design->fresh()->status);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'entries', $design->id]), [
            'title' => 'Homepage design', 'status' => 'billed', 'contact_id' => $client->id, 'occurs_on' => today()->toDateString(), 'data' => ['project' => 'Website', 'hours' => 5, 'rate' => 40],
        ])->assertSessionHasErrors('data.hours');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Hours by project')->assertSee('Hours by person')->assertSee('Website');
    }

    public function test_documents_keep_folders_acyclic_and_retire_older_approved_versions(): void
    {
        $app = 'documents';
        [$owner, $workspace] = $this->appWorkspace($app);
        $policies = $this->record($workspace, $app, 'folders', 'Policies', 'active');
        $hr = $this->record($workspace, $app, 'folders', 'HR', 'active', ['parent' => $policies->id]);
        $old = $this->record($workspace, $app, 'folders', 'Old', 'archived');

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'folders', $policies->id]), ['title' => 'Policies', 'status' => 'active', 'data' => ['parent' => $hr->id]])
            ->assertSessionHasErrors('data.parent');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'documents']), [
            'title' => 'Stray', 'status' => 'draft', 'data' => ['folder' => $old->id, 'file_url' => 'https://files.test/stray.pdf'],
        ])->assertSessionHasErrors('data.folder');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'documents']), [
            'title' => 'Unversioned', 'status' => 'approved', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(),
            'data' => ['folder' => $policies->id, 'file_url' => 'https://files.test/x.pdf'],
        ])->assertSessionHasErrors(['data.version', 'due_on']);

        $first = $this->record($workspace, $app, 'documents', 'Leave policy', 'approved', ['folder' => $policies->id, 'file_url' => 'https://files.test/leave-1.pdf', 'version' => '1.0']);
        $this->record($workspace, $app, 'documents', 'Leave policy', 'approved', ['folder' => $policies->id, 'file_url' => 'https://files.test/leave-2.pdf', 'version' => '2.0']);
        $this->assertSame('obsolete', $first->fresh()->status);
        $this->assertEquals(2, $policies->fresh()->value('_documents'));

        $this->record($workspace, $app, 'documents', 'Travel policy', 'in_review', ['folder' => $policies->id, 'file_url' => 'https://files.test/travel.pdf', 'version' => '0.9'],
            ['occurs_on' => today(), 'due_on' => today()->addDays(5)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Due for review')->assertSee('Waiting for approval')->assertSee('Travel policy');
        $this->actingAs($owner)->get($policies->url())->assertOk()->assertSee('In this folder')->assertSee('Leave policy');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Documents by folder')->assertSee('Review schedule');
    }

    public function test_team_chat_guards_channels_and_limits_pinned_messages(): void
    {
        $app = 'team-chat';
        [$owner, $workspace] = $this->appWorkspace($app);
        $general = $this->record($workspace, $app, 'channels', 'general', 'active', ['purpose' => 'Everything']);
        $archived = $this->record($workspace, $app, 'channels', 'old-news', 'archived');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'channels']), ['title' => 'secret', 'status' => 'active', 'data' => ['private' => '1']])
            ->assertSessionHasErrors('data.members');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'channels']), ['title' => 'general', 'status' => 'active'])->assertSessionHasErrors('title');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'messages']), [
            'title' => 'Hello?', 'status' => 'posted', 'data' => ['channel' => $archived->id, 'body' => 'Anyone here?'],
        ])->assertSessionHasErrors('data.channel');

        foreach (range(1, 10) as $number) {
            $this->record($workspace, $app, 'messages', 'Notice '.$number, 'pinned', ['channel' => $general->id, 'body' => 'Read notice '.$number], ['assignee_id' => $owner->id]);
        }
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'messages']), [
            'title' => 'One more', 'status' => 'pinned', 'data' => ['channel' => $general->id, 'body' => 'Pin me too'],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'messages']), [
            'title' => 'Plain', 'status' => 'posted', 'data' => ['channel' => $general->id, 'body' => 'Just chatting'],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(11, $general->fresh()->value('_messages'));
        $this->assertSame(today()->toDateString(), $general->fresh()->value('_last_message_on'));

        $this->actingAs($owner)->get($general->url())->assertOk()->assertSee('Pinned')->assertSee('Latest messages')->assertSee('Read notice 3');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Busiest channels')->assertSee('#general');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Messages by channel')->assertSee('Most active people')->assertSee($owner->name);
    }

    public function test_video_meetings_stop_double_booking_the_host_and_close_old_meetings(): void
    {
        $app = 'video-meetings';
        [$owner, $workspace] = $this->appWorkspace($app);
        $review = $this->record($workspace, $app, 'meetings', 'Quarterly review', 'scheduled',
            ['start_time' => '10:00', 'duration' => 60, 'link' => 'https://meet.test/review', 'platform' => 'zoom', 'attendees' => "Ana\nBen\nChipo"],
            ['occurs_on' => today(), 'assignee_id' => $owner->id]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'meetings']), [
            'title' => 'Clash', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'assignee_id' => $owner->id,
            'data' => ['start_time' => '10:30', 'link' => 'https://meet.test/clash'],
        ])->assertSessionHasErrors('data.start_time');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'meetings']), [
            'title' => 'Blink', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['start_time' => '15:00', 'duration' => 2, 'link' => 'https://meet.test/blink'],
        ])->assertSessionHasErrors('data.duration');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'meetings']), [
            'title' => 'Afterwards', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'assignee_id' => $owner->id,
            'data' => ['start_time' => '11:00', 'link' => 'https://meet.test/after'],
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Today\'s meetings')->assertSee('Quarterly review');
        $this->actingAs($owner)->get($review->url())->assertOk()->assertSee('10:00 – 11:00');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'meetings', $review->id, 'start']))->assertRedirect();
        $this->assertSame('live', $review->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'meetings', $review->id, 'end']), ['recording_url' => 'https://meet.test/rec/1'])->assertRedirect();
        $this->assertSame('ended', $review->fresh()->status);
        $this->assertSame('https://meet.test/rec/1', $review->fresh()->value('recording_url'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'meetings', $review->id]), [
            'title' => 'Quarterly review', 'status' => 'live', 'occurs_on' => today()->toDateString(), 'data' => ['start_time' => '10:00', 'link' => 'https://meet.test/review'],
        ])->assertSessionHasErrors('status');

        $forgotten = $this->record($workspace, $app, 'meetings', 'Forgotten call', 'live', ['start_time' => '09:00', 'link' => 'https://meet.test/old'], ['occurs_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('ended', $forgotten->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Meetings by platform')->assertSee('Zoom')->assertSee('Time in meetings by host');
    }

    public function test_calendar_refuses_double_bookings_closed_rooms_and_overfull_rooms(): void
    {
        $app = 'calendar';
        [$owner, $workspace] = $this->appWorkspace($app);
        $boardroom = $this->record($workspace, $app, 'resources', 'Board room', 'available', ['type' => 'meeting_room', 'capacity' => 3]);
        $van = $this->record($workspace, $app, 'resources', 'Delivery van', 'out_of_service', ['type' => 'vehicle']);
        $this->record($workspace, $app, 'events', 'Board meeting', 'confirmed', ['start_time' => '09:00', 'end_time' => '10:00', 'resource' => $boardroom->id], ['occurs_on' => today()]);

        $event = fn (array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'events']), [
            'title' => 'Booking', 'status' => 'confirmed', 'occurs_on' => today()->toDateString(), 'data' => $data,
        ]);
        $event(['start_time' => '09:30', 'end_time' => '10:30', 'resource' => $boardroom->id])->assertSessionHasErrors('data.resource');
        $event(['start_time' => '11:00', 'end_time' => '10:00'])->assertSessionHasErrors('data.end_time');
        $event(['start_time' => '11:00', 'end_time' => '12:00', 'resource' => $van->id])->assertSessionHasErrors('data.resource');
        $event(['start_time' => '11:00', 'end_time' => '12:00', 'resource' => $boardroom->id, 'attendees' => 'Ana, Ben, Chipo, Dalitso'])->assertSessionHasErrors('data.attendees');
        $event(['start_time' => '08:00', 'all_day' => '1', 'resource' => $boardroom->id])->assertSessionHasErrors('data.resource');
        $event(['start_time' => '10:00', 'end_time' => '11:00', 'resource' => $boardroom->id, 'attendees' => 'Ana, Ben'])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get($boardroom->url())->assertOk()->assertSee('Upcoming bookings')->assertSee('09:00–10:00');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Free right now')->assertSee('Board meeting');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Room and resource use')->assertSee('Board room')->assertSee('Events by month');
    }

    public function test_wiki_keeps_titles_unique_hides_nothing_public_and_flags_stale_articles(): void
    {
        $app = 'wiki';
        [$owner, $workspace] = $this->appWorkspace($app);
        $hr = $this->record($workspace, $app, 'categories', 'HR', 'active');
        $internal = $this->record($workspace, $app, 'categories', 'Internal only', 'hidden');
        $body = str_repeat('Staff request leave through the portal at least two weeks ahead. ', 5);

        $leave = $this->record($workspace, $app, 'articles', 'Requesting leave', 'published', ['category' => $hr->id, 'visibility' => 'public', 'body' => $body], ['assignee_id' => $owner->id]);
        $this->assertEquals(55, $leave->value('_words'));
        $this->assertEquals(1, $leave->value('_read_minutes'));
        $this->assertSame(today()->toDateString(), $leave->value('_published_on'));
        $this->assertEquals(1, $hr->fresh()->value('_articles'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'categories']), ['title' => 'HR', 'status' => 'active'])->assertSessionHasErrors('title');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'categories', $hr->id]), ['title' => 'HR', 'status' => 'hidden'])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'articles']), [
            'title' => 'Server passwords', 'status' => 'draft', 'data' => ['category' => $internal->id, 'visibility' => 'public', 'body' => 'Ask IT.'],
        ])->assertSessionHasErrors('data.visibility');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'articles']), [
            'title' => 'Requesting leave', 'status' => 'draft', 'data' => ['category' => $hr->id, 'visibility' => 'internal', 'body' => 'Duplicate'],
        ])->assertSessionHasErrors('title');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'articles']), [
            'title' => 'Payslips', 'status' => 'published', 'data' => ['category' => $hr->id, 'visibility' => 'internal', 'body' => 'See the portal.'],
        ])->assertSessionHasErrors('data.body');

        $stale = $this->record($workspace, $app, 'articles', 'Old dress code', 'published', ['category' => $hr->id, 'visibility' => 'internal', 'body' => $body]);
        Record::query()->whereKey($stale->id)->update(['occurs_on' => today()->subDays(200)]);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Knowledge base')->assertSee('Recently updated')->assertSee('Requesting leave');
        $this->actingAs($owner)->get($stale->url())->assertOk()->assertSee('Might be out of date');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Articles by category')->assertSee('Stale articles')->assertSee('Old dress code');
    }

    public function test_forms_route_submissions_to_the_named_approver(): void
    {
        $app = 'forms-approvals-workflow';
        [$owner, $workspace] = $this->appWorkspace($app);
        $approver = $this->memberOf($workspace);
        $bystander = $this->memberOf($workspace);
        $leaveForm = $this->record($workspace, $app, 'forms', 'Leave request', 'active', ['questions' => "Which dates?\nWhy?"]);
        $retired = $this->record($workspace, $app, 'forms', 'Old purchase form', 'retired', ['questions' => 'What?']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'forms']), ['title' => 'Empty', 'status' => 'active', 'data' => ['questions' => ' ']])
            ->assertSessionHasErrors('data.questions');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'submissions']), [
            'title' => 'Laptop', 'status' => 'submitted', 'data' => ['form' => $retired->id, 'answers' => 'A laptop'],
        ])->assertSessionHasErrors('data.form');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'submissions']), [
            'title' => 'Half done', 'status' => 'submitted', 'data' => ['form' => $leaveForm->id, 'answers' => '1-5 May'],
        ])->assertSessionHasErrors('data.answers');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'submissions']), [
            'title' => 'No reason', 'status' => 'rejected', 'data' => ['form' => $leaveForm->id, 'answers' => "1-5 May\nFamily"],
        ])->assertSessionHasErrors('data.comments');

        $holiday = $this->record($workspace, $app, 'submissions', 'Ana — May leave', 'submitted', ['form' => $leaveForm->id, 'answers' => "1-5 May\nFamily", 'approver' => $approver->id],
            ['occurs_on' => today()->subDays(2)]);
        $this->assertSame('pending_approval', $holiday->status);
        $trip = $this->record($workspace, $app, 'submissions', 'Ben — June leave', 'submitted', ['form' => $leaveForm->id, 'answers' => "2-9 June\nTrip", 'approver' => $approver->id]);
        $this->assertEquals(2, $leaveForm->fresh()->value('_submissions'));

        $this->actingAs($approver)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting for your approval')->assertSee('Ana — May leave');
        $refused = $this->actingAs($bystander)->post(route('apps.records.action', [$app, 'submissions', $holiday->id, 'approve']));
        $this->assertContains($refused->status(), [403, 404]);
        $this->assertSame('pending_approval', $holiday->fresh()->status);

        $this->actingAs($approver)->post(route('apps.records.action', [$app, 'submissions', $holiday->id, 'approve']))->assertRedirect();
        $this->assertSame('approved', $holiday->fresh()->status);
        $this->assertEquals(2, $holiday->fresh()->value('_turnaround_days'));
        $this->actingAs($approver)->post(route('apps.records.action', [$app, 'submissions', $trip->id, 'reject']), [])->assertSessionHasErrors('comments');
        $this->actingAs($approver)->post(route('apps.records.action', [$app, 'submissions', $trip->id, 'reject']), ['comments' => 'Peak season'])->assertRedirect();
        $this->assertSame('rejected', $trip->fresh()->status);
        $this->assertSame('Peak season', $trip->fresh()->value('comments'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'submissions', $holiday->id]), [
            'title' => 'Ana — May leave', 'status' => 'pending_approval', 'data' => ['form' => $leaveForm->id, 'answers' => "1-5 May\nFamily", 'approver' => $approver->id],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Submissions by form')->assertSee('Leave request')->assertSee('Average turnaround');
    }

    public function test_notes_count_checklist_progress_and_lock_archived_notes(): void
    {
        $app = 'notes-whiteboards';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'notes']), ['title' => 'Sprint board', 'status' => 'active', 'data' => ['type' => 'whiteboard', 'body' => 'Planning']])
            ->assertSessionHasErrors('data.image_url');

        $launch = $this->record($workspace, $app, 'notes', 'Launch checklist', 'active', ['type' => 'checklist', 'body' => "[x] Book venue\n[ ] Send invites\n- [X] Order food", 'shared' => true]);
        $this->assertEquals(3, $launch->value('_items'));
        $this->assertEquals(2, $launch->value('_done'));

        $old = $this->record($workspace, $app, 'notes', 'Old idea', 'archived', ['type' => 'note', 'body' => 'Maybe later']);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'notes', $old->id]), ['title' => 'Old idea', 'status' => 'archived', 'data' => ['type' => 'note', 'body' => 'Changed']])
            ->assertSessionHasErrors('status');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'notes', $old->id]), ['title' => 'Old idea', 'status' => 'active', 'data' => ['type' => 'note', 'body' => 'Changed']])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)->get($launch->url())->assertOk()->assertSee('Checklist')->assertSee('2 of 3');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Open checklist items')->assertSee('Shared with the team')->assertSee('Launch checklist');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Notes by type')->assertSee('Checklist progress')->assertSee('67%');
    }

    public function test_okrs_roll_key_results_up_and_flag_objectives_falling_behind(): void
    {
        $app = 'goals-okr-tracking';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'objectives']), ['title' => 'Faster support', 'status' => 'on_track', 'data' => ['level' => 'team']])
            ->assertSessionHasErrors('data.team');

        $revenue = $this->record($workspace, $app, 'objectives', 'Grow revenue', 'on_track', ['level' => 'company', 'period' => 'Q4'], ['due_on' => today()->addDays(10)]);
        $sales = $this->record($workspace, $app, 'key_results', 'Monthly sales', 'on_track', ['objective' => $revenue->id, 'start_value' => 0, 'target_value' => 100, 'current_value' => 50]);
        $churn = $this->record($workspace, $app, 'key_results', 'Churn rate', 'on_track', ['objective' => $revenue->id, 'start_value' => 10, 'target_value' => 5, 'current_value' => 5]);
        $this->assertEquals(50, $sales->value('_progress'));
        $this->assertSame('achieved', $churn->status);
        $this->assertEquals(75, $revenue->fresh()->value('_progress'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'objectives', $revenue->id]), [
            'title' => 'Grow revenue', 'status' => 'achieved', 'due_on' => today()->addDays(10)->toDateString(), 'data' => ['level' => 'company', 'period' => 'Q4'],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'key_results']), [
            'title' => 'Flat', 'status' => 'on_track', 'data' => ['objective' => $revenue->id, 'start_value' => 5, 'target_value' => 5],
        ])->assertSessionHasErrors('data.target_value');

        $hiring = $this->record($workspace, $app, 'objectives', 'Hire a team', 'on_track', ['level' => 'company'], ['due_on' => today()->addDays(3)]);
        $this->record($workspace, $app, 'key_results', 'Engineers hired', 'on_track', ['objective' => $hiring->id, 'start_value' => 0, 'target_value' => 5, 'current_value' => 1]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('at_risk', $hiring->fresh()->status);
        $this->assertSame('on_track', $revenue->fresh()->status);

        $sales->update(['data' => [...(array) $sales->data, 'current_value' => 120]]);
        $this->assertSame('achieved', $sales->fresh()->status);
        $this->assertEquals(100, $revenue->fresh()->value('_progress'));

        $this->actingAs($owner)->get($revenue->url())->assertOk()->assertSee('Key results')->assertSee('Churn rate');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Objectives needing attention')->assertSee('Hire a team');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Progress by objective')->assertSee('Progress by team');
    }

    public function test_issues_block_shipping_open_work_and_write_release_notes(): void
    {
        $app = 'issues';
        [$owner, $workspace] = $this->appWorkspace($app);
        $release = $this->record($workspace, $app, 'releases', 'v1.0', 'planned', [], ['due_on' => today()->addDays(14)]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'issues']), ['title' => 'Crash on login', 'status' => 'open', 'data' => ['type' => 'bug', 'priority' => 'critical']])
            ->assertSessionHasErrors('data.steps');

        $crash = $this->record($workspace, $app, 'issues', 'Checkout crashes', 'open', ['type' => 'bug', 'priority' => 'high', 'steps' => 'Pay with mobile money', 'release' => $release->id]);
        $this->record($workspace, $app, 'issues', 'Dark mode', 'done', ['type' => 'feature', 'priority' => 'low', 'release' => $release->id]);
        $this->assertEquals(2, $release->fresh()->value('_issues'));
        $this->assertEquals(50, $release->fresh()->value('_progress'));

        $ship = fn () => $this->actingAs($owner)->put(route('apps.records.update', [$app, 'releases', $release->id]), ['title' => 'v1.0', 'status' => 'released', 'data' => ['goals' => 'Stable checkout']]);
        $ship()->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'issues', $crash->id, 'triage']), ['priority' => 'critical'])->assertRedirect();
        $this->assertSame('triaged', $crash->fresh()->status);
        $this->assertSame('critical', $crash->fresh()->value('priority'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Critical and high priority')->assertSee('Checkout crashes');

        $crash->fresh()->update(['status' => 'done']);
        $ship()->assertSessionHasNoErrors();
        $this->assertSame('released', $release->fresh()->status);
        $this->assertStringContainsString('Bug: Checkout crashes', (string) $release->fresh()->value('release_notes'));
        $this->assertStringContainsString('Feature: Dark mode', (string) $release->fresh()->value('release_notes'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'issues']), [
            'title' => 'Late addition', 'status' => 'open', 'data' => ['type' => 'task', 'priority' => 'low', 'release' => $release->id],
        ])->assertSessionHasErrors('data.release');

        $this->actingAs($owner)->get($release->url())->assertOk()->assertSee('Release scope')->assertSee('Issues in this release');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Open issues by priority')->assertSee('Release progress')->assertSee('Time to close');
    }

    public function test_meeting_minutes_lock_once_approved_and_track_action_items(): void
    {
        $app = 'meeting-minutes-action-items';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'meetings']), ['title' => 'No one came', 'status' => 'held', 'occurs_on' => today()->toDateString()])
            ->assertSessionHasErrors('data.attendees');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'meetings']), [
            'title' => 'Next month', 'status' => 'held', 'occurs_on' => today()->addMonth()->toDateString(), 'data' => ['attendees' => 'Ana'],
        ])->assertSessionHasErrors('status');

        $board = $this->record($workspace, $app, 'meetings', 'Board meeting', 'held', ['chair' => $owner->id, 'attendees' => "Ana\nBen", 'minutes' => 'Budget agreed.'],
            ['occurs_on' => today()->subDays(5)]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'actions']), [
            'title' => 'Too early', 'status' => 'open', 'due_on' => today()->subDays(6)->toDateString(), 'data' => ['meeting' => $board->id, 'owner' => $owner->id],
        ])->assertSessionHasErrors('due_on');

        $report = $this->record($workspace, $app, 'actions', 'Send budget report', 'open', ['meeting' => $board->id, 'owner' => $owner->id], ['due_on' => today()->subDays(2)]);
        $this->record($workspace, $app, 'actions', 'Book auditor', 'open', ['meeting' => $board->id, 'owner' => $owner->id], ['due_on' => today()->addDays(7)]);
        $this->assertEquals(2, $board->fresh()->value('_open_actions'));

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('My open actions')->assertSee('Send budget report');
        $this->actingAs($owner)->get($board->url())->assertOk()->assertSee('Action items')->assertSee('Book auditor');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'meetings', $board->id, 'approve_minutes']))->assertRedirect();
        $this->assertSame('minutes_approved', $board->fresh()->status);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'meetings', $board->id]), [
            'title' => 'Board meeting', 'status' => 'minutes_approved', 'occurs_on' => today()->subDays(5)->toDateString(), 'data' => ['attendees' => "Ana\nBen", 'minutes' => 'Budget rejected.'],
        ])->assertSessionHasErrors('data.minutes');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'actions', $report->id, 'complete']))->assertRedirect();
        $this->assertSame('done', $report->fresh()->status);
        $this->assertEquals(1, $board->fresh()->value('_open_actions'));

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Action items by owner')->assertSee($owner->name);
    }

    public function test_client_portal_collects_sign_off_before_a_project_completes(): void
    {
        $app = 'client-portal-for-agencies';
        [$owner, $workspace] = $this->appWorkspace($app);
        $client = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Lakeside Lodge']);
        $rebrand = $this->record($workspace, $app, 'projects', 'Rebrand', 'active', ['summary' => 'New identity'],
            ['contact_id' => $client->id, 'amount' => 5000, 'occurs_on' => today(), 'due_on' => today()->addDays(30)]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'projects']), [
            'title' => 'Backwards', 'status' => 'active', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(),
        ])->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliverables']), [
            'title' => 'Brochure', 'status' => 'awaiting_approval', 'data' => ['project' => $rebrand->id],
        ])->assertSessionHasErrors('data.file_url');

        $logo = $this->record($workspace, $app, 'deliverables', 'Logo', 'awaiting_approval', ['project' => $rebrand->id, 'file_url' => 'https://files.test/logo.png'], ['due_on' => today()->addDays(2)]);
        $palette = $this->record($workspace, $app, 'deliverables', 'Colour palette', 'in_progress', ['project' => $rebrand->id], ['due_on' => today()->addDays(4)]);
        $this->record($workspace, $app, 'updates', 'Kick-off done', 'published', ['project' => $rebrand->id, 'body' => 'We met the team and agreed the brief.']);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting for client approval')->assertSee('Logo')->assertSee('Due in the next week')->assertSee('Colour palette');

        $complete = fn () => $this->actingAs($owner)->put(route('apps.records.update', [$app, 'projects', $rebrand->id]), [
            'title' => 'Rebrand', 'status' => 'completed', 'contact_id' => $client->id, 'amount' => 5000, 'occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(30)->toDateString(),
        ]);
        $complete()->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'deliverables', $logo->id, 'request_changes']), [])->assertSessionHasErrors('client_feedback');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'deliverables', $logo->id, 'request_changes']), ['client_feedback' => 'Make it bolder'])->assertRedirect();
        $this->assertSame('changes_requested', $logo->fresh()->status);
        $this->assertSame('Make it bolder', $logo->fresh()->value('client_feedback'));

        $logo->fresh()->update(['status' => 'awaiting_approval']);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'deliverables', $logo->id, 'approve']))->assertRedirect();
        $palette->fresh()->update(['status' => 'approved']);
        $this->assertEquals(100, $rebrand->fresh()->value('_progress'));
        $complete()->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'updates']), [
            'title' => 'One more thing', 'status' => 'published', 'data' => ['project' => $rebrand->id, 'body' => 'Late news'],
        ])->assertSessionHasErrors('data.project');

        $this->actingAs($owner)->get($rebrand->url())->assertOk()->assertSee('Sign-off')->assertSee('Latest updates')->assertSee('Kick-off done');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Project sign-off')->assertSee('Lakeside Lodge');
    }

    public function test_freelancer_values_gigs_from_time_and_marks_them_paid(): void
    {
        $app = 'freelancer';
        [$owner, $workspace] = $this->appWorkspace($app);
        $client = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Zambezi Tours']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'gigs']), ['title' => 'Unsigned', 'status' => 'contracted', 'data' => ['rate_type' => 'fixed']])
            ->assertSessionHasErrors('data.contract_signed');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'gigs']), ['title' => 'Pitch', 'status' => 'proposal_sent', 'data' => ['rate_type' => 'fixed']])
            ->assertSessionHasErrors('data.proposal');

        $api = $this->record($workspace, $app, 'gigs', 'Booking API', 'in_progress', ['rate_type' => 'hourly', 'rate' => 50, 'contract_signed' => true],
            ['contact_id' => $client->id, 'due_on' => today()->addDays(2)]);
        $this->record($workspace, $app, 'time', 'Endpoints', 'unbilled', ['gig' => $api->id, 'hours' => 3]);
        $this->record($workspace, $app, 'time', 'Tests', 'unbilled', ['gig' => $api->id, 'hours' => 5]);
        $this->assertEquals(8, $api->fresh()->value('_hours'));
        $this->assertEquals(400, $api->fresh()->amount);

        $audit = $this->record($workspace, $app, 'gigs', 'Security audit', 'in_progress', ['rate_type' => 'daily', 'rate' => 200, 'contract_signed' => true], ['contact_id' => $client->id]);
        $this->record($workspace, $app, 'time', 'Scan', 'unbilled', ['gig' => $audit->id, 'hours' => 4]);
        $this->assertEquals(100, $audit->fresh()->amount);

        $lead = $this->record($workspace, $app, 'gigs', 'Maybe a website', 'lead', ['rate_type' => 'fixed'], ['amount' => 900]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'time']), ['title' => 'Early', 'status' => 'unbilled', 'data' => ['gig' => $lead->id, 'hours' => 2]])
            ->assertSessionHasErrors('data.gig');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'time']), ['title' => 'Marathon', 'status' => 'unbilled', 'data' => ['gig' => $api->id, 'hours' => 30]])
            ->assertSessionHasErrors('data.hours');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Deadlines')->assertSee('Booking API')->assertSee('900.00');

        $api->fresh()->update(['status' => 'delivered']);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'gigs', $api->id, 'mark_paid']), ['invoice_number' => 'INV-7'])->assertRedirect();
        $this->assertSame('paid', $api->fresh()->status);
        $this->assertSame('INV-7', $api->fresh()->value('invoice_number'));
        $this->assertEquals(0, $api->fresh()->value('_unbilled_hours'));

        $audit->fresh()->update(['status' => 'delivered']);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'gigs', $audit->id, 'mark_paid']), ['invoice_number' => 'INV-7'])->assertSessionHasErrors('invoice_number');
        $this->assertSame('delivered', $audit->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Income by client')->assertSee('Zambezi Tours')->assertSee('400.00')->assertSee('Hours by gig');
    }
}
