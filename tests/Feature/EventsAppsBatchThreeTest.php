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

class EventsAppsBatchThreeTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_wedding_planner_books_vendors_against_a_budget_and_completes_once_the_checklist_is_done(): void
    {
        [$owner, $workspace] = $this->appWorkspace('wedding-event-planning-vendors');
        $app = 'wedding-event-planning-vendors';

        $wedding = $this->record($workspace, $app, 'events', 'Amina & Brian', 'enquiry', ['type' => 'wedding', 'venue' => 'Lake View Gardens', 'guests' => 150, 'budget' => 10000]);

        $this->actingAs($owner)->post($wedding->url().'/actions/contract', ['occurs_on' => today()->addDays(60)->toDateString(), 'amount' => 1500])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Amina & Brian contracted for '.today()->addDays(60)->format('d M Y').'.');
        $wedding = $wedding->fresh();
        $this->assertSame('contracted', $wedding->status);
        $this->assertSame(60, $wedding->value('_days_to_go'));

        $this->actingAs($owner)->post($wedding->url().'/actions/start_planning')->assertSessionHasNoErrors();

        $caterer = $this->record($workspace, $app, 'vendors', 'Tasty Bites', 'shortlisted', ['event' => $wedding->id, 'service' => 'catering', 'deposit' => 0]);
        $this->actingAs($owner)->post($caterer->url().'/actions/book', ['amount' => 6000])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Tasty Bites booked for catering.');
        $this->assertSame(6000.0, (float) $wedding->fresh()->value('_vendor_cost'));
        $this->assertSame(4000.0, (float) $wedding->fresh()->value('_budget_left'));

        $this->actingAs($owner)->post($caterer->url().'/actions/pay_deposit', ['deposit' => 7000])->assertSessionHasErrors('deposit');
        $this->actingAs($owner)->post($caterer->url().'/actions/pay_deposit', ['deposit' => 2000])->assertSessionHasNoErrors();
        $caterer = $caterer->fresh();
        $this->assertSame('deposit_paid', $caterer->status);
        $this->assertSame(4000.0, (float) $caterer->value('_balance'));
        $this->assertSame(4000.0, (float) $wedding->fresh()->value('_vendor_balance'));

        $photographer = $this->record($workspace, $app, 'vendors', 'Snap Studio', 'booked', ['event' => $wedding->id, 'service' => 'photography', 'deposit' => 0], ['amount' => 5000]);
        $wedding = $wedding->fresh();
        $this->assertSame(11000.0, (float) $wedding->value('_vendor_cost'));
        $this->assertTrue((bool) $wedding->value('_over_budget'));

        $this->actingAs($owner)->post($photographer->url().'/actions/cancel')->assertSessionHasNoErrors();
        $this->assertFalse((bool) $wedding->fresh()->value('_over_budget'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'vendors']), [
            'title' => 'Late Florist', 'status' => 'booked', 'amount' => 500,
            'due_on' => today()->addDays(90)->toDateString(),
            'data' => ['event' => $wedding->id, 'service' => 'flowers', 'deposit' => 0],
        ])->assertSessionHasErrors('due_on');

        $cake = $this->record($workspace, $app, 'checklist', 'Order the cake', 'to_do', ['event' => $wedding->id], ['due_on' => today()->subDay()]);
        $wedding = $wedding->fresh();
        $this->assertSame(1, $wedding->value('_overdue_tasks'));
        $this->assertSame(0, $wedding->value('_progress'));

        $this->actingAs($owner)->post($wedding->url().'/actions/complete')->assertSessionHasErrors('status');

        $this->actingAs($owner)->post($cake->url().'/actions/done')
            ->assertSessionHas('flash.message', 'Order the cake done.');
        $wedding = $wedding->fresh();
        $this->assertSame(100, $wedding->value('_progress'));
        $this->assertSame(0, $wedding->value('_overdue_tasks'));

        $this->actingAs($owner)->post($wedding->url().'/actions/complete')->assertSessionHasErrors('status');

        $wedding->update(['occurs_on' => today()->subDay()]);
        $this->actingAs($owner)->post($wedding->url().'/actions/complete')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Amina & Brian completed.');
        $this->assertSame('completed', $wedding->fresh()->status);

        $this->actingAs($owner)->post($caterer->url().'/actions/pay')->assertSessionHas('flash.message', 'Tasty Bites paid in full.');
        $this->assertSame(0.0, (float) $caterer->fresh()->value('_balance'));

        $this->actingAs($owner)->get($wedding->url())->assertOk()->assertSee('Vendors cost');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Upcoming events');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Vendors by service');
    }

    public function test_association_keeps_membership_numbers_unique_counts_verified_cpd_and_lapses_members(): void
    {
        [$owner, $workspace] = $this->appWorkspace('alumni-professional-associations-cpd');
        $app = 'alumni-professional-associations-cpd';

        $grace = $this->record($workspace, $app, 'members', 'Grace Achieng', 'active', ['membership_number' => 'm-100', 'grade' => 'member', 'graduation_year' => 2015], ['amount' => 120, 'occurs_on' => today()->subYears(3), 'due_on' => today()->addDays(10)]);
        $this->assertSame('M-100', $grace->fresh()->value('membership_number'));
        $this->assertSame(3, $grace->fresh()->value('_years'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'members']), [
            'title' => 'Copycat', 'status' => 'active', 'amount' => 120,
            'data' => ['membership_number' => 'M-100', 'grade' => 'associate', 'graduation_year' => 2030],
        ])->assertSessionHasErrors(['data.membership_number', 'data.graduation_year']);

        $course = $this->record($workspace, $app, 'cpd', 'Ethics course', 'submitted', ['member' => $grace->id, 'category' => 'course', 'points' => 12]);
        $this->assertSame(0.0, (float) $grace->fresh()->value('_cpd_points'));
        $this->assertSame(1, $grace->fresh()->value('_cpd_pending'));

        $this->actingAs($owner)->post($course->url().'/actions/verify')
            ->assertSessionHas('flash.message', '12 points verified for Grace Achieng.');
        $grace = $grace->fresh();
        $this->assertSame(12.0, (float) $grace->value('_cpd_points'));
        $this->assertFalse((bool) $grace->value('_cpd_met'));

        $this->record($workspace, $app, 'cpd', 'Annual conference', 'verified', ['member' => $grace->id, 'category' => 'conference', 'points' => 10]);
        $this->assertTrue((bool) $grace->fresh()->value('_cpd_met'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'cpd']), [
            'title' => 'Future webinar', 'status' => 'submitted', 'occurs_on' => today()->addDays(3)->toDateString(),
            'data' => ['member' => $grace->id, 'category' => 'webinar', 'points' => 0],
        ])->assertSessionHasErrors(['occurs_on', 'data.points']);

        $renewal = today()->addDays(10)->addYear();
        $this->actingAs($owner)->post($grace->url().'/actions/renew', ['amount' => 150])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Grace Achieng renewed until '.$renewal->format('d M Y').'.');
        $this->assertSame(150.0, (float) $grace->fresh()->amount);

        $this->actingAs($owner)->post($grace->url().'/actions/change_grade', ['grade' => 'fellow'])
            ->assertSessionHas('flash.message', 'Grace Achieng is now Fellow.');
        $this->actingAs($owner)->post($grace->url().'/actions/change_grade', ['grade' => 'wizard'])->assertSessionHasErrors('grade');

        $late = $this->record($workspace, $app, 'members', 'Late Payer', 'active', ['membership_number' => 'M-200', 'grade' => 'associate'], ['amount' => 80, 'due_on' => today()->subDays(45)]);
        $this->assertTrue((bool) $late->value('_renewal_overdue'));
        $recent = $this->record($workspace, $app, 'members', 'Recently Due', 'active', ['membership_number' => 'M-300', 'grade' => 'associate'], ['amount' => 80, 'due_on' => today()->subDays(5)]);

        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('lapsed', $late->fresh()->status);
        $this->assertSame('active', $recent->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'cpd']), [
            'title' => 'Course while lapsed', 'status' => 'submitted',
            'data' => ['member' => $late->id, 'category' => 'course', 'points' => 5],
        ])->assertSessionHasErrors('data.member');

        $this->actingAs($owner)->post($late->url().'/actions/renew')->assertSessionHasNoErrors();
        $this->assertSame('active', $late->fresh()->status);
        $this->assertSame(today()->addYear()->toDateString(), $late->fresh()->due_on->toDateString());

        $this->actingAs($owner)->get($grace->url())->assertOk()->assertSee('CPD this year');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Renewals due');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('CPD compliance');
    }

    public function test_social_work_cases_open_with_a_care_plan_log_visits_and_close_only_when_none_are_scheduled(): void
    {
        [$owner, $workspace] = $this->appWorkspace('social-work-case-management');
        $app = 'social-work-case-management';

        $case = $this->record($workspace, $app, 'cases', 'Otieno household', 'intake', ['category' => 'child_protection', 'risk' => 'medium', 'household_size' => 4], ['occurs_on' => today()->subDays(10)]);
        $this->assertSame(10, $case->value('_days_open'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'cases']), [
            'title' => 'Bad intake', 'status' => 'active',
            'data' => ['category' => 'gbv', 'risk' => 'urgent', 'household_size' => 0, 'care_plan' => ''],
        ])->assertSessionHasErrors(['data.household_size', 'due_on', 'data.care_plan']);

        $this->actingAs($owner)->post($case->url().'/actions/assess')
            ->assertSessionHas('flash.message', 'Assessment of Otieno household started.');

        $this->actingAs($owner)->post($case->url().'/actions/activate', ['risk' => 'high', 'care_plan' => 'Weekly home visits and school liaison.', 'due_on' => today()->toDateString()])
            ->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post($case->url().'/actions/activate', ['risk' => 'high', 'care_plan' => 'Weekly home visits and school liaison.', 'due_on' => today()->addDays(30)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Case Otieno household is active; review on '.today()->addDays(30)->format('d M Y').'.');
        $case = $case->fresh();
        $this->assertSame('active', $case->status);
        $this->assertSame('high', $case->value('risk'));

        $this->actingAs($owner)->post($case->url().'/actions/schedule_visit', ['title' => 'First home visit', 'occurs_on' => today()->addDays(2)->toDateString(), 'type' => 'home_visit'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'First home visit scheduled for '.today()->addDays(2)->format('d M Y').'.');
        $visit = Record::where('entity', 'visits')->where('title', 'First home visit')->firstOrFail();
        $this->assertSame($case->id, (int) $visit->value('case'));
        $this->assertSame(1, $case->fresh()->value('_scheduled'));
        $this->assertSame(today()->addDays(2)->toDateString(), $case->fresh()->value('_next_visit'));

        $this->actingAs($owner)->post($visit->url().'/actions/complete', ['notes' => 'Went well.'])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($case->url().'/actions/close')->assertSessionHasErrors('status');

        $visit->update(['occurs_on' => today()->subDay()]);
        $this->actingAs($owner)->post($visit->url().'/actions/complete', ['notes' => 'Children in school.', 'referral' => 'County children officer'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Visit completed; referred to County children officer.');
        $case = $case->fresh();
        $this->assertSame('referred', $case->status);
        $this->assertSame(1, $case->value('_completed'));
        $this->assertSame(1, $case->value('_referrals'));
        $this->assertSame(today()->subDay()->toDateString(), $case->value('_last_visit'));

        $missed = $this->record($workspace, $app, 'visits', 'Office follow-up', 'scheduled', ['case' => $case->id, 'type' => 'office', 'notes' => 'Follow up'], ['occurs_on' => today()->subDays(2)]);
        $this->actingAs($owner)->post($missed->url().'/actions/miss')->assertSessionHas('flash.message', 'Visit missed.');
        $this->assertSame(1, $case->fresh()->value('_missed'));

        $this->actingAs($owner)->post($case->url().'/actions/review', ['risk' => 'low', 'due_on' => today()->addDays(60)->toDateString()])
            ->assertSessionHasNoErrors();
        $case = $case->fresh();
        $this->assertSame('active', $case->status);
        $this->assertSame('low', $case->value('risk'));

        $this->actingAs($owner)->post($case->url().'/actions/close')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Case Otieno household closed after 1 visits.');
        $this->assertSame('closed', $case->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), [
            'title' => 'Visit on closed case', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(),
            'data' => ['case' => $case->id, 'type' => 'phone', 'notes' => 'Call'],
        ])->assertSessionHasErrors('data.case');

        $this->actingAs($owner)->post($case->url().'/actions/reopen')->assertSessionHas('flash.message', 'Case Otieno household reopened.');

        $this->actingAs($owner)->get($case->url())->assertOk()->assertSee('Days open');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Reviews due');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Cases by risk');
    }

    public function test_funeral_policies_collect_premiums_lapse_in_arrears_and_cover_funeral_claims(): void
    {
        [$owner, $workspace] = $this->appWorkspace('funeral-services-funeral-policies');
        $app = 'funeral-services-funeral-policies';

        $policy = $this->record($workspace, $app, 'policies', 'Mary Wanjiru', 'active', ['policy_number' => 'fp-001', 'plan' => 'family', 'premium' => 500], ['amount' => 50000, 'occurs_on' => today()->subYear(), 'due_on' => today()->subDays(35)]);
        $policy = $policy->fresh();
        $this->assertSame('FP-001', $policy->value('policy_number'));
        $this->assertSame(2, $policy->value('_months_in_arrears'));
        $this->assertSame(1000.0, (float) $policy->value('_arrears'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'policies']), [
            'title' => 'Duplicate', 'status' => 'active', 'amount' => 0,
            'data' => ['policy_number' => 'FP-001', 'plan' => 'single', 'premium' => 0],
        ])->assertSessionHasErrors(['data.policy_number', 'data.premium', 'amount']);

        $this->actingAs($owner)->post($policy->url().'/actions/record_premium', ['months' => 2])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Premium received for FP-001; paid until '.today()->addMonths(2)->format('d M Y').'.');
        $policy = $policy->fresh();
        $this->assertSame(0, $policy->value('_months_in_arrears'));

        $this->actingAs($owner)->post($policy->url().'/actions/record_premium', ['months' => 1])->assertSessionHasNoErrors();
        $this->assertSame(today()->addMonths(3)->toDateString(), $policy->fresh()->due_on->toDateString());

        $old = $this->record($workspace, $app, 'policies', 'Old Policy', 'active', ['policy_number' => 'FP-002', 'plan' => 'single', 'premium' => 200], ['amount' => 20000, 'due_on' => today()->subDays(70)]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('lapsed', $old->fresh()->status);
        $this->assertSame('active', $policy->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'funerals']), [
            'title' => 'No cover', 'status' => 'reported',
            'data' => ['policy' => $old->id, 'date_of_death' => today()->addDay()->toDateString()],
        ])->assertSessionHasErrors(['data.policy', 'data.date_of_death']);

        $this->actingAs($owner)->post($old->url().'/actions/reinstate', ['months' => 3])
            ->assertSessionHas('flash.message', 'Policy FP-002 reinstated; paid until '.today()->addMonths(3)->format('d M Y').'.');
        $this->assertSame('active', $old->fresh()->status);

        $funeral = $this->record($workspace, $app, 'funerals', 'Peter Wanjiru', 'reported', ['policy' => $policy->id, 'date_of_death' => today()->subDays(3)->toDateString()]);
        $this->assertSame(50000.0, (float) $funeral->fresh()->value('_cover'));
        $this->assertSame(3, $funeral->fresh()->value('_days_since_death'));
        $this->assertSame(1, $policy->fresh()->value('_funerals'));

        $this->actingAs($owner)->post($funeral->url().'/actions/arrange')->assertNotFound();
        $this->actingAs($owner)->post($funeral->url().'/actions/documents', ['death_certificate' => ''])->assertSessionHasErrors('death_certificate');
        $this->actingAs($owner)->post($funeral->url().'/actions/documents', ['death_certificate' => 'DC-7781'])
            ->assertSessionHas('flash.message', 'Documents for Peter Wanjiru received.');

        $this->actingAs($owner)->post($funeral->url().'/actions/arrange', ['occurs_on' => today()->subDays(5)->toDateString()])->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post($funeral->url().'/actions/arrange', ['occurs_on' => today()->addDays(4)->toDateString(), 'service_venue' => 'St Peter Church', 'cemetery' => 'Lang\'ata', 'amount' => 65000])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Funeral of Peter Wanjiru arranged for '.today()->addDays(4)->format('d M Y').' at St Peter Church.');
        $funeral = $funeral->fresh();
        $this->assertSame('arranged', $funeral->status);
        $this->assertSame(15000.0, (float) $funeral->value('_shortfall'));

        $this->actingAs($owner)->post($funeral->url().'/actions/complete')->assertSessionHasErrors('status');
        $funeral->update(['occurs_on' => today()->subDay()]);
        $this->actingAs($owner)->post($funeral->url().'/actions/complete')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Funeral of Peter Wanjiru held.');

        $this->actingAs($owner)->post($funeral->url().'/actions/settle', ['amount' => 60000])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('flash.message', 'Funeral of Peter Wanjiru settled against policy FP-001; the family covers the shortfall.');
        $funeral = $funeral->fresh();
        $this->assertSame('paid', $funeral->status);
        $this->assertSame(10000.0, (float) $funeral->value('_shortfall'));
        $policy = $policy->fresh();
        $this->assertSame('claimed', $policy->status);
        $this->assertSame(50000.0, (float) $policy->value('_claimed'));

        $this->actingAs($owner)->get($policy->url())->assertOk()->assertSee('Paid until');
        $this->actingAs($owner)->get($funeral->url())->assertOk()->assertSee('Shortfall');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Premiums in arrears');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Policies by plan');
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
