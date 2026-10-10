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

/** People: HR, attendance, rosters, performance, training, the self-service portal and disciplinary cases. */
class PeopleAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_hr_keeps_numbers_unique_and_carries_signed_contracts_onto_the_employee(): void
    {
        $app = 'hr';
        [$owner, $workspace] = $this->appWorkspace($app);
        $employee = fn (string $number, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'employees']), ['title' => 'Jane Doe', 'status' => 'active', 'amount' => 12000, 'occurs_on' => today()->subYears(2)->subMonths(3)->toDateString(), 'data' => ['employee_number' => $number, 'job_title' => 'Clerk', 'employment_type' => 'permanent', ...$data]]);
        $employee(' emp-001')->assertSessionHasNoErrors();
        $employee('EMP 001')->assertSessionHasErrors(['data.employee_number' => 'Employee number EMP 001 belongs to Jane Doe.']);
        $jane = Record::query()->where('entity', 'employees')->firstOrFail();
        $this->assertSame('EMP-001', $jane->value('employee_number'));

        $contract = fn (array $attributes) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'contracts']), ['title' => 'Contract', 'status' => 'draft', 'amount' => 15000, 'occurs_on' => today()->toDateString(), ...$attributes, 'data' => ['employee' => $jane->id, 'type' => 'fixed_term', ...($attributes['data'] ?? [])]]);
        $contract([])->assertSessionHasErrors(['due_on' => 'A fixed-term contract needs an end date.']);
        $contract(['due_on' => today()->subDay()->toDateString()])->assertSessionHasErrors(['due_on' => 'The contract cannot end before it starts.']);

        $first = $this->record($workspace, $app, 'contracts', 'First contract', 'signed', ['employee' => $jane->id, 'type' => 'fixed_term'], ['amount' => 15000, 'occurs_on' => today()->subYear(), 'due_on' => today()->addDays(20)]);
        $jane->refresh();
        $this->assertEquals(15000, $jane->amount);
        $this->assertSame('fixed_term', $jane->value('employment_type'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Running out')->assertSee('Jane Doe');

        $renewal = $this->record($workspace, $app, 'contracts', 'Permanent contract', 'draft', ['employee' => $jane->id, 'type' => 'permanent'], ['amount' => 18000, 'occurs_on' => today()]);
        $this->actingAs($owner)->post($renewal->url().'/actions/sign')->assertSessionHas('flash.message', 'Contract for Jane Doe signed.');
        $this->assertSame('expired', $first->fresh()->status);
        $jane->refresh();
        $this->assertEquals(18000, $jane->amount);
        $this->assertSame('permanent', $jane->value('employment_type'));

        $permit = $this->record($workspace, $app, 'documents', 'Work permit', 'valid', ['employee' => $jane->id, 'type' => 'work_permit'], ['due_on' => today()->addMonth()]);
        $this->assertSame('valid', $permit->status);
        Record::query()->whereKey($permit->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $permit->fresh()->status);

        $this->actingAs($owner)->get($jane->url())->assertOk()->assertSee('2 years 3 months')->assertSee('Open-ended')->assertSee('Expired documents');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Headcount by department')->assertSee($this->money(18000));
    }

    public function test_attendance_marks_late_clock_ins_and_fills_timesheets_with_overtime(): void
    {
        $app = 'attendance';
        [$owner, $workspace] = $this->appWorkspace($app);
        $owner->update(['name' => 'Jane Doe']);
        $monday = today()->subWeek()->startOfWeek();
        $clock = fn (array $data, ?string $day = null) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'clockings']), ['title' => 'Jane Doe', 'status' => 'present', 'assignee_id' => $owner->id, 'occurs_on' => $day ?? $monday->toDateString(), 'data' => $data]);
        $clock(['clock_in' => '08:05', 'clock_out' => '17:05'])->assertSessionHasNoErrors();
        $clock(['clock_in' => '09:00'])->assertSessionHasErrors(['title' => 'Jane Doe has already clocked in on '.$monday->format('d M Y').'.']);
        $tuesday = $monday->copy()->addDay()->toDateString();
        $clock(['clock_in' => '08:30', 'clock_out' => '07:00'], $tuesday)->assertSessionHasErrors(['data.clock_out' => 'Clocking out must come after clocking in.']);
        $clock(['clock_in' => '08:30'], $tuesday)->assertSessionHasNoErrors();
        $this->assertSame('present', Record::query()->where('entity', 'clockings')->whereDate('occurs_on', $monday->toDateString())->firstOrFail()->status);
        $late = Record::query()->where('entity', 'clockings')->whereDate('occurs_on', $tuesday)->firstOrFail();
        $this->assertSame('late', $late->status);
        $this->actingAs($owner)->post($late->url().'/actions/clock_out')->assertSessionHas('flash.message', 'Jane Doe clocked out at 23:59 after 15.48 hours.');
        foreach ([2, 3, 4] as $offset) {
            $this->record($workspace, $app, 'clockings', 'Jane Doe', 'present', ['clock_in' => '08:00', 'clock_out' => '17:00'], ['assignee_id' => $owner->id, 'occurs_on' => $monday->copy()->addDays($offset)]);
        }

        $timesheet = fn () => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'timesheets']), ['title' => 'Week', 'status' => 'draft', 'occurs_on' => $monday->copy()->addDays(2)->toDateString(), 'data' => ['employee' => $owner->id]]);
        $timesheet()->assertSessionHasNoErrors();
        $timesheet()->assertSessionHasErrors(['data.employee' => 'Jane Doe already has a timesheet for the week of '.$monday->format('d M Y').'.']);
        $week = Record::query()->where('entity', 'timesheets')->firstOrFail();
        $this->assertTrue($week->occurs_on->isSameDay($monday));
        $this->actingAs($owner)->post($week->url().'/actions/fill')->assertSessionHas('flash.message', 'Filled from 5 clock-ins: 40 normal and 11.48 overtime hours.');
        $this->actingAs($owner)->post($week->url().'/actions/submit')->assertSessionHas('flash.message', 'Timesheet submitted for approval.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Timesheets to approve')->assertSee('51.48 h');
        $this->actingAs($owner)->post($week->url().'/actions/approve')->assertSessionHas('flash.message', 'Timesheet approved: 51.48 hours.');
        $this->assertSame('approved', $week->fresh()->status);

        $this->actingAs($owner)->get($week->url())->assertOk()->assertSee('Days late');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Attendance by employee')->assertSee('51.48');
    }

    public function test_rosters_stop_double_booking_and_swaps_move_the_shift(): void
    {
        $app = 'rosters';
        [$owner, $workspace] = $this->appWorkspace($app);
        $owner->update(['name' => 'Sam Phiri']);
        $lee = $this->member($workspace, 'Lee Banda');
        $tomorrow = today()->addDay();
        $shift = fn (string $title, string $start, string $end) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shifts']), ['title' => $title, 'status' => 'planned', 'assignee_id' => $owner->id, 'occurs_on' => $tomorrow->toDateString(), 'data' => ['start_time' => $start, 'end_time' => $end]]);
        $shift('Morning', '06:00', '14:00')->assertSessionHasNoErrors();
        $shift('Late', '13:00', '21:00')->assertSessionHasErrors(['assignee_id' => 'Sam Phiri is already on the 06:00–14:00 shift that day.']);
        $shift('Odd', '09:00', '09:00')->assertSessionHasErrors(['data.end_time' => 'The shift must end at a different time from when it starts.']);
        $shift('Night', '22:00', '06:00')->assertSessionHasNoErrors();
        $morning = Record::query()->where('entity', 'shifts')->where('title', 'Morning')->firstOrFail();
        $night = Record::query()->where('entity', 'shifts')->where('title', 'Night')->firstOrFail();
        $this->assertEquals(8, $night->value('_hours'));

        $swap = fn (int $with) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'swaps']), ['title' => 'Doctor', 'status' => 'requested', 'assignee_id' => $owner->id, 'data' => ['shift' => $morning->id, 'swap_with' => $with]]);
        $swap($owner->id)->assertSessionHasErrors(['data.swap_with' => 'Pick someone other than the person on the shift.']);
        $swap($lee->id)->assertSessionHasNoErrors();
        $request = Record::query()->where('entity', 'swaps')->firstOrFail();
        $busy = $this->record($workspace, $app, 'shifts', 'Cover', 'planned', ['start_time' => '10:00', 'end_time' => '12:00'], ['assignee_id' => $lee->id, 'occurs_on' => $tomorrow]);
        $this->actingAs($owner)->post($request->url().'/actions/approve')->assertSessionHasErrors(['swap_with' => 'Lee Banda is already on the 10:00–12:00 shift that day.']);
        Record::query()->whereKey($busy->id)->update(['occurs_on' => today()->addDays(5)]);
        $this->actingAs($owner)->post($request->url().'/actions/approve')->assertSessionHas('flash.message', 'Lee Banda now works the '.$tomorrow->format('d M').' shift instead of Sam Phiri.');
        $morning->refresh();
        $this->assertSame($lee->id, $morning->assignee_id);
        $this->assertSame('swapped', $morning->status);

        $this->actingAs($owner)->post($night->url().'/actions/confirm')->assertSessionHas('flash.message', 'Sam Phiri is confirmed for '.$tomorrow->format('d M').'.');
        $this->actingAs($owner)->post($night->url().'/actions/complete')->assertSessionHas('flash.message', 'Sam Phiri worked 8 hours.');
        $this->record($workspace, $app, 'shifts', 'Day', 'completed', ['start_time' => '07:00', 'end_time' => '15:30'], ['assignee_id' => $lee->id, 'occurs_on' => today()]);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Hours by person')->assertSee('Lee Banda')->assertSee('8.5');
    }

    public function test_performance_reviews_need_a_rating_and_objectives_follow_their_progress(): void
    {
        $app = 'performance';
        [$owner, $workspace] = $this->appWorkspace($app);
        $jane = $this->member($workspace, 'Jane Doe');
        $review = fn (array $data, string $status = 'scheduled') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'reviews']), ['title' => 'H1 2026', 'status' => $status, 'data' => ['employee' => $jane->id, 'reviewer' => $owner->id, ...$data]]);
        $review(['reviewer' => $jane->id])->assertSessionHasErrors(['data.reviewer' => 'Pick a reviewer other than the employee.']);
        $review([], 'completed')->assertSessionHasErrors(['data.rating' => 'Give a rating before completing the review.']);
        $review([])->assertSessionHasNoErrors();
        $h1 = Record::query()->where('entity', 'reviews')->firstOrFail();
        $this->actingAs($owner)->post($h1->url().'/actions/advance')->assertSessionHas('flash.message', 'Jane Doe\'s review is with them for their self review.');
        $this->actingAs($owner)->post($h1->url().'/actions/advance')->assertSessionHas('flash.message', 'Jane Doe\'s review is with the manager.');
        $this->actingAs($owner)->post($h1->url().'/actions/advance')->assertSessionHasErrors(['rating' => 'Give a rating before completing the review.']);
        $this->actingAs($owner)->post($h1->url().'/actions/advance', ['rating' => 4])->assertSessionHas('flash.message', 'Jane Doe\'s review is complete, rated 4 out of 5.');

        $sales = $this->record($workspace, $app, 'objectives', 'Grow sales 20%', 'on_track', ['owner' => $jane->id, 'progress' => 50], ['due_on' => today()->addDays(10)]);
        $this->assertSame('at_risk', $sales->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Objectives slipping')->assertSee('Grow sales 20%');
        $this->actingAs($owner)->post($sales->url().'/actions/progress', ['progress' => 101])->assertSessionHasErrors('progress');
        $this->actingAs($owner)->post($sales->url().'/actions/progress', ['progress' => 80])->assertSessionHas('flash.message', 'Grow sales 20%: 50% → 80%, on track.');
        $this->actingAs($owner)->post($sales->url().'/actions/progress', ['progress' => 100])->assertSessionHas('flash.message', 'Grow sales 20%: 80% → 100%, achieved.');

        $hiring = $this->record($workspace, $app, 'objectives', 'Hire two engineers', 'on_track', ['owner' => $owner->id, 'progress' => 10], ['due_on' => today()->addMonth()]);
        $this->assertSame('on_track', $hiring->status);
        Record::query()->whereKey($hiring->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('behind', $hiring->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'feedback']), ['title' => 'Q3', 'status' => 'given', 'data' => ['about' => $jane->id, 'from' => $jane->id, 'comments' => 'Great work']])
            ->assertSessionHasErrors(['data.from' => 'Nobody can give 360 feedback about themselves.']);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Ratings by employee')->assertSee('Jane Doe')->assertSee('4.0');
    }

    public function test_training_certifications_follow_their_expiry_and_courses_show_cost_per_certificate(): void
    {
        $app = 'training';
        [$owner, $workspace] = $this->appWorkspace($app);
        $jane = $this->member($workspace, 'Jane Doe');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'courses']), ['title' => 'First aid', 'status' => 'planned', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(), 'data' => []])
            ->assertSessionHasErrors(['due_on' => 'The course cannot end before it starts.']);
        $course = $this->record($workspace, $app, 'courses', 'First aid level 1', 'planned', ['type' => 'safety', 'provider' => 'Red Cross'], ['amount' => 3000, 'occurs_on' => today(), 'due_on' => today()->addDay()]);
        $this->actingAs($owner)->post($course->url().'/actions/start')->assertSessionHas('flash.message', 'First aid level 1 has started.');
        $this->actingAs($owner)->post($course->url().'/actions/complete')->assertSessionHas('flash.message', 'First aid level 1 completed. Record each attendee\'s certificate under Certifications.');

        $cpr = $this->record($workspace, $app, 'certifications', 'First aid', 'valid', ['employee' => $jane->id, 'course' => $course->id, 'certificate_number' => 'CPR-1'], ['occurs_on' => today(), 'due_on' => today()->addDays(30)]);
        $this->assertSame('expiring', $cpr->status);
        $this->record($workspace, $app, 'certifications', 'First aid', 'valid', ['employee' => $owner->id, 'course' => $course->id, 'certificate_number' => 'CPR-2'], ['occurs_on' => today(), 'due_on' => today()->addYears(2)]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'certifications']), ['title' => 'First aid', 'status' => 'valid', 'data' => ['employee' => $owner->id, 'certificate_number' => ' cpr-1 ']])
            ->assertSessionHasErrors(['data.certificate_number' => 'Certificate CPR-1 is already recorded for Jane Doe.']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Certifications to renew')->assertSee('Jane Doe');

        Record::query()->whereKey($cpr->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $cpr->fresh()->status);

        $this->actingAs($owner)->get($course->url())->assertOk()->assertSee('Cost per certificate')->assertSee($this->money(1500));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Training spend by type')->assertSee($this->money(3000));
    }

    public function test_self_service_requests_close_only_with_a_response(): void
    {
        $app = 'employee-self-service-portal';
        [$owner, $workspace] = $this->appWorkspace($app);
        $owner->update(['name' => 'Mary HR']);
        $jane = $this->member($workspace, 'Jane Doe');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), ['title' => 'Payslip for May', 'status' => 'done', 'data' => ['employee' => $jane->id, 'type' => 'payslip_copy', 'details' => 'Need it for the bank']])
            ->assertSessionHasErrors(['data.response' => 'Write a response to the employee before closing the request.']);
        $payslip = $this->record($workspace, $app, 'requests', 'Payslip for May', 'submitted', ['employee' => $jane->id, 'type' => 'payslip_copy', 'details' => 'Need it for the bank'], ['occurs_on' => today()->subDays(3), 'assignee_id' => null]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Open staff requests')->assertSee('Payslip for May');
        $this->actingAs($owner)->post($payslip->url().'/actions/start')->assertSessionHas('flash.message', 'Payslip for May picked up by Mary HR.');
        $this->actingAs($owner)->post($payslip->url().'/actions/done')->assertSessionHasErrors(['response' => 'Write a response to the employee before closing the request.']);
        $this->actingAs($owner)->post($payslip->url().'/actions/done', ['response' => 'Emailed to you'])->assertSessionHas('flash.message', 'Payslip for May done after 3 days.');
        $this->assertSame('Emailed to you', $payslip->fresh()->value('response'));

        $notice = $this->record($workspace, $app, 'announcements', 'Year-end function', 'draft', ['body' => 'Friday 5 December']);
        $this->actingAs($owner)->post($notice->url().'/actions/publish')->assertSessionHas('flash.message', 'Year-end function published.');
        $this->assertTrue($notice->fresh()->occurs_on->isToday());
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Year-end function');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Requests by type')->assertSee('Payslip copy');
    }

    public function test_disciplinary_cases_need_hearing_dates_and_fitting_outcomes_and_keep_warnings_on_file(): void
    {
        $app = 'disciplinary-grievance-case-management';
        [$owner, $workspace] = $this->appWorkspace($app);
        $lee = $this->member($workspace, 'Lee Banda');
        $case = fn (string $status, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'cases']), ['title' => 'Late again', 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'disciplinary', 'employee' => $lee->id, ...$data]]);
        $case('hearing_scheduled', [])->assertSessionHasErrors(['data.hearing_at' => 'Set the hearing date.']);
        $case('closed', [])->assertSessionHasErrors(['data.outcome' => 'Record the outcome first.']);
        $case('reported', ['type' => 'grievance', 'outcome' => 'written_warning'])->assertSessionHasErrors(['data.outcome' => 'A grievance is either upheld or not upheld.']);

        $lateness = $this->record($workspace, $app, 'cases', 'Late five times', 'reported', ['type' => 'disciplinary', 'employee' => $lee->id, 'category' => 'attendance'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($lateness->url().'/actions/schedule', ['hearing_at' => today()->subDay()->format('Y-m-d\T10:00')])->assertSessionHasErrors(['hearing_at' => 'The hearing cannot be before the case was reported.']);
        $this->actingAs($owner)->post($lateness->url().'/actions/schedule', ['hearing_at' => today()->addDay()->format('Y-m-d\T10:00')])
            ->assertSessionHas('flash.message', 'Hearing for Lee Banda set for '.today()->addDay()->format('d M Y').' 10:00.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Hearings coming up')->assertSee('Lee Banda');
        $this->actingAs($owner)->post($lateness->url().'/actions/decide', ['outcome' => 'upheld'])->assertSessionHasErrors(['outcome' => 'A disciplinary case ends in no action or a sanction.']);
        $this->actingAs($owner)->post($lateness->url().'/actions/decide', ['outcome' => 'final_warning'])
            ->assertSessionHas('flash.message', 'Outcome for Lee Banda: final warning, on file until '.today()->addMonthsNoOverflow(12)->format('d M Y').'.');
        $this->actingAs($owner)->post($lateness->url().'/actions/close')->assertSessionHas('flash.message', 'Case closed.');

        $again = $this->record($workspace, $app, 'cases', 'Absent without leave', 'reported', ['type' => 'disciplinary', 'employee' => $lee->id, 'category' => 'attendance'], ['occurs_on' => today()]);
        $this->actingAs($owner)->get($again->url())->assertOk()->assertSee('Live warnings')->assertSee('final warning');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Cases by category')->assertSee('Attendance');
    }

    private function member(Workspace $workspace, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $workspace->members()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);

        return $user;
    }

    private function money(float $amount): string
    {
        return Money::format($amount);
    }

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
