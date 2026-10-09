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

/** The money-business apps' rules: credit scoring, accounting practices, audits, premium finance, forex, agent float, pawnshops, investment clubs and household budgets. */
class FinanceAppsBatchThreeTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @param  list<string>  $extra  @return array{0: User, 1: Workspace} */
    protected function appWorkspace(string $app, array $extra = []): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app, ...$extra], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /** @param  array<string, mixed>  $data  @param  array<string, mixed>  $attributes */
    protected function record(Workspace $workspace, string $app, string $entity, string $title, string $status, array $data = [], array $attributes = []): Record
    {
        return Record::factory()->ofEntity($app, $entity, $data)->create(['workspace_id' => $workspace->id, 'title' => $title, 'status' => $status, ...$attributes]);
    }

    public function test_credit_scoring_bands_scores_and_blocks_risky_approvals(): void
    {
        [$owner, $workspace] = $this->appWorkspace('credit-scoring');

        $this->actingAs($owner)->post(route('apps.records.store', ['credit-scoring', 'applicants']), [
            'title' => 'Chipo Banda', 'status' => 'verified', 'data' => ['id_number' => '63-123456', 'id_verified' => 1],
        ])->assertSessionHasErrors('status');

        $applicant = $this->record($workspace, 'credit-scoring', 'applicants', 'Chipo Banda', 'pending', ['id_number' => '63-123456', 'monthly_income' => 1000]);
        $assessment = $this->record($workspace, 'credit-scoring', 'assessments', 'Assessment', 'in_progress', ['applicant' => $applicant->id, 'score' => 720]);
        $this->assertSame('good', $assessment->value('_band'));
        $this->assertSame(2000.0, (float) $assessment->amount);

        $approve = ['title' => 'Assessment', 'status' => 'approved', 'amount' => 2000, 'data' => ['applicant' => $applicant->id, 'score' => 720, 'aml_result' => 'possible_match']];
        $this->actingAs($owner)->put(route('apps.records.update', ['credit-scoring', 'assessments', $assessment->id]), $approve)->assertSessionHasErrors(['data.applicant', 'data.aml_result']);
        $this->actingAs($owner)->post(route('apps.records.store', ['credit-scoring', 'assessments']), [...$approve, 'data' => [...$approve['data'], 'score' => 1200]])->assertSessionHasErrors('data.score');

        $this->actingAs($owner)->get(route('apps.show', 'credit-scoring'))->assertOk()->assertSee('Awaiting a decision')->assertSee('Chipo Banda');

        $applicant->update(['status' => 'verified', 'data' => array_merge($applicant->data, ['id_verified' => true, 'proof_of_address' => true])]);
        $this->actingAs($owner)->put(route('apps.records.update', ['credit-scoring', 'assessments', $assessment->id]), [...$approve, 'data' => [...$approve['data'], 'aml_result' => 'clear']])->assertSessionHasNoErrors();
        $this->assertSame('approved', $assessment->fresh()->status);

        $this->actingAs($owner)->get($assessment->url())->assertOk()->assertSee('Recommended limit');
        $this->actingAs($owner)->get(route('apps.reports', 'credit-scoring'))->assertOk()->assertSee('Decisions by risk band')->assertSee('AML screening results');
    }

    public function test_accounting_practice_tracks_filings_and_rolls_them_forward(): void
    {
        [$owner, $workspace] = $this->appWorkspace('accounting-practice');
        $client = $this->record($workspace, 'accounting-practice', 'clients', 'Moyo Hardware', 'active', ['entity_type' => 'company'], ['amount' => 250]);

        $late = $this->record($workspace, 'accounting-practice', 'deadlines', 'VAT', 'upcoming', ['client' => $client->id, 'type' => 'vat_return'], ['due_on' => today()->subDay()]);
        $this->assertSame('overdue', $late->fresh()->status);

        $filing = ['title' => 'VAT', 'status' => 'filed', 'due_on' => today()->addDays(5)->toDateString(), 'data' => ['client' => $client->id, 'type' => 'vat_return']];
        $this->actingAs($owner)->post(route('apps.records.store', ['accounting-practice', 'deadlines']), $filing)->assertSessionHasErrors('data.submission_reference');
        $this->actingAs($owner)->put(route('apps.records.update', ['accounting-practice', 'deadlines', $late->id]), [...$filing, 'data' => [...$filing['data'], 'submission_reference' => 'ZRA-991']])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('apps.records.action', ['accounting-practice', 'deadlines', $late->id, 'roll_forward']))->assertSessionHasNoErrors();
        $next = Record::query()->ofEntity('accounting-practice', 'deadlines')->whereKeyNot($late->id)->firstOrFail();
        $this->assertSame('upcoming', $next->status);
        $this->assertSame(today()->addDays(5)->addMonthNoOverflow()->toDateString(), $next->due_on->toDateString());
        $this->assertSame($client->id, (int) $next->value('client'));

        $letter = $this->record($workspace, 'accounting-practice', 'engagements', 'Bookkeeping', 'signed', ['client' => $client->id], ['due_on' => today()->addDay()]);
        Record::query()->whereKey($letter->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => 'accounting-practice']);
        $this->assertSame('expired', $letter->fresh()->status);

        $this->actingAs($owner)->get($client->url())->assertOk()->assertSee('Filings due')->assertSee('Engagement letter');
        $this->actingAs($owner)->get(route('apps.show', 'accounting-practice'))->assertOk()->assertSee('Filings due in the next 14 days')->assertSee('Moyo Hardware');
        $this->actingAs($owner)->get(route('apps.reports', 'accounting-practice'))->assertOk()->assertSee('Filings by type')->assertSee('Fee book');
    }

    public function test_audit_cannot_be_reported_until_papers_clear_and_high_risks_close(): void
    {
        [$owner, $workspace] = $this->appWorkspace('audit-working-papers');
        $engagement = $this->record($workspace, 'audit-working-papers', 'engagements', 'Moyo Ltd FY2026', 'fieldwork', ['year_end' => '2026-06-30', 'materiality' => 5000]);
        $paper = $this->record($workspace, 'audit-working-papers', 'papers', 'Revenue', 'prepared', ['engagement' => $engagement->id, 'reference' => 'C1']);
        $finding = $this->record($workspace, 'audit-working-papers', 'findings', 'No bank reconciliations', 'open', ['engagement' => $engagement->id, 'risk' => 'high']);

        $clear = ['title' => 'Revenue', 'status' => 'cleared', 'data' => ['engagement' => $engagement->id, 'reference' => 'C1']];
        $this->actingAs($owner)->put(route('apps.records.update', ['audit-working-papers', 'papers', $paper->id]), $clear)->assertSessionHasErrors('data.conclusion');
        $this->actingAs($owner)->post(route('apps.records.store', ['audit-working-papers', 'papers']), [...$clear, 'status' => 'prepared'])->assertSessionHasErrors('data.reference');

        $report = ['title' => 'Moyo Ltd FY2026', 'status' => 'reported', 'data' => ['year_end' => '2026-06-30']];
        $this->actingAs($owner)->put(route('apps.records.update', ['audit-working-papers', 'engagements', $engagement->id]), $report)->assertSessionHasErrors('status');

        $this->actingAs($owner)->put(route('apps.records.update', ['audit-working-papers', 'papers', $paper->id]), [...$clear, 'data' => [...$clear['data'], 'conclusion' => 'Revenue fairly stated.']])->assertSessionHasNoErrors();
        $this->actingAs($owner)->put(route('apps.records.update', ['audit-working-papers', 'engagements', $engagement->id]), $report)->assertSessionHasErrors('status');

        $finding->update(['status' => 'agreed']);
        $this->actingAs($owner)->get($engagement->url())->assertOk()->assertSee('Audit progress')->assertSee('1 of 1 (100%)');
        $this->actingAs($owner)->put(route('apps.records.update', ['audit-working-papers', 'engagements', $engagement->id]), $report)->assertSessionHasNoErrors();
        $this->assertSame('reported', $engagement->fresh()->status);

        $engagement->update(['status' => 'archived']);
        $this->actingAs($owner)->post(route('apps.records.store', ['audit-working-papers', 'papers']), ['title' => 'Late paper', 'status' => 'not_started', 'data' => ['engagement' => $engagement->id, 'reference' => 'Z9']])
            ->assertSessionHasErrors('data.engagement');
        $this->actingAs($owner)->get(route('apps.reports', 'audit-working-papers'))->assertOk()->assertSee('Engagement progress')->assertSee('No bank reconciliations');
    }

    public function test_premium_finance_spreads_the_premium_and_tracks_arrears(): void
    {
        [$owner, $workspace] = $this->appWorkspace('insurance-premium-financing');
        $insured = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Farai Transport']);
        $agreement = $this->record($workspace, 'insurance-premium-financing', 'agreements', 'Fleet cover', 'active',
            ['insurer' => 'Old Mutual', 'policy_number' => 'P-77', 'premium' => 1000, 'interest_rate' => 10, 'instalments' => 10],
            ['contact_id' => $insured->id, 'occurs_on' => today()->subMonths(2)]);

        $this->assertSame(1100.0, (float) $agreement->amount);
        $this->assertSame(110.0, (float) $agreement->value('_instalment'));
        $this->assertSame(330.0, (float) $agreement->value('_arrears'));
        $this->assertSame('in_arrears', $agreement->status);
        $this->actingAs($owner)->get(route('apps.show', 'insurance-premium-financing'))->assertOk()->assertSee('Agreements in arrears')->assertSee('Farai Transport');

        $collection = ['title' => 'Debit order', 'status' => 'received', 'occurs_on' => today()->toDateString(), 'data' => ['agreement' => $agreement->id, 'method' => 'debit_order']];
        $this->actingAs($owner)->post(route('apps.records.store', ['insurance-premium-financing', 'collections']), [...$collection, 'amount' => 2000])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', ['insurance-premium-financing', 'collections']), [...$collection, 'amount' => 330])->assertSessionHasNoErrors();

        $agreement->refresh();
        $this->assertSame('active', $agreement->status);
        $this->assertSame(330.0, (float) $agreement->value('_paid'));
        $this->actingAs($owner)->post(route('apps.records.store', ['insurance-premium-financing', 'collections']), [...$collection, 'amount' => 770])->assertSessionHasNoErrors();
        $this->assertSame('completed', $agreement->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', ['insurance-premium-financing', 'collections']), [...$collection, 'amount' => 10])->assertSessionHasErrors('data.agreement');

        $fresh = $this->record($workspace, 'insurance-premium-financing', 'agreements', 'Home cover', 'active',
            ['insurer' => 'Nicoz', 'policy_number' => 'H-1', 'premium' => 600, 'instalments' => 6], ['occurs_on' => today()->addDay()]);
        $this->assertSame('active', $fresh->status);
        Record::query()->whereKey($fresh->id)->update(['occurs_on' => today()->subMonth()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => 'insurance-premium-financing']);
        $this->assertSame('in_arrears', $fresh->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', 'insurance-premium-financing'))->assertOk()->assertSee('Instalments collected')->assertSee('Nicoz');
    }

    public function test_forex_rates_supersede_and_deals_work_out_margin(): void
    {
        [$owner, $workspace] = $this->appWorkspace('forex-bureau-money-transfer');

        $this->actingAs($owner)->post(route('apps.records.store', ['forex-bureau-money-transfer', 'rates']), [
            'title' => 'USD/ZWG', 'status' => 'current', 'data' => ['buy_rate' => 27, 'sell_rate' => 25],
        ])->assertSessionHasErrors('data.sell_rate');

        $old = $this->record($workspace, 'forex-bureau-money-transfer', 'rates', 'USD/ZWG', 'current', ['buy_rate' => 25, 'sell_rate' => 27]);
        $this->record($workspace, 'forex-bureau-money-transfer', 'rates', 'usd / zwg', 'current', ['buy_rate' => 26, 'sell_rate' => 28]);
        $this->assertSame('superseded', $old->fresh()->status);

        $deal = ['title' => 'Walk-in', 'status' => 'completed', 'occurs_on' => today()->toDateString(), 'data' => ['direction' => 'sell', 'currency' => 'usd', 'foreign_amount' => 100, 'rate' => 27.5]];
        $this->actingAs($owner)->post(route('apps.records.store', ['forex-bureau-money-transfer', 'deals']), $deal)->assertSessionHasErrors('data.id_number');
        $this->actingAs($owner)->post(route('apps.records.store', ['forex-bureau-money-transfer', 'deals']), [...$deal, 'data' => [...$deal['data'], 'id_number' => '08-1234']])->assertSessionHasNoErrors();

        $created = Record::query()->ofEntity('forex-bureau-money-transfer', 'deals')->firstOrFail();
        $this->assertSame(2750.0, (float) $created->amount);
        $this->assertSame(50.0, (float) $created->value('_margin'));

        $transfer = $this->record($workspace, 'forex-bureau-money-transfer', 'transfers', 'Tatenda', 'sent', ['receiver' => 'Rumbi', 'fee' => 5], ['amount' => 100]);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $transfer->value('secret_code'));
        $this->assertSame(105.0, (float) $transfer->value('_total'));
        $transfer->update(['status' => 'cancelled']);
        $this->actingAs($owner)->put(route('apps.records.update', ['forex-bureau-money-transfer', 'transfers', $transfer->id]), [
            'title' => 'Tatenda', 'status' => 'paid_out', 'amount' => 100, 'data' => ['receiver' => 'Rumbi'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.show', 'forex-bureau-money-transfer'))->assertOk()->assertSee('Board rates')->assertSee('Buy 26');
        $this->actingAs($owner)->get(route('apps.reports', 'forex-bureau-money-transfer'))->assertOk()->assertSee('Currency position')->assertSee('USD')->assertSee('50.00');
    }

    public function test_agent_float_keeps_both_balances_and_reconciles_counts(): void
    {
        [$owner, $workspace] = $this->appWorkspace('mobile-money-agent-float-management');
        $outlet = $this->record($workspace, 'mobile-money-agent-float-management', 'outlets', 'Mbare kiosk', 'active', ['agent_number' => 'A-55', 'network' => 'ecocash', 'float_limit' => 1000]);
        $this->record($workspace, 'mobile-money-agent-float-management', 'floats', 'Top-up', 'recorded', ['outlet' => $outlet->id, 'type' => 'top_up'], ['amount' => 800]);

        $store = fn (string $type, float $amount, array $extra = [], string $status = 'recorded') => $this->actingAs($owner)->post(route('apps.records.store', ['mobile-money-agent-float-management', 'floats']), [
            'title' => $type, 'status' => $status, 'amount' => $amount, 'occurs_on' => today()->toDateString(), 'data' => ['outlet' => $outlet->id, 'type' => $type, ...$extra],
        ]);

        $store('cash_in', 900)->assertSessionHasErrors('amount');
        $store('cash_in', 300)->assertSessionHasNoErrors();
        $store('cash_out', 400)->assertSessionHasErrors('amount');
        $store('top_up', 600)->assertSessionHasErrors('amount');
        $store('commission', 10, ['closing_float' => 510, 'closing_cash' => 250], 'reconciled')->assertSessionHasErrors('status');
        $store('commission', 10, ['closing_float' => 510, 'closing_cash' => 300], 'reconciled')->assertSessionHasNoErrors();

        $outlet->refresh();
        $this->assertSame(510.0, (float) $outlet->value('_float'));
        $this->assertSame(300.0, (float) $outlet->value('_cash'));
        $this->assertFalse($outlet->appLogic()->isLow($outlet));

        $this->record($workspace, 'mobile-money-agent-float-management', 'floats', 'Busy day', 'recorded', ['outlet' => $outlet->id, 'type' => 'cash_in'], ['amount' => 400]);
        $this->assertTrue($outlet->appLogic()->isLow($outlet->fresh()));

        $this->actingAs($owner)->get($outlet->url())->assertOk()->assertSee('Cash in drawer')->assertSee('700.00');
        $this->actingAs($owner)->get(route('apps.show', 'mobile-money-agent-float-management'))->assertOk()->assertSee('Outlet balances')->assertSee('Mbare kiosk');
        $this->actingAs($owner)->get(route('apps.reports', 'mobile-money-agent-float-management'))->assertOk()->assertSee('Movements by outlet')->assertSee('Commission by month');
    }

    public function test_pawnshop_caps_loans_charges_interest_and_forfeits_late_pledges(): void
    {
        [$owner, $workspace] = $this->appWorkspace('pawnshop-collateral-lending');

        $this->actingAs($owner)->post(route('apps.records.store', ['pawnshop-collateral-lending', 'pledges']), [
            'title' => 'Gold ring', 'status' => 'pledged', 'amount' => 800, 'occurs_on' => today()->toDateString(), 'data' => ['category' => 'jewellery', 'valuation' => 1000],
        ])->assertSessionHasErrors('amount');

        $pledge = $this->record($workspace, 'pawnshop-collateral-lending', 'pledges', 'Gold ring', 'pledged', ['category' => 'jewellery', 'valuation' => 1000, 'interest_rate' => 10], ['amount' => 500, 'occurs_on' => today()->subDays(40)]);
        $this->assertSame(today()->subDays(10)->toDateString(), $pledge->due_on->toDateString());
        $this->assertSame(100.0, (float) $pledge->value('_interest'));
        $this->assertSame(600.0, (float) $pledge->value('_owed'));

        $payment = fn (string $type, float $amount) => $this->actingAs($owner)->post(route('apps.records.store', ['pawnshop-collateral-lending', 'payments']), [
            'title' => $type, 'status' => 'received', 'amount' => $amount, 'occurs_on' => today()->toDateString(), 'data' => ['pledge' => $pledge->id, 'type' => $type],
        ]);
        $payment('redemption', 300)->assertSessionHasErrors('amount');
        $payment('part_payment', 700)->assertSessionHasErrors('amount');
        $payment('interest', 100)->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('apps.records.action', ['pawnshop-collateral-lending', 'pledges', $pledge->id, 'extend']))->assertSessionHasNoErrors();
        $pledge->refresh();
        $this->assertSame('extended', $pledge->status);
        $this->assertSame(today()->addDays(20)->toDateString(), $pledge->due_on->toDateString());

        $this->actingAs($owner)->get(route('apps.records.document', ['pawnshop-collateral-lending', 'pledges', $pledge->id, 'ticket']))->assertOk()->assertSee('Pawn ticket')->assertSee('Gold ring');
        $payment('redemption', 500)->assertSessionHasNoErrors();
        $this->assertSame('redeemed', $pledge->fresh()->status);
        $payment('interest', 10)->assertSessionHasErrors('data.pledge');

        $phone = $this->record($workspace, 'pawnshop-collateral-lending', 'pledges', 'Phone', 'pledged', ['category' => 'electronics', 'valuation' => 200], ['amount' => 100, 'occurs_on' => today()->subDays(60), 'due_on' => today()->subDays(30)]);
        $this->actingAs($owner)->put(route('apps.records.update', ['pawnshop-collateral-lending', 'pledges', $phone->id]), [
            'title' => 'Phone', 'status' => 'sold', 'amount' => 100, 'data' => ['category' => 'electronics', 'valuation' => 200],
        ])->assertSessionHasErrors('status');
        Artisan::call('zonseo:run-app-schedules', ['--app' => 'pawnshop-collateral-lending']);
        $this->assertSame('forfeited', $phone->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', 'pawnshop-collateral-lending'))->assertOk()->assertSee('Pledges by category')->assertSee('Payments received');
    }

    public function test_investment_club_raises_to_target_and_shares_distributions(): void
    {
        [$owner, $workspace] = $this->appWorkspace('investment-clubs-crowdfunding-of');
        $alice = $this->record($workspace, 'investment-clubs-crowdfunding-of', 'members', 'Alice', 'active');
        $bob = $this->record($workspace, 'investment-clubs-crowdfunding-of', 'members', 'Bob', 'active');
        $gone = $this->record($workspace, 'investment-clubs-crowdfunding-of', 'members', 'Carl', 'exited');
        $venture = $this->record($workspace, 'investment-clubs-crowdfunding-of', 'ventures', 'Poultry farm', 'raising', ['target' => 1000]);

        $contribute = fn (Record $investor, float $amount) => $this->actingAs($owner)->post(route('apps.records.store', ['investment-clubs-crowdfunding-of', 'contributions']), [
            'title' => 'Contribution', 'status' => 'received', 'amount' => $amount, 'occurs_on' => today()->toDateString(), 'data' => ['investor' => $investor->id, 'venture' => $venture->id],
        ]);
        $contribute($alice, 600)->assertSessionHasNoErrors();
        $contribute($gone, 100)->assertSessionHasErrors('data.investor');
        $contribute($bob, 500)->assertSessionHasErrors('amount');
        $contribute($bob, 400)->assertSessionHasNoErrors();

        $venture->refresh();
        $this->assertSame(1000.0, (float) $venture->value('raised'));
        $this->assertSame('funded', $venture->status);
        $this->assertSame(600.0, (float) $alice->fresh()->amount);
        $contribute($alice, 50)->assertSessionHasErrors('data.venture');

        $distribution = $this->record($workspace, 'investment-clubs-crowdfunding-of', 'distributions', 'First dividend', 'paid', ['venture' => $venture->id, 'type' => 'dividend'], ['amount' => 100]);
        $this->assertEquals([$alice->id => 60.0, $bob->id => 40.0], (array) $distribution->value('_allocations'));
        $this->assertSame(60.0, $alice->appLogic()->returnsFor($alice));

        $this->actingAs($owner)->get($alice->url())->assertOk()->assertSee('Returns received')->assertSee('10%');
        $this->actingAs($owner)->get(route('apps.reports', 'investment-clubs-crowdfunding-of'))->assertOk()->assertSee('Poultry farm')->assertSee('Investors');
    }

    public function test_household_budgets_add_up_spending_in_their_category_and_month(): void
    {
        [$owner, $workspace] = $this->appWorkspace('personal-finance-household-budgeting');
        $month = today()->format('F Y');

        $this->actingAs($owner)->post(route('apps.records.store', ['personal-finance-household-budgeting', 'budgets']), [
            'title' => $month, 'status' => 'active', 'data' => ['category' => 'Food', 'limit' => 400],
        ])->assertSessionHasErrors('data.category');
        $this->actingAs($owner)->post(route('apps.records.store', ['personal-finance-household-budgeting', 'budgets']), [
            'title' => $month, 'status' => 'active', 'data' => ['category' => 'School fees', 'limit' => 400],
        ])->assertSessionHasNoErrors();

        $budget = Record::query()->ofEntity('personal-finance-household-budgeting', 'budgets')->firstOrFail();
        $this->assertSame('school_fees', $budget->value('category'));

        $transaction = fn (string $title, string $type, string $category, float $amount, $date = null) => $this->record($workspace, 'personal-finance-household-budgeting', 'transactions', $title, 'recorded',
            ['type' => $type, 'category' => $category], ['amount' => $amount, 'occurs_on' => $date ?? today()]);
        $transaction('Salary', 'income', 'salary', 1000);
        $first = $transaction('Term 1', 'expense', 'school_fees', 300);
        $transaction('Uniforms', 'expense', 'school_fees', 200);
        $transaction('Last term', 'expense', 'school_fees', 999, today()->subMonthNoOverflow()->startOfMonth());
        $transaction('Bread', 'expense', 'groceries', 50);

        $this->assertSame(500.0, (float) $budget->fresh()->value('spent'));
        $this->actingAs($owner)->get(route('apps.show', 'personal-finance-household-budgeting'))->assertOk()->assertSee('School fees is over budget')->assertSee('450.00');

        $first->delete();
        $this->assertSame(200.0, (float) $budget->fresh()->value('spent'));
        $this->actingAs($owner)->get(route('apps.reports', 'personal-finance-household-budgeting'))->assertOk()->assertSee('Spending by category')->assertSee('Groceries');
    }
}
