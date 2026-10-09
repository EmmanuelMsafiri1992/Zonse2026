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

/** The education apps' rules, batch two: tutoring, parent portal, certificates & verification, university, hostel and school transport. */
class EducationAppsBatchTwoTest extends TestCase
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

    public function test_tutoring_books_lessons_for_active_students_without_double_booking_the_tutor(): void
    {
        $app = 'tutoring';
        [$owner, $workspace] = $this->appWorkspace($app);
        $amani = $this->record($workspace, $app, 'students', 'Amani Juma', 'active', ['subjects' => 'Mathematics', 'level' => 'Grade 8']);
        $paused = $this->record($workspace, $app, 'students', 'Pat Paused', 'paused', ['subjects' => 'Physics']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Vectors', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'assignee_id' => $owner->id, 'data' => ['student' => $paused->id, 'start_time' => '09:00', 'duration' => 60],
        ])->assertSessionHasErrors('data.student');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Fractions', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'assignee_id' => $owner->id, 'amount' => 500, 'data' => ['student' => $amani->id, 'start_time' => '10:00', 'duration' => 5],
        ])->assertSessionHasErrors('data.duration');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Fractions', 'status' => 'done', 'occurs_on' => today()->addDay()->toDateString(), 'assignee_id' => $owner->id, 'amount' => 500, 'data' => ['student' => $amani->id, 'start_time' => '10:00', 'duration' => 60],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Fractions', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'assignee_id' => $owner->id, 'amount' => 500, 'data' => ['student' => $amani->id, 'start_time' => '10:00', 'duration' => 60, 'mode' => 'in_person'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Decimals', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'assignee_id' => $owner->id, 'amount' => 500, 'data' => ['student' => $amani->id, 'start_time' => '10:30', 'duration' => 60],
        ])->assertSessionHasErrors('data.start_time');

        $fractions = Record::query()->ofEntity($app, 'lessons')->latest('id')->firstOrFail();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'lessons', $fractions->id, 'done']), ['notes' => 'Good progress'])->assertSessionHas('flash.message', 'Lesson done.');
        $this->assertSame('done', $fractions->fresh()->status);
        $this->assertSame('Good progress', $fractions->fresh()->value('notes'));
        $this->assertSame(1, $amani->fresh()->value('_lessons'));
        $this->assertEquals(1.0, $amani->fresh()->value('_hours'));
        $this->assertEquals(500, $amani->fresh()->value('_fees'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Decimals', 'status' => 'booked', 'occurs_on' => today()->addDay()->toDateString(), 'assignee_id' => $owner->id, 'data' => ['student' => $amani->id, 'start_time' => '10:00', 'duration' => 90],
        ])->assertSessionHasNoErrors();
        $decimals = Record::query()->ofEntity($app, 'lessons')->latest('id')->firstOrFail();
        $this->assertEquals(500, $decimals->amount);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'students', $amani->id, 'pause']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'lessons', $decimals->id, 'no_show']))->assertSessionHas('flash.message', 'Lesson marked as a no-show.');
        $this->assertSame(1, $amani->fresh()->value('_no_shows'));
        $this->assertEquals(1000, $amani->fresh()->value('_fees'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'students', $amani->id, 'pause']))->assertSessionHas('flash.message', 'Amani Juma is paused.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'students', $amani->id, 'resume']))->assertSessionHas('flash.message', 'Amani Juma is active again.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'students', $amani->id]))->assertOk()->assertSee('Tutoring')->assertSee('Lessons')->assertSee('Fractions');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Active students')->assertSee('Upcoming lessons');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Students')->assertSee('Mathematics')->assertSee('Lessons by tutor')->assertSee($owner->name)->assertSee('Fees by month');
    }

    public function test_parent_portal_activates_reachable_accounts_and_tracks_replies(): void
    {
        $app = 'parent-portal';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'accounts']), [
            'title' => 'Mrs Banda', 'status' => 'active', 'data' => ['role' => 'parent', 'children' => 'Chipo Banda'],
        ])->assertSessionHasErrors('data.email');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'accounts']), [
            'title' => 'Mrs Banda', 'status' => 'active', 'data' => ['role' => 'parent', 'children' => 'Chipo Banda', 'email' => 'banda@example.com'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'accounts']), [
            'title' => 'Mr Banda', 'status' => 'invited', 'data' => ['role' => 'parent', 'email' => 'BANDA@example.com'],
        ])->assertSessionHasErrors('data.email');
        $banda = Record::query()->ofEntity($app, 'accounts')->latest('id')->firstOrFail();

        $kofi = $this->record($workspace, $app, 'accounts', 'Kofi Mensah', 'invited', ['role' => 'student']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'messages']), [
            'title' => 'Fee balance', 'status' => 'new', 'data' => ['account' => $kofi->id, 'type' => 'fee_query', 'message' => 'How much do I owe?'],
        ])->assertSessionHasErrors('data.account');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'accounts', $kofi->id, 'activate']))->assertSessionHasErrors('data.email');
        $kofi->update(['data' => [...(array) $kofi->data, 'phone' => '+233200000000']]);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'accounts', $kofi->id, 'activate']))->assertSessionHas('flash.message', 'Kofi Mensah\'s portal account is active.');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'messages']), [
            'title' => 'Absent on Monday', 'status' => 'replied', 'occurs_on' => today()->subDays(2)->toDateString(), 'data' => ['account' => $banda->id, 'type' => 'absence_note', 'message' => 'Chipo was unwell.'],
        ])->assertSessionHasErrors('data.reply');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'messages']), [
            'title' => 'Absent on Monday', 'status' => 'new', 'occurs_on' => today()->subDays(2)->toDateString(), 'data' => ['account' => $banda->id, 'type' => 'absence_note', 'message' => 'Chipo was unwell.'],
        ])->assertSessionHasNoErrors();
        $message = Record::query()->ofEntity($app, 'messages')->latest('id')->firstOrFail();
        $this->assertSame(1, $banda->fresh()->value('_messages'));
        $this->assertSame(1, $banda->fresh()->value('_open_messages'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'accounts', $banda->id, 'disable']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'messages', $message->id, 'reply']), ['reply' => ''])->assertSessionHasErrors('reply');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'messages', $message->id, 'reply']), ['reply' => 'Noted, thank you.'])->assertSessionHas('flash.message', 'Reply sent.');
        $this->assertSame('replied', $message->fresh()->status);
        $this->assertSame(2, $message->fresh()->value('_response_days'));
        $this->assertSame(today()->toDateString(), $message->fresh()->value('_replied_on'));
        $this->assertSame(0, $banda->fresh()->value('_open_messages'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'messages', $message->id, 'close']))->assertSessionHas('flash.message', 'Message closed.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'accounts', $banda->id, 'disable']))->assertSessionHas('flash.message', 'Account disabled.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'accounts', $banda->id, 'reinvite']))->assertSessionHas('flash.message', 'Invitation sent again to Mrs Banda.');
        $this->assertSame('invited', $banda->fresh()->status);

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'accounts', $banda->id]))->assertOk()->assertSee('Portal account')->assertSee('Messages')->assertSee('Absent on Monday');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Average reply time')->assertSee('2 days')->assertSee('Unanswered messages');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Messages by type')->assertSee('Absence note')->assertSee('Reply time by month')->assertSee('Accounts by role');
    }

    public function test_certificates_carry_unique_codes_and_checks_record_their_outcome(): void
    {
        $app = 'certificates-verification';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'certificates']), [
            'title' => 'Grace Achieng', 'status' => 'issued', 'occurs_on' => today()->subYear()->toDateString(), 'due_on' => today()->subYears(2)->toDateString(), 'data' => ['programme' => 'Diploma in Nursing', 'verification_code' => 'zc-abc123'],
        ])->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'certificates']), [
            'title' => 'Grace Achieng', 'status' => 'issued', 'occurs_on' => today()->subYear()->toDateString(), 'due_on' => today()->addYear()->toDateString(), 'data' => ['programme' => 'Diploma in Nursing', 'verification_code' => 'zc-abc123', 'grade' => 'Credit'],
        ])->assertSessionHasNoErrors();
        $grace = Record::query()->ofEntity($app, 'certificates')->latest('id')->firstOrFail();
        $this->assertSame('ZC-ABC123', $grace->value('verification_code'));
        $this->assertFalse($grace->value('_expired'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'certificates']), [
            'title' => 'Someone Else', 'status' => 'issued', 'data' => ['programme' => 'Diploma in Nursing', 'verification_code' => 'ZC-abc123'],
        ])->assertSessionHasErrors('data.verification_code');

        $auto = $this->record($workspace, $app, 'certificates', 'Peter Kamau', 'issued', ['programme' => 'Certificate in Plumbing']);
        $this->assertStringStartsWith('ZC-', (string) $auto->value('verification_code'));
        $this->assertSame(11, strlen((string) $auto->value('verification_code')));
        $expired = $this->record($workspace, $app, 'certificates', 'Old Holder', 'issued', ['programme' => 'Certificate in Plumbing', 'verification_code' => 'ZC-OLD0001'], ['occurs_on' => today()->subYears(3), 'due_on' => today()->subDay()]);
        $this->assertTrue($expired->value('_expired'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'checks']), [
            'title' => 'Kenya Bank', 'status' => 'verified', 'data' => ['code_checked' => '', 'organisation' => 'Kenya Bank'],
        ])->assertSessionHasErrors('data.code_checked');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'checks']), [
            'title' => 'Kenya Bank', 'status' => 'not_found', 'data' => ['code_checked' => 'zc-abc123', 'organisation' => 'Kenya Bank'],
        ])->assertSessionHasNoErrors();
        $check = Record::query()->ofEntity($app, 'checks')->latest('id')->firstOrFail();
        $this->assertSame('verified', $check->status);
        $this->assertSame('ZC-ABC123', $check->value('code_checked'));
        $this->assertSame($grace->id, $check->value('certificate'));
        $this->assertSame('Grace Achieng', $check->value('_holder'));
        $this->assertSame(1, $grace->fresh()->value('_checks'));
        $this->assertSame(today()->toDateString(), $grace->fresh()->value('_last_checked'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'checks']), [
            'title' => 'Unknown employer', 'status' => 'verified', 'data' => ['code_checked' => 'ZC-NOPE'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('not_found', Record::query()->ofEntity($app, 'checks')->latest('id')->firstOrFail()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'checks']), [
            'title' => 'County hospital', 'status' => 'verified', 'data' => ['code_checked' => 'ZC-OLD0001'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('mismatch', Record::query()->ofEntity($app, 'checks')->latest('id')->firstOrFail()->status);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'certificates', $grace->id, 'revoke']))->assertSessionHas('flash.message', 'Certificate ZC-ABC123 revoked.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'checks', $check->id, 'recheck']))->assertSessionHas('flash.message', 'Code ZC-ABC123 is mismatch.');
        $this->assertSame('mismatch', $check->fresh()->status);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'certificates', $grace->id]), [
            'title' => 'Grace Achieng', 'status' => 'issued', 'data' => ['programme' => 'Diploma in Nursing', 'verification_code' => 'ZC-ABC123'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'certificates', $grace->id]))->assertOk()->assertSee('Verification checks')->assertSee('Revoked')->assertSee('Kenya Bank');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Recent checks')->assertSee('Failed checks this month');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Certificates by programme')->assertSee('Diploma in Nursing')->assertSee('Checks by month');
    }

    public function test_university_registers_students_on_active_programmes_and_graduates_them_into_alumni(): void
    {
        $app = 'university';
        [$owner, $workspace] = $this->appWorkspace($app);
        $student = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Brian Otieno', 'email' => 'brian@example.com']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'programmes']), [
            'title' => 'BSc Computer Science', 'status' => 'active', 'amount' => 50000, 'data' => ['faculty' => 'Science', 'level' => 'bachelors', 'duration' => 0],
        ])->assertSessionHasErrors('data.duration');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'programmes']), [
            'title' => 'BSc Computer Science', 'status' => 'active', 'amount' => 50000, 'data' => ['faculty' => 'Science', 'level' => 'bachelors', 'duration' => 4],
        ])->assertSessionHasNoErrors();
        $programme = Record::query()->ofEntity($app, 'programmes')->latest('id')->firstOrFail();
        $closed = $this->record($workspace, $app, 'programmes', 'Old Diploma', 'closed', ['faculty' => 'Arts', 'level' => 'diploma']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Brian Otieno', 'status' => 'pending', 'contact_id' => $student->id, 'data' => ['programme' => $closed->id, 'student_number' => 'AR/001/26'],
        ])->assertSessionHasErrors('data.programme');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Brian Otieno', 'status' => 'pending', 'contact_id' => $student->id, 'occurs_on' => today()->toDateString(), 'data' => ['programme' => $programme->id, 'student_number' => 'cs/001/26', 'semester' => 'Y1S1', 'gpa' => 6],
        ])->assertSessionHasErrors('data.gpa');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Brian Otieno', 'status' => 'graduated', 'contact_id' => $student->id, 'occurs_on' => today()->toDateString(), 'data' => ['programme' => $programme->id, 'student_number' => 'cs/001/26', 'semester' => 'Y1S1'],
        ])->assertSessionHasErrors('data.gpa');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Brian Otieno', 'status' => 'pending', 'contact_id' => $student->id, 'occurs_on' => today()->toDateString(), 'data' => ['programme' => $programme->id, 'student_number' => 'cs/001/26', 'semester' => 'Y1S1'],
        ])->assertSessionHasNoErrors();
        $registration = Record::query()->ofEntity($app, 'registrations')->latest('id')->firstOrFail();
        $this->assertSame('CS/001/26', $registration->value('student_number'));
        $this->assertEquals(50000, $registration->amount);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Brian Otieno', 'status' => 'pending', 'contact_id' => $student->id, 'data' => ['programme' => $programme->id, 'student_number' => 'CS/001/26', 'semester' => 'y1s1'],
        ])->assertSessionHasErrors('data.student_number');
        $this->assertSame(1, $programme->fresh()->value('_pending'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'programmes', $programme->id, 'close']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $registration->id, 'register']))->assertSessionHas('flash.message', 'Brian Otieno is registered on BSc Computer Science.');
        $this->assertSame(1, $programme->fresh()->value('_registered'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $registration->id, 'graduate']), ['gpa' => 7])->assertSessionHasErrors('gpa');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $registration->id, 'graduate']), ['gpa' => 3.7])->assertRedirect();
        $alumnus = Record::query()->ofEntity($app, 'alumni')->latest('id')->firstOrFail();
        $this->assertSame('Brian Otieno', $alumnus->title);
        $this->assertSame($programme->id, $alumnus->value('programme'));
        $this->assertSame((int) today()->year, $alumnus->value('graduation_year'));
        $this->assertSame('brian@example.com', $alumnus->value('email'));
        $this->assertSame('graduated', $registration->fresh()->status);
        $this->assertSame(1, $programme->fresh()->value('_graduated'));
        $this->assertSame(1, $programme->fresh()->value('_alumni'));
        $this->assertEquals(3.7, $programme->fresh()->value('_average_gpa'));
        $this->assertEquals(50000, $programme->fresh()->value('_fees_billed'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'alumni', $alumnus->id, 'lost_contact']))->assertSessionHas('flash.message', 'Marked as lost contact.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'alumni', $alumnus->id, 'found']), ['email' => 'brian@work.example.com', 'employer' => 'Safaricom'])->assertSessionHas('flash.message', 'Brian Otieno is back in touch.');
        $this->assertSame('Safaricom', $alumnus->fresh()->value('employer'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'programmes', $programme->id, 'close']))->assertSessionHas('flash.message', 'BSc Computer Science is closed.');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'programmes', $programme->id]))->assertOk()->assertSee('Programme')->assertSee('Registrations')->assertSee('CS/001/26');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('University')->assertSee('Pending registrations');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Programmes by faculty')->assertSee('Science')->assertSee('Registrations by semester')->assertSee('Y1S1')->assertSee('Alumni by year');
    }

    public function test_hostel_fills_rooms_bed_by_bed_and_tracks_exeats_until_students_are_back(): void
    {
        $app = 'hostel';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'rooms']), [
            'title' => 'Room 1', 'status' => 'available', 'data' => ['hostel_name' => 'Kilimanjaro House', 'gender' => 'male', 'beds' => 0],
        ])->assertSessionHasErrors('data.beds');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'rooms']), [
            'title' => 'Room 1', 'status' => 'available', 'data' => ['hostel_name' => 'Kilimanjaro House', 'gender' => 'male', 'beds' => 2],
        ])->assertSessionHasNoErrors();
        $room = Record::query()->ofEntity($app, 'rooms')->latest('id')->firstOrFail();
        $this->assertSame(2, $room->value('_free'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'allocations']), [
            'title' => 'Juma Ali', 'status' => 'active', 'occurs_on' => today()->toDateString(), 'amount' => 15000, 'data' => ['room' => $room->id, 'bed' => '1'],
        ])->assertSessionHasNoErrors();
        $juma = Record::query()->ofEntity($app, 'allocations')->latest('id')->firstOrFail();
        $this->assertSame(1, $room->fresh()->value('occupied'));
        $this->assertSame('available', $room->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'allocations']), [
            'title' => 'Hassan Omar', 'status' => 'active', 'occurs_on' => today()->toDateString(), 'amount' => 15000, 'data' => ['room' => $room->id, 'bed' => '1'],
        ])->assertSessionHasErrors('data.bed');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'allocations']), [
            'title' => 'Hassan Omar', 'status' => 'active', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(), 'amount' => 15000, 'data' => ['room' => $room->id, 'bed' => '2'],
        ])->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'allocations']), [
            'title' => 'Hassan Omar', 'status' => 'active', 'occurs_on' => today()->toDateString(), 'amount' => 15000, 'data' => ['room' => $room->id, 'bed' => '2'],
        ])->assertSessionHasNoErrors();
        $hassan = Record::query()->ofEntity($app, 'allocations')->latest('id')->firstOrFail();
        $this->assertSame('full', $room->fresh()->status);
        $this->assertSame(0, $room->fresh()->value('_free'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'allocations']), [
            'title' => 'Ali Baba', 'status' => 'active', 'data' => ['room' => $room->id, 'bed' => '3'],
        ])->assertSessionHasErrors('data.room');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'rooms', $room->id]), [
            'title' => 'Room 1', 'status' => 'full', 'data' => ['hostel_name' => 'Kilimanjaro House', 'gender' => 'male', 'beds' => 1],
        ])->assertSessionHasErrors('data.beds');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'rooms', $room->id, 'close']))->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'exeats']), [
            'title' => 'Juma Ali', 'status' => 'requested', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(), 'data' => ['destination' => 'Mombasa'],
        ])->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'exeats']), [
            'title' => 'Juma Ali', 'status' => 'approved', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(2)->toDateString(), 'data' => ['destination' => 'Mombasa'],
        ])->assertSessionHasErrors('data.collected_by');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'exeats']), [
            'title' => 'Juma Ali', 'status' => 'requested', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(2)->toDateString(), 'assignee_id' => $owner->id, 'data' => ['destination' => 'Mombasa'],
        ])->assertSessionHasNoErrors();
        $exeat = Record::query()->ofEntity($app, 'exeats')->latest('id')->firstOrFail();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'exeats', $exeat->id, 'approve']), ['collected_by' => ''])->assertSessionHasErrors('collected_by');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'exeats', $exeat->id, 'approve']), ['collected_by' => 'Mr Ali'])->assertSessionHas('flash.message', 'Exeat approved; Mr Ali collects Juma Ali.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'exeats', $exeat->id, 'sign_out']))->assertSessionHas('flash.message', 'Juma Ali signed out to Mombasa, back on '.today()->addDays(2)->format('d M Y').'.');
        $this->assertSame('out', $exeat->fresh()->status);

        $this->travel(4)->days();
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Overdue returns')->assertSee('Out on exeat')->assertSee('2 days late');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'exeats', $exeat->id, 'sign_in']))->assertSessionHas('flash.message', 'Juma Ali signed in, 2 days late.');
        $this->assertSame('returned', $exeat->fresh()->status);
        $this->assertSame(2, $exeat->fresh()->value('_days_late'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'allocations', $hassan->id, 'end']))->assertSessionHas('flash.message', 'Hassan Omar has moved out.');
        $this->assertSame('ended', $hassan->fresh()->status);
        $this->assertSame(today()->toDateString(), $hassan->fresh()->due_on->toDateString());
        $this->assertSame(1, $room->fresh()->value('occupied'));
        $this->assertSame('available', $room->fresh()->status);
        $this->assertSame(1, $room->fresh()->value('_free'));

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'rooms', $room->id]))->assertOk()->assertSee('Residents')->assertSee('Juma Ali')->assertSee('Bed 1');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Occupancy by hostel')->assertSee('Kilimanjaro House')->assertSee('Exeats by month')->assertSee('Exeats by approver')->assertSee($owner->name);
    }

    public function test_school_transport_logs_one_trip_per_direction_a_day_with_no_more_pupils_than_ride(): void
    {
        $app = 'school-transport';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'routes']), [
            'title' => 'Route A Westlands', 'status' => 'active', 'data' => ['bus' => 'KCA 123A', 'driver' => 'John Mwangi', 'stops' => "Sarit Centre\nABC Place", 'morning_departure' => '06:30'],
        ])->assertSessionHasNoErrors();
        $route = Record::query()->ofEntity($app, 'routes')->latest('id')->firstOrFail();
        $suspended = $this->record($workspace, $app, 'routes', 'Route B Karen', 'suspended', ['bus' => 'KCB 456B']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'riders']), [
            'title' => 'Amina Yusuf', 'status' => 'active', 'amount' => 8000, 'data' => ['route' => $suspended->id, 'stop' => 'Karen Hub'],
        ])->assertSessionHasErrors('data.route');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'riders']), [
            'title' => 'Amina Yusuf', 'status' => 'active', 'amount' => 8000, 'data' => ['route' => $route->id, 'stop' => 'Sarit Centre'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'riders']), [
            'title' => 'Baraka Odhiambo', 'status' => 'active', 'data' => ['route' => $route->id, 'stop' => 'ABC Place'],
        ])->assertSessionHasNoErrors();
        $baraka = Record::query()->ofEntity($app, 'riders')->latest('id')->firstOrFail();
        $this->assertEquals(8000, $baraka->amount);
        $this->assertSame(2, $route->fresh()->value('_riders'));
        $this->assertEquals(16000, $route->fresh()->value('_fees'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trips']), [
            'title' => 'Morning run', 'status' => 'completed', 'occurs_on' => today()->toDateString(), 'data' => ['route' => $route->id, 'direction' => 'morning', 'pupils' => 3],
        ])->assertSessionHasErrors('data.pupils');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trips']), [
            'title' => 'Morning run', 'status' => 'completed', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['route' => $route->id, 'direction' => 'morning', 'pupils' => 2],
        ])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trips']), [
            'title' => 'Morning run', 'status' => 'completed', 'occurs_on' => today()->toDateString(), 'data' => ['route' => $route->id, 'direction' => 'morning', 'pupils' => 2],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'trips']), [
            'title' => 'Morning run again', 'status' => 'completed', 'occurs_on' => today()->toDateString(), 'data' => ['route' => $route->id, 'direction' => 'morning', 'pupils' => 2],
        ])->assertSessionHasErrors('data.direction');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'routes', $route->id, 'log_trip']), ['direction' => 'morning', 'pupils' => 2, 'outcome' => 'completed'])->assertSessionHasErrors('direction');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'routes', $route->id, 'log_trip']), ['direction' => 'afternoon', 'pupils' => 5, 'outcome' => 'completed'])->assertSessionHasErrors('pupils');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'routes', $route->id, 'log_trip']), ['direction' => 'afternoon', 'pupils' => 2, 'outcome' => 'delayed', 'notes' => 'Traffic on Waiyaki Way'])->assertSessionHas('flash.message', 'Afternoon trip logged with 2 pupils (delayed).');
        $afternoon = Record::query()->ofEntity($app, 'trips')->latest('id')->firstOrFail();
        $this->assertSame('delayed', $afternoon->status);
        $this->assertSame($route->id, $afternoon->value('route'));
        $this->assertSame(2, $route->fresh()->value('_trips_month'));
        $this->assertEquals(50, $route->fresh()->value('_on_time_pct'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'riders', $baraka->id, 'stop']))->assertSessionHas('flash.message', 'Baraka Odhiambo no longer rides.');
        $this->assertSame(1, $route->fresh()->value('_riders'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'routes', $route->id, 'suspend']))->assertSessionHas('flash.message', 'Route A Westlands is suspended.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'riders', $baraka->id, 'restart']))->assertSessionHasErrors('data.route');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'routes', $route->id, 'resume']))->assertSessionHas('flash.message', 'Route A Westlands is running again.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'riders', $baraka->id, 'restart']))->assertSessionHas('flash.message', 'Baraka Odhiambo rides again.');
        $this->assertSame(2, $route->fresh()->value('_riders'));

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'routes', $route->id]))->assertOk()->assertSee('Route')->assertSee('Riders')->assertSee('Amina Yusuf')->assertSee('50%');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('School transport')->assertSee("Today's trips")->assertSee('Delayed this month');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Routes')->assertSee('John Mwangi')->assertSee('Trips by month')->assertSee('Riders by stop')->assertSee('Sarit Centre');
    }
}
