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

/** The education apps' rules, batch one: library, nursery & daycare, driving school, LMS, exams & report cards and timetabling. */
class EducationAppsTest extends TestCase
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

    public function test_library_lends_free_copies_only_caps_borrowers_and_fines_late_returns(): void
    {
        $app = 'library';
        [$owner, $workspace] = $this->appWorkspace($app);
        $amina = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Amina Juma']);
        $baraka = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Baraka Odhiambo']);
        $novel = $this->record($workspace, $app, 'books', 'Things Fall Apart', 'available', ['author' => 'Chinua Achebe', 'category' => 'Fiction', 'copies' => 1]);
        $maths = $this->record($workspace, $app, 'books', 'Maths Grade 10', 'available', ['author' => 'KLB', 'category' => 'Textbooks', 'copies' => 2]);
        $atlas = $this->record($workspace, $app, 'books', 'World Atlas', 'available', ['author' => 'Collins', 'category' => 'Reference', 'copies' => 3]);
        $withdrawn = $this->record($workspace, $app, 'books', 'Old Atlas', 'withdrawn', ['author' => 'Collins', 'copies' => 1]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'loans']), [
            'title' => 'Amina Juma', 'status' => 'on_loan', 'contact_id' => $amina->id, 'occurs_on' => today()->toDateString(), 'data' => ['book' => $novel->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $novelLoan = Record::where('entity', 'loans')->where('title', 'Amina Juma')->firstOrFail();
        $this->assertSame(today()->addDays(14)->toDateString(), $novelLoan->due_on->toDateString());
        $this->assertSame('on_loan', $novel->fresh()->status);
        $this->assertEquals(1, $novel->fresh()->value('_out'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'loans']), [
            'title' => 'Baraka Odhiambo', 'status' => 'on_loan', 'contact_id' => $baraka->id, 'data' => ['book' => $novel->id],
        ])->assertSessionHasErrors('data.book');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'loans']), [
            'title' => 'Baraka Odhiambo', 'status' => 'on_loan', 'contact_id' => $baraka->id, 'data' => ['book' => $withdrawn->id],
        ])->assertSessionHasErrors('data.book');

        $this->record($workspace, $app, 'loans', 'Amina Juma', 'on_loan', ['book' => $maths->id], ['contact_id' => $amina->id, 'occurs_on' => today()]);
        $this->record($workspace, $app, 'loans', 'Amina Juma', 'on_loan', ['book' => $maths->id], ['contact_id' => $amina->id, 'occurs_on' => today()]);
        $this->assertSame('on_loan', $maths->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'loans']), [
            'title' => 'Amina Juma', 'status' => 'on_loan', 'contact_id' => $amina->id, 'data' => ['book' => $atlas->id],
        ])->assertSessionHasErrors('contact_id');

        $atlasLoan = $this->record($workspace, $app, 'loans', 'Baraka Odhiambo', 'on_loan', ['book' => $atlas->id], ['contact_id' => $baraka->id, 'occurs_on' => today(), 'due_on' => today()]);
        $this->travel(2)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('overdue', $atlasLoan->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Library')->assertSee('Overdue loans')->assertSee('World Atlas')->assertSee('2 days late');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'loans', $atlasLoan->id, 'return_book']), ['returned_on' => today()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('returned', $atlasLoan->fresh()->status);
        $this->assertEquals(2, $atlasLoan->fresh()->value('fine'));
        $this->assertEquals(0, $atlas->fresh()->value('_out'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'loans', $novelLoan->id, 'mark_lost']))->assertRedirect();
        $this->assertSame('lost', $novelLoan->fresh()->status);
        $this->assertSame('lost', $novel->fresh()->status);

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'books', $novel->id]))->assertOk()->assertSee('Copies')->assertSee('Times borrowed');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Loans by category')->assertSee('Most borrowed')->assertSee('Fines')->assertSee('Textbooks')->assertSee('Baraka Odhiambo');
    }

    public function test_daycare_places_children_by_age_and_closes_incidents_only_after_parents_are_told(): void
    {
        $app = 'daycare';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'children']), [
            'title' => 'Zawadi Mwangi', 'status' => 'enrolled', 'data' => ['date_of_birth' => today()->subMonths(10)->toDateString(), 'guardian_phone' => '0712345678', 'allergies' => 'Peanuts'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $zawadi = Record::where('entity', 'children')->where('title', 'Zawadi Mwangi')->firstOrFail();
        $this->assertSame('babies', $zawadi->value('room'));
        $this->assertEquals(10, $zawadi->value('_age_months'));
        $this->assertTrue((bool) $zawadi->value('_has_allergies'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'children']), [
            'title' => 'Future', 'status' => 'waitlisted', 'data' => ['date_of_birth' => today()->addMonth()->toDateString()],
        ])->assertSessionHasErrors('data.date_of_birth');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'children']), [
            'title' => 'Too old', 'status' => 'waitlisted', 'data' => ['date_of_birth' => today()->subYears(7)->toDateString()],
        ])->assertSessionHasErrors('data.date_of_birth');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'children']), [
            'title' => 'No phone', 'status' => 'enrolled', 'data' => ['date_of_birth' => today()->subYears(2)->toDateString()],
        ])->assertSessionHasErrors('data.guardian_phone');

        $neema = $this->record($workspace, $app, 'children', 'Neema Said', 'waitlisted', ['date_of_birth' => today()->subMonths(30)->toDateString(), 'guardian_phone' => '0700000000']);
        $this->assertSame('toddlers', $neema->value('room'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'children', $neema->id, 'enrol']))->assertRedirect()->assertSessionHas('flash.message', 'Neema Said is enrolled in the toddlers room.');
        $this->record($workspace, $app, 'children', 'Big baby', 'enrolled', ['date_of_birth' => today()->subMonths(20)->toDateString(), 'room' => 'babies', 'guardian_phone' => '0700000001']);
        $left = $this->record($workspace, $app, 'children', 'Moved away', 'left', ['date_of_birth' => today()->subYears(3)->toDateString()]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'incidents']), [
            'title' => 'Bumped head', 'status' => 'open', 'data' => ['child' => $left->id, 'details' => 'Slipped'],
        ])->assertSessionHasErrors('data.child');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'incidents']), [
            'title' => 'Fell off slide', 'status' => 'open', 'data' => ['child' => $zawadi->id, 'details' => 'Fell from the small slide'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $incident = Record::where('entity', 'incidents')->firstOrFail();
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'incidents', $incident->id]), [
            'title' => 'Fell off slide', 'status' => 'closed', 'data' => ['child' => $zawadi->id, 'details' => 'Fell from the small slide'],
        ])->assertSessionHasErrors(['data.action_taken', 'status']);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'incidents', $incident->id, 'inform_parent']))->assertSessionHasErrors('action_taken');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'incidents', $incident->id, 'inform_parent']), ['action_taken' => 'Iced the bruise and called mum'])->assertRedirect();
        $this->assertSame('parent_informed', $incident->fresh()->status);
        $this->assertSame(today()->toDateString(), $incident->fresh()->value('_informed_on'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'incidents', $incident->id, 'close']))->assertRedirect();
        $this->assertSame('closed', $incident->fresh()->status);

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'children', $zawadi->id]))->assertOk()->assertSee('Child')->assertSee('Peanuts')->assertSee('Fell off slide');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Nursery')->assertSee('With allergies')->assertSee('Ready to move room')->assertSee('Open incidents');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Children by room')->assertSee('Incidents by month')->assertSee('Toddlers');
    }

    public function test_driving_school_books_lessons_from_the_package_without_double_booking_cars_or_instructors(): void
    {
        $app = 'driving-school';
        [$owner, $workspace] = $this->appWorkspace($app);
        $instructor = $this->memberOf($workspace);
        $instructor->update(['name' => 'Jane Wanjiru']);
        $kofi = $this->record($workspace, $app, 'learners', 'Kofi Mensah', 'active', ['licence_code' => 'code_8', 'lessons_bought' => 2], ['amount' => 5000]);
        $ama = $this->record($workspace, $app, 'learners', 'Ama Serwaa', 'active', ['licence_code' => 'code_8', 'lessons_bought' => 5], ['amount' => 9000]);
        $dropped = $this->record($workspace, $app, 'learners', 'Gone', 'dropped', ['licence_code' => 'code_8', 'lessons_bought' => 5]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Parking', 'status' => 'booked', 'assignee_id' => $owner->id, 'occurs_on' => today()->toDateString(), 'data' => ['learner' => $kofi->id, 'start_time' => '09:00', 'vehicle' => 'KAA 123A'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $parking = Record::where('entity', 'lessons')->where('title', 'Parking')->firstOrFail();
        $this->assertEquals(1, $kofi->fresh()->value('_lessons_used'));
        $this->assertEquals(1, $kofi->fresh()->value('_lessons_remaining'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Hill start', 'status' => 'booked', 'assignee_id' => $instructor->id, 'occurs_on' => today()->toDateString(), 'data' => ['learner' => $ama->id, 'start_time' => '09:00', 'vehicle' => 'kaa 123a'],
        ])->assertSessionHasErrors('data.vehicle');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Hill start', 'status' => 'booked', 'assignee_id' => $owner->id, 'occurs_on' => today()->toDateString(), 'data' => ['learner' => $ama->id, 'start_time' => '09:00', 'vehicle' => 'KBB 456B'],
        ])->assertSessionHasErrors('assignee_id');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Hill start', 'status' => 'booked', 'assignee_id' => $instructor->id, 'occurs_on' => today()->toDateString(), 'data' => ['learner' => $ama->id, 'start_time' => '09:00', 'vehicle' => 'KBB 456B'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Nope', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['learner' => $dropped->id, 'start_time' => '11:00'],
        ])->assertSessionHasErrors('data.learner');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Three-point turn', 'status' => 'booked', 'assignee_id' => $owner->id, 'occurs_on' => today()->toDateString(), 'data' => ['learner' => $kofi->id, 'start_time' => '10:00', 'vehicle' => 'KAA 123A'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $turn = Record::where('entity', 'lessons')->where('title', 'Three-point turn')->firstOrFail();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Highway', 'status' => 'booked', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['learner' => $kofi->id, 'start_time' => '10:00'],
        ])->assertSessionHasErrors('data.learner');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'lessons', $parking->id, 'complete']))->assertSessionHasErrors('feedback');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'lessons', $parking->id, 'complete']), ['feedback' => 'Good mirror checks'])->assertRedirect()->assertSessionHas('flash.message', 'Lesson completed. 0 lessons left in the package.');
        $this->assertSame('completed', $parking->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'lessons', $turn->id, 'cancel']))->assertRedirect();
        $this->assertEquals(1, $kofi->fresh()->value('_lessons_done'));
        $this->assertEquals(1, $kofi->fresh()->value('_lessons_remaining'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'learners', $kofi->id]), [
            'title' => 'Kofi Mensah', 'status' => 'active', 'data' => ['licence_code' => 'code_8', 'lessons_bought' => 0],
        ])->assertSessionHasErrors('data.lessons_bought');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'learners', $kofi->id]), [
            'title' => 'Kofi Mensah', 'status' => 'licensed', 'data' => ['licence_code' => 'code_8', 'lessons_bought' => 2],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'learners', $kofi->id, 'book_test']))->assertSessionHasErrors('learner_licence');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'learners', $kofi->id, 'book_test']), ['learner_licence' => 'LL-2026-77'])->assertRedirect();
        $this->assertSame('test_booked', $kofi->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'learners', $kofi->id, 'passed']))->assertRedirect();
        $this->assertSame('licensed', $kofi->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Extra', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['learner' => $kofi->id, 'start_time' => '14:00'],
        ])->assertSessionHasErrors('data.learner');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'learners', $kofi->id]))->assertOk()->assertSee('Package')->assertSee('Remaining')->assertSee('Parking');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Driving school')->assertSee('Licensed this year')->assertSee("Today's lessons")->assertSee('Hill start');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Learners by licence code')->assertSee('Lessons by instructor')->assertSee('Vehicle use')->assertSee('Jane Wanjiru')->assertSee('KBB 456B');
    }

    public function test_lms_publishes_courses_with_lessons_and_tracks_enrolments_by_progress(): void
    {
        $app = 'lms';
        [$owner, $workspace] = $this->appWorkspace($app);
        $learner = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Lerato Dube']);
        $course = $this->record($workspace, $app, 'courses', 'Bookkeeping basics', 'draft', ['category' => 'Finance', 'level' => 'beginner', 'price' => 1500]);
        $other = $this->record($workspace, $app, 'courses', 'Advanced tax', 'draft', ['category' => 'Finance', 'level' => 'advanced', 'price' => 3000]);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'courses', $course->id, 'publish']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Welcome', 'status' => 'draft', 'data' => ['course' => $course->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $welcome = Record::where('entity', 'lessons')->where('title', 'Welcome')->firstOrFail();
        $this->assertEquals(1, $welcome->value('order'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Debits & credits', 'status' => 'published', 'data' => ['course' => $course->id, 'order' => 1],
        ])->assertSessionHasErrors('data.order');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Debits & credits', 'status' => 'published', 'data' => ['course' => $course->id, 'order' => 2, 'video_url' => 'https://example.com/v/1'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(2, $course->fresh()->value('_lessons'));
        $this->assertEquals(1, $course->fresh()->value('_published_lessons'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'courses', $course->id, 'publish']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('published', $course->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'enrolments']), [
            'title' => 'Lerato Dube', 'status' => 'enrolled', 'contact_id' => $learner->id, 'data' => ['course' => $other->id],
        ])->assertSessionHasErrors('data.course');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'enrolments']), [
            'title' => 'Lerato Dube', 'status' => 'enrolled', 'contact_id' => $learner->id, 'amount' => 2000, 'data' => ['course' => $course->id],
        ])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'enrolments']), [
            'title' => 'Lerato Dube', 'status' => 'enrolled', 'contact_id' => $learner->id, 'data' => ['course' => $course->id],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $enrolment = Record::where('entity', 'enrolments')->where('title', 'Lerato Dube')->firstOrFail();
        $this->assertEquals(1500, $enrolment->amount);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'enrolments', $enrolment->id]), [
            'title' => 'Lerato Dube', 'status' => 'completed', 'contact_id' => $learner->id, 'amount' => 1500, 'data' => ['course' => $course->id, 'progress' => 40],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'enrolments', $enrolment->id, 'progress']), ['progress' => 50])->assertRedirect();
        $this->assertSame('in_progress', $enrolment->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'enrolments', $enrolment->id, 'progress']), ['progress' => 100])->assertRedirect();
        $this->assertSame('completed', $enrolment->fresh()->status);
        $this->assertSame(today()->toDateString(), $enrolment->fresh()->value('_completed_on'));
        $this->assertEquals(1, $course->fresh()->value('_completed'));
        $this->assertEquals(100, $course->fresh()->value('_completion_rate'));
        $this->assertEquals(1500, $course->fresh()->value('_revenue'));

        $thabo = $this->record($workspace, $app, 'enrolments', 'Thabo Nkosi', 'enrolled', ['course' => $course->id, 'progress' => 0], ['amount' => 1500, 'occurs_on' => today()]);
        $this->assertEquals(50, $course->fresh()->value('_completion_rate'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'courses', $course->id, 'archive']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'enrolments', $thabo->id, 'drop']))->assertRedirect();
        $this->assertSame('dropped', $thabo->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'courses', $course->id, 'archive']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('archived', $course->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lessons']), [
            'title' => 'Late lesson', 'status' => 'draft', 'data' => ['course' => $course->id],
        ])->assertSessionHasErrors('data.course');

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'courses', $course->id]))->assertOk()->assertSee('Course')->assertSee('Completion rate')->assertSee('1. Welcome');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('LMS')->assertSee('Top courses')->assertSee('Revenue this month')->assertSee('1,500.00');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Courses')->assertSee('Enrolments by month')->assertSee('Bookkeeping basics')->assertSee('Beginner');
    }

    public function test_exams_grade_marks_within_the_maximum_and_report_cards_compile_averages_and_positions(): void
    {
        $app = 'exams';
        [$owner, $workspace] = $this->appWorkspace($app);
        $maths = $this->record($workspace, $app, 'exams', 'Mid-term Maths', 'scheduled', ['term' => 'Term 1', 'class_name' => 'Grade 10A', 'subject' => 'Mathematics', 'max_mark' => 50], ['occurs_on' => today()]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'exams']), [
            'title' => 'Broken', 'status' => 'scheduled', 'data' => ['subject' => 'Art', 'max_mark' => 0],
        ])->assertSessionHasErrors('data.max_mark');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'exams', $maths->id]), [
            'title' => 'Mid-term Maths', 'status' => 'published', 'data' => ['term' => 'Term 1', 'class_name' => 'Grade 10A', 'subject' => 'Mathematics', 'max_mark' => 50],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'marks']), [
            'title' => 'Aisha Noor', 'status' => 'entered', 'data' => ['exam' => $maths->id, 'mark' => 45],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $aisha = Record::where('entity', 'marks')->where('title', 'Aisha Noor')->firstOrFail();
        $this->assertSame('A', $aisha->value('grade'));
        $this->assertEquals(90, $aisha->value('_percentage'));
        $this->assertSame('marking', $maths->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'marks']), [
            'title' => 'Dan Kiprop', 'status' => 'entered', 'data' => ['exam' => $maths->id, 'mark' => 60],
        ])->assertSessionHasErrors('data.mark');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'marks']), [
            'title' => 'Aisha Noor', 'status' => 'entered', 'data' => ['exam' => $maths->id, 'mark' => 40],
        ])->assertSessionHasErrors('title');
        $this->record($workspace, $app, 'marks', 'Brian Otieno', 'entered', ['exam' => $maths->id, 'mark' => 20]);
        $this->record($workspace, $app, 'marks', 'Chen Li', 'entered', ['exam' => $maths->id, 'mark' => 30]);
        $this->assertEquals(3, $maths->fresh()->value('_entered'));
        $this->assertEquals(63.3, $maths->fresh()->value('_average'));
        $this->assertEquals(45, $maths->fresh()->value('_highest'));
        $this->assertEquals(67, $maths->fresh()->value('_pass_rate'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'exams', $maths->id, 'publish']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('published', $maths->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'marks']), [
            'title' => 'Late entry', 'status' => 'entered', 'data' => ['exam' => $maths->id, 'mark' => 10],
        ])->assertSessionHasErrors('data.exam');

        $english = $this->record($workspace, $app, 'exams', 'Mid-term English', 'published', ['term' => 'Term 1', 'class_name' => 'Grade 10A', 'subject' => 'English', 'max_mark' => 100], ['occurs_on' => today()]);
        $this->record($workspace, $app, 'marks', 'Aisha Noor', 'entered', ['exam' => $english->id, 'mark' => 70]);
        $this->record($workspace, $app, 'marks', 'Brian Otieno', 'entered', ['exam' => $english->id, 'mark' => 50]);
        $this->record($workspace, $app, 'marks', 'Chen Li', 'entered', ['exam' => $english->id, 'mark' => 80]);

        $report = $this->record($workspace, $app, 'reports', 'Aisha Noor', 'draft', ['term' => 'Term 1', 'class_name' => 'Grade 10A']);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'reports', $report->id, 'issue']), ['teacher_comment' => 'Excellent'])->assertSessionHasErrors('teacher_comment');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'reports', $report->id, 'compile']))->assertRedirect()->assertSessionHas('flash.message', 'Aisha Noor averaged 80%, position 1 of 3.');
        $this->assertEquals(80, $report->fresh()->value('average'));
        $this->assertEquals(1, $report->fresh()->value('position'));
        $this->assertSame('A', $report->fresh()->value('grade'));
        $nobody = $this->record($workspace, $app, 'reports', 'Nobody Here', 'draft', ['term' => 'Term 1', 'class_name' => 'Grade 10A']);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'reports', $nobody->id, 'compile']))->assertSessionHasErrors('title');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'reports', $report->id]), [
            'title' => 'Aisha Noor', 'status' => 'draft', 'data' => ['term' => 'Term 1', 'class_name' => 'Grade 10A', 'average' => 120],
        ])->assertSessionHasErrors('data.average');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'reports', $report->id, 'issue']), ['teacher_comment' => 'Excellent work'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('issued', $report->fresh()->status);
        $this->assertSame(today()->toDateString(), $report->fresh()->occurs_on->toDateString());

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'exams', $maths->id]))->assertOk()->assertSee('Results')->assertSee('Pass rate')->assertSee('Brian Otieno')->assertSee('45 (A)');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Exams')->assertSee('Marking in progress')->assertSee('Report cards to issue');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Results by subject')->assertSee('Report cards by class')->assertSee('Mathematics')->assertSee('63.3%');
    }

    public function test_timetable_refuses_clashing_classes_teachers_and_rooms(): void
    {
        $app = 'timetable';
        [$owner, $workspace] = $this->appWorkspace($app);
        $other = $this->memberOf($workspace);
        $other->update(['name' => 'Peter Kamau']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'periods']), [
            'title' => 'Mathematics', 'status' => 'active', 'data' => ['class_name' => 'Grade 10A', 'day' => 'monday', 'start_time' => '08:00', 'end_time' => '08:40', 'teacher' => $owner->id, 'room' => 'R1'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $mathematics = Record::where('entity', 'periods')->where('title', 'Mathematics')->firstOrFail();
        $this->assertEquals(40, $mathematics->value('_minutes'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'periods']), [
            'title' => 'Backwards', 'status' => 'active', 'data' => ['class_name' => 'Grade 10C', 'day' => 'monday', 'start_time' => '09:00', 'end_time' => '08:40'],
        ])->assertSessionHasErrors('data.end_time');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'periods']), [
            'title' => 'English', 'status' => 'active', 'data' => ['class_name' => 'grade 10a', 'day' => 'monday', 'start_time' => '08:20', 'end_time' => '09:00', 'teacher' => $other->id, 'room' => 'R2'],
        ])->assertSessionHasErrors('data.class_name');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'periods']), [
            'title' => 'English', 'status' => 'active', 'data' => ['class_name' => 'Grade 10B', 'day' => 'monday', 'start_time' => '08:20', 'end_time' => '09:00', 'teacher' => $owner->id, 'room' => 'R2'],
        ])->assertSessionHasErrors('data.teacher');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'periods']), [
            'title' => 'English', 'status' => 'active', 'data' => ['class_name' => 'Grade 10B', 'day' => 'monday', 'start_time' => '08:20', 'end_time' => '09:00', 'teacher' => $other->id, 'room' => 'r1'],
        ])->assertSessionHasErrors('data.room');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'periods']), [
            'title' => 'English', 'status' => 'active', 'data' => ['class_name' => 'Grade 10B', 'day' => 'tuesday', 'start_time' => '08:20', 'end_time' => '09:00', 'teacher' => $owner->id, 'room' => 'R1'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'periods']), [
            'title' => 'English', 'status' => 'active', 'data' => ['class_name' => 'Grade 10B', 'day' => 'monday', 'start_time' => '08:20', 'end_time' => '09:00', 'teacher' => $other->id, 'room' => 'R2'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $english = Record::where('entity', 'periods')->where('title', 'English')->where('data->day', 'monday')->firstOrFail();

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'periods', $english->id, 'cancel']))->assertRedirect();
        $this->assertSame('cancelled', $english->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'periods']), [
            'title' => 'Science', 'status' => 'active', 'data' => ['class_name' => 'Grade 10B', 'day' => 'monday', 'start_time' => '08:30', 'end_time' => '09:10', 'teacher' => $other->id, 'room' => 'R2'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'periods', $english->id, 'restore']))->assertSessionHasErrors('data.class_name');
        $this->assertSame('cancelled', $english->fresh()->status);

        $this->actingAs($owner)->get(route('apps.records.show', [$app, 'periods', $mathematics->id]))->assertOk()->assertSee('Period')->assertSee('40 min')->assertSee('Same day for Grade 10A');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Timetable')->assertSee('Hours a week')->assertSee("Today's periods");
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Teacher load')->assertSee('Class hours')->assertSee('Periods by day')->assertSee('Peter Kamau')->assertSee('Monday');
    }
}
