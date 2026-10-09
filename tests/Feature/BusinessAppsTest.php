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

/** The general business apps' rules: CRM, expenses, petty cash, fixed assets, loans, insurance, recruitment, leave and projects. */
class BusinessAppsTest extends TestCase
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

    public function test_crm_weights_deals_by_stage_and_records_why_deals_are_lost(): void
    {
        $app = 'crm';
        [$owner, $workspace] = $this->appWorkspace($app);
        $deal = $this->record($workspace, $app, 'deals', 'Office fit-out', 'lead', ['source' => 'referral'], ['amount' => 10000, 'due_on' => today()->addDays(3)]);
        $this->assertEquals(10, $deal->value('probability'));
        $this->assertEquals(1000, $deal->value('_weighted'));

        $deal->update(['status' => 'proposal']);
        $this->assertEquals(50, $deal->fresh()->value('probability'));
        $custom = $this->record($workspace, $app, 'deals', 'Fleet tracking', 'qualified', ['probability' => 60, 'source' => 'website'], ['amount' => 5000]);
        $custom->update(['status' => 'proposal']);
        $this->assertEquals(60, $custom->fresh()->value('probability'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deals']), ['title' => 'Bad', 'status' => 'lost', 'data' => ['probability' => 150]])
            ->assertSessionHasErrors(['data.probability', 'data.lost_reason']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deals']), ['title' => 'Free', 'status' => 'won'])->assertSessionHasErrors('amount');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Weighted forecast')->assertSee('Deals to chase')->assertSee('Office fit-out');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'deals', $custom->id, 'lost']), ['lost_reason' => 'Price too high'])->assertRedirect();
        $this->assertSame('lost', $custom->fresh()->status);
        $this->assertEquals(0, $custom->fresh()->value('probability'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'deals', $deal->id, 'won']))->assertRedirect();
        $this->assertEquals(100, $deal->fresh()->value('probability'));

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('50%');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Pipeline by stage')->assertSee('Why deals were lost')->assertSee('Price too high');
    }

    public function test_expenses_move_through_approval_and_block_duplicate_receipts(): void
    {
        $app = 'expenses';
        [$owner, $workspace] = $this->appWorkspace($app);
        $claim = $this->record($workspace, $app, 'expenses', 'Fuel to Blantyre', 'submitted', ['category' => 'fuel', 'payment_method' => 'personal', 'supplier' => 'Puma', 'receipt_number' => 'R-100'], ['amount' => 80, 'occurs_on' => today()]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'expenses']), [
            'title' => 'Same fuel', 'status' => 'submitted', 'amount' => 80, 'data' => ['category' => 'fuel', 'supplier' => 'Puma', 'receipt_number' => 'R-100'],
        ])->assertSessionHasErrors('data.receipt_number');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'expenses']), ['title' => 'Nothing', 'status' => 'submitted', 'data' => ['category' => 'meals']])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'expenses']), [
            'title' => 'Card lunch', 'status' => 'reimbursed', 'amount' => 20, 'data' => ['category' => 'meals', 'payment_method' => 'card'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting for approval')->assertSee('Fuel to Blantyre');

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'expenses', $claim->id]), [
            'title' => $claim->title, 'status' => 'reimbursed', 'amount' => 80, 'occurs_on' => today()->toDateString(), 'data' => $claim->data,
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'expenses', $claim->id, 'approve']))->assertRedirect();
        $this->assertSame('approved', $claim->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('To pay back to staff')->assertSee('80.00');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'expenses', $claim->id, 'reimburse']))->assertRedirect();
        $this->assertSame('reimbursed', $claim->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Spending by category')->assertSee('Fuel')->assertSee('Paid with');
    }

    public function test_petty_cash_never_goes_negative_and_a_count_reconciles_the_book(): void
    {
        $app = 'petty-cash';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->record($workspace, $app, 'entries', 'Float from bank', 'recorded', ['direction' => 'in'], ['amount' => 100, 'occurs_on' => today()->subDays(2)]);
        $this->record($workspace, $app, 'entries', 'Tea and sugar', 'recorded', ['direction' => 'out', 'category' => 'kitchen', 'voucher' => 'V1'], ['amount' => 30, 'occurs_on' => today()->subDay()]);

        $payout = ['title' => 'Taxi', 'status' => 'recorded', 'amount' => 90, 'occurs_on' => today()->toDateString(), 'data' => ['direction' => 'out', 'voucher' => 'V2']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), $payout)->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [...$payout, 'amount' => 10, 'data' => ['direction' => 'out', 'voucher' => 'V1']])->assertSessionHasErrors('data.voucher');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'entries']), [...$payout, 'amount' => 20])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Cash on hand')->assertSee('50.00');

        $entry = Record::query()->where('entity', 'entries')->where('title', 'Taxi')->firstOrFail();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'entries', $entry->id, 'count']), ['counted' => 45])->assertRedirect();
        $this->assertDatabaseHas('records', ['entity' => 'entries', 'title' => 'Cash shortage', 'amount' => 5]);
        $this->assertSame(0, Record::query()->where('entity', 'entries')->where('status', 'recorded')->whereDate('occurs_on', '<', today())->count());
        $this->assertSame('reconciled', $entry->fresh()->status);

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'entries', $entry->id]), [...$payout, 'status' => 'reconciled', 'amount' => 25])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Cash book')->assertSee('Opening balance')->assertSee('Payouts by category');
    }

    public function test_fixed_assets_depreciate_straight_line_and_flag_warranties(): void
    {
        $app = 'fixed-assets';
        [$owner, $workspace] = $this->appWorkspace($app);
        $laptop = $this->record($workspace, $app, 'assets', 'Dell laptop', 'in_use', ['asset_tag' => 'IT-001', 'category' => 'computers', 'useful_life_years' => 1, 'custodian' => $owner->id],
            ['amount' => 1200, 'occurs_on' => today()->subMonths(3), 'due_on' => today()->addDays(10)]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'assets']), [
            'title' => 'Copy', 'status' => 'in_use', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subYear()->toDateString(), 'data' => ['asset_tag' => 'IT-001'],
        ])->assertSessionHasErrors(['data.asset_tag', 'due_on']);

        $this->actingAs($owner)->get($laptop->url())->assertOk()->assertSee('Depreciation')->assertSee('100.00')->assertSee('900.00')->assertSee('Warranty ending');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Asset register')->assertSee('Warranties ending')->assertSee('IT-001');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Depreciation schedule')->assertSee('Who holds what')->assertSee($owner->name);
    }

    public function test_loans_schedule_instalments_track_arrears_and_close_when_repaid(): void
    {
        $app = 'loans';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'loans']), ['title' => 'No terms', 'status' => 'disbursed', 'data' => ['product' => 'personal']])
            ->assertSessionHasErrors(['amount', 'data.term_months', 'occurs_on']);

        $loan = $this->record($workspace, $app, 'loans', 'Agnes Chirwa', 'disbursed', ['product' => 'business', 'interest_rate' => 5, 'term_months' => 6],
            ['amount' => 1200, 'occurs_on' => today()->subMonths(3)->subDay()]);
        $this->assertEquals(1560, $loan->value('_total_due'));
        $this->assertEquals(260, $loan->value('_instalment'));
        $this->assertEquals(780, $loan->value('_arrears'));
        $this->assertSame('in_arrears', $loan->status);
        $this->assertSame(today()->subMonths(3)->subDay()->addMonthsNoOverflow(6)->toDateString(), $loan->due_on->toDateString());

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Portfolio at risk')->assertSee('100%')->assertSee('Agnes Chirwa');

        $payment = ['title' => 'RCPT-1', 'status' => 'received', 'amount' => 780, 'occurs_on' => today()->toDateString(), 'data' => ['loan' => $loan->id, 'method' => 'cash']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'repayments']), $payment)->assertSessionHasNoErrors();
        $loan = $loan->fresh();
        $this->assertSame('disbursed', $loan->status);
        $this->assertEquals(780, $loan->value('_balance'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'repayments']), [...$payment, 'amount' => 900])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'repayments']), $payment)->assertSessionHasNoErrors();
        $this->assertSame('repaid', $loan->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'repayments']), [...$payment, 'amount' => 10])->assertSessionHasErrors('data.loan');

        $this->actingAs($owner)->get(route('apps.records.document', [$app, 'loans', $loan->id, 'statement']))->assertOk()->assertSee('Loan statement')->assertSee('Instalment 6')->assertSee('1,560.00');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Loan book')->assertSee('Disbursed and collected');

        $late = $this->record($workspace, $app, 'loans', 'Late payer', 'disbursed', ['product' => 'personal', 'interest_rate' => 0, 'term_months' => 2], ['amount' => 200, 'occurs_on' => today()->subMonth()]);
        $this->assertSame('in_arrears', $late->status);
        $late->updateQuietly(['status' => 'disbursed']);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('in_arrears', $late->fresh()->status);
    }

    public function test_insurance_checks_cover_and_sum_insured_and_lapses_unrenewed_policies(): void
    {
        $app = 'insurance';
        [$owner, $workspace] = $this->appWorkspace($app);
        $policy = $this->record($workspace, $app, 'policies', 'POL-MV-1', 'active', ['insurer' => 'NICO', 'class' => 'motor', 'sum_insured' => 5000],
            ['amount' => 400, 'occurs_on' => today()->subMonths(11), 'due_on' => today()->addDays(20)]);
        $quoted = $this->record($workspace, $app, 'policies', 'POL-Q-1', 'quoted', ['insurer' => 'NICO']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'policies']), ['title' => 'POL-MV-1', 'status' => 'active', 'data' => ['insurer' => 'Old Mutual']])
            ->assertSessionHasErrors(['title', 'occurs_on', 'due_on']);

        $claim = ['title' => 'Rear-end collision', 'status' => 'reported', 'amount' => 3000, 'data' => ['policy' => $policy->id, 'incident_date' => today()->subMonths(12)->toDateString()]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'claims']), $claim)->assertSessionHasErrors('data.incident_date');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'claims']), [...$claim, 'data' => ['policy' => $quoted->id]])->assertSessionHasErrors('data.policy');

        $this->record($workspace, $app, 'claims', 'Windscreen', 'paid', ['policy' => $policy->id, 'incident_date' => today()->subMonth()->toDateString()], ['amount' => 600]);
        $this->assertEquals(150.0, $policy->fresh()->value('_loss_ratio'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'claims']), [...$claim, 'status' => 'approved', 'amount' => 4500, 'data' => ['policy' => $policy->id, 'incident_date' => today()->subWeek()->toDateString()]])
            ->assertSessionHasErrors('amount');

        $this->actingAs($owner)->get($policy->url())->assertOk()->assertSee('Loss ratio')->assertSee('150%')->assertSee('Renewal due');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Renewals due')->assertSee('POL-MV-1');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'policies', $policy->id, 'renew']), ['premium' => 450])->assertRedirect();
        $renewed = $policy->fresh();
        $this->assertSame(today()->addDays(20)->addYear()->toDateString(), $renewed->due_on->toDateString());
        $this->assertEquals(450, (float) $renewed->amount);

        $old = $this->record($workspace, $app, 'policies', 'POL-OLD', 'active', ['insurer' => 'NICO'], ['amount' => 100, 'occurs_on' => today()->subYear()->subWeek(), 'due_on' => today()->subWeek()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('lapsed', $old->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Book by insurer')->assertSee('NICO')->assertSee('Claims by status');
    }

    public function test_recruitment_stops_duplicates_and_fills_the_vacancy_when_positions_are_hired(): void
    {
        $app = 'recruitment';
        [$owner, $workspace] = $this->appWorkspace($app);
        $vacancy = $this->record($workspace, $app, 'vacancies', 'Accountant', 'open', ['department' => 'Finance', 'positions' => 1], ['due_on' => today()->addWeeks(2)]);
        $first = $this->record($workspace, $app, 'candidates', 'Ruth Gondwe', 'interview', ['vacancy' => $vacancy->id, 'email' => 'ruth@example.com'], ['occurs_on' => today()->subDays(10)]);
        $second = $this->record($workspace, $app, 'candidates', 'Ben Kumwenda', 'offer', ['vacancy' => $vacancy->id, 'email' => 'ben@example.com']);
        $this->assertEquals(2, $vacancy->fresh()->value('_applicants'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'candidates']), ['title' => 'Again', 'status' => 'applied', 'data' => ['vacancy' => $vacancy->id, 'email' => 'ruth@example.com']])
            ->assertSessionHasErrors('data.email');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Open vacancies')->assertSee('Accountant');

        $first->update(['status' => 'hired']);
        $this->assertSame('filled', $vacancy->fresh()->status);
        $this->assertNotNull($first->fresh()->value('_hired_on'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'candidates', $second->id]), ['title' => $second->title, 'status' => 'hired', 'data' => $second->data])
            ->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'candidates']), ['title' => 'Late', 'status' => 'applied', 'data' => ['vacancy' => $vacancy->id]])
            ->assertSessionHasErrors('data.vacancy');

        $this->actingAs($owner)->get($vacancy->url())->assertOk()->assertSee('Pipeline');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Funnel by vacancy')->assertSee('Time to hire')->assertSee('Ruth Gondwe')->assertSee('10 days');
    }

    public function test_leave_caps_annual_days_blocks_overlaps_and_marks_past_leave_taken(): void
    {
        $app = 'leave';
        [$owner, $workspace] = $this->appWorkspace($app);
        $start = today()->startOfYear()->addMonths(2);
        $this->record($workspace, $app, 'requests', 'Chikondi Banda', 'approved', ['type' => 'annual', 'days' => 15], ['occurs_on' => $start, 'due_on' => $start->copy()->addDays(20)]);

        $request = ['title' => 'Chikondi Banda', 'status' => 'requested', 'occurs_on' => $start->copy()->addDays(5)->toDateString(), 'due_on' => $start->copy()->addDays(6)->toDateString(), 'data' => ['type' => 'sick', 'days' => 2]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), $request)->assertSessionHasErrors('occurs_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), [...$request, 'due_on' => $start->copy()->addDays(4)->toDateString()])->assertSessionHasErrors('due_on');

        $later = $start->copy()->addMonths(4);
        $annual = [...$request, 'occurs_on' => $later->toDateString(), 'due_on' => $later->copy()->addDays(9)->toDateString(), 'data' => ['type' => 'annual', 'days' => 8]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), $annual)->assertSessionHasErrors('data.days');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), [...$annual, 'data' => ['type' => 'annual', 'days' => 12]])->assertSessionHasErrors('data.days');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), [...$annual, 'data' => ['type' => 'annual', 'days' => 6]])->assertSessionHasNoErrors();

        $waiting = Record::query()->where('entity', 'requests')->where('status', 'requested')->firstOrFail();
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting for a decision')->assertSee('Chikondi Banda');
        $this->actingAs($owner)->get($waiting->url())->assertOk()->assertSee('Annual leave')->assertSee('21 days');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'requests', $waiting->id, 'approve']))->assertRedirect();
        $this->assertSame('approved', $waiting->fresh()->status);

        $past = $this->record($workspace, $app, 'requests', 'James Phiri', 'approved', ['type' => 'family', 'days' => 1], ['occurs_on' => today()->subDays(3), 'due_on' => today()->subDays(3)]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('taken', $past->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Annual leave balances')->assertSee('Days by type');
    }

    public function test_projects_track_milestone_progress_and_guard_the_budget(): void
    {
        $app = 'projects';
        [$owner, $workspace] = $this->appWorkspace($app);
        $project = $this->record($workspace, $app, 'projects', 'Website rebuild', 'planning', ['priority' => 'high'], ['amount' => 1000, 'due_on' => today()->addMonth()]);
        $design = $this->record($workspace, $app, 'milestones', 'Design', 'in_progress', ['project' => $project->id], ['amount' => 400, 'due_on' => today()->subDay()]);
        $this->assertSame('active', $project->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'milestones']), ['title' => 'Build', 'status' => 'pending', 'amount' => 700, 'data' => ['project' => $project->id]])
            ->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'milestones']), ['title' => 'Build', 'status' => 'pending', 'amount' => 600, 'data' => ['project' => $project->id]])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Overdue milestones')->assertSee('Design')->assertSee('Website rebuild');

        $design->update(['status' => 'done']);
        $project = $project->fresh();
        $this->assertEquals(50, $project->value('_progress'));
        $this->assertEquals(400, $project->value('_billed'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'projects', $project->id]), ['title' => $project->title, 'status' => 'completed', 'amount' => 1000, 'data' => $project->data])
            ->assertSessionHasErrors('status');
        $closed = $this->record($workspace, $app, 'projects', 'Old job', 'cancelled');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'milestones']), ['title' => 'Extra', 'status' => 'pending', 'data' => ['project' => $closed->id]])
            ->assertSessionHasErrors('data.project');

        $this->actingAs($owner)->get($project->url())->assertOk()->assertSee('Progress')->assertSee('50% of 2');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Project status')->assertSee('Milestones due');
    }
}
