<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The specialist finance apps' rules: savings groups, subscriptions, gateways, job costing, consolidation, stock value, treasury and hire purchase. */
class FinanceAppsBatchTwoTest extends TestCase
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

    public function test_savings_group_limits_loans_to_savings_and_settles_repaid_loans(): void
    {
        [$owner, $workspace] = $this->appWorkspace('savings-groups');
        $member = $this->record($workspace, 'savings-groups', 'members', 'Rudo Moyo', 'active', ['member_number' => 'M1']);
        $this->record($workspace, 'savings-groups', 'contributions', 'Week 1', 'received', ['member' => $member->id, 'type' => 'savings'], ['amount' => 100, 'occurs_on' => today()]);
        $this->record($workspace, 'savings-groups', 'contributions', 'Shares', 'received', ['member' => $member->id, 'type' => 'shares'], ['amount' => 50, 'occurs_on' => today()]);
        $this->record($workspace, 'savings-groups', 'contributions', 'Missed', 'missed', ['member' => $member->id, 'type' => 'savings'], ['amount' => 100]);
        $meeting = $this->record($workspace, 'savings-groups', 'meetings', 'Weekly meeting', 'held', [], ['occurs_on' => today()]);

        $member->refresh();
        $this->assertSame(100.0, (float) $member->value('_savings'));
        $this->assertSame(50.0, (float) $member->value('_shares'));
        $this->assertSame(150.0, (float) $meeting->fresh()->amount);

        $loan = ['title' => 'School fees', 'status' => 'disbursed', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addMonths(2)->toDateString(), 'data' => ['member' => $member->id, 'interest_rate' => 5]];
        $this->actingAs($owner)->post(route('apps.records.store', ['savings-groups', 'loans']), [...$loan, 'amount' => 500])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', ['savings-groups', 'loans']), [...$loan, 'amount' => 400])->assertSessionHasNoErrors();

        $created = Record::query()->ofEntity('savings-groups', 'loans')->firstOrFail();
        $this->assertSame(440.0, (float) $created->value('_total_due'));
        $this->assertSame(10.0, $created->appLogic()->borrowingLimit($member));

        $created->update(['data' => array_merge($created->data, ['repaid' => 440])]);
        $this->assertSame('repaid', $created->fresh()->status);

        $late = $this->record($workspace, 'savings-groups', 'loans', 'Stock', 'disbursed', ['member' => $member->id], ['amount' => 50, 'occurs_on' => today()->subMonths(2), 'due_on' => today()->subDay()]);
        $this->assertSame('in_arrears', $late->fresh()->status);

        $this->actingAs($owner)->get($member->url())->assertOk()->assertSee('Member balances')->assertSee('Can still borrow');
        $this->actingAs($owner)->get(route('apps.records.document', ['savings-groups', 'members', $member->id, 'statement']))->assertOk()->assertSee('Member statement')->assertSee('150.00');
        $this->actingAs($owner)->get(route('apps.reports', 'savings-groups'))->assertOk()->assertSee('Loan book');
        $this->actingAs($owner)->get(route('apps.show', 'savings-groups'))->assertOk()->assertSee('Group fund');
    }

    public function test_subscriptions_bill_each_cycle_and_follow_overdue_invoices(): void
    {
        [$owner, $workspace] = $this->appWorkspace('recurring-billing');
        $this->assertTrue($workspace->hasModule('invoicing'));
        $customer = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Harare Gym']);
        $plan = $this->record($workspace, 'recurring-billing', 'plans', 'Pro', 'active', ['interval' => 'monthly', 'price' => 20]);
        $retired = $this->record($workspace, 'recurring-billing', 'plans', 'Legacy', 'retired', ['interval' => 'monthly', 'price' => 5]);

        $this->actingAs($owner)->post(route('apps.records.store', ['recurring-billing', 'subscriptions']), [
            'title' => 'Gym', 'status' => 'active', 'contact_id' => $customer->id, 'data' => ['plan' => $retired->id],
        ])->assertSessionHasErrors('data.plan');

        $subscription = $this->record($workspace, 'recurring-billing', 'subscriptions', 'Gym', 'trial', ['plan' => $plan->id, 'quantity' => 3], ['contact_id' => $customer->id, 'occurs_on' => today()->subMonths(2)]);
        $this->assertSame(60.0, (float) $subscription->amount);
        $this->assertSame(today()->subMonths(2)->toDateString(), $subscription->due_on->toDateString());

        Artisan::call('zonseo:run-app-schedules', ['--app' => 'recurring-billing']);

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertSame(3, $subscription->invoices()->count());
        $this->assertTrue($subscription->due_on->gt(today()));
        $this->assertSame(180.0, (float) $subscription->invoices()->sum('total'));

        // Running again bills nothing new.
        Artisan::call('zonseo:run-app-schedules', ['--app' => 'recurring-billing']);
        $this->assertSame(3, $subscription->invoices()->count());

        $invoice = $subscription->invoices()->firstOrFail();
        $invoice->update(['status' => 'overdue', 'due_date' => today()->subDay()]);
        $this->assertSame('past_due', $subscription->fresh()->status);

        $subscription->invoices()->update(['status' => 'paid']);
        $subscription->appLogic()->invoiceChanged($subscription->fresh(), $invoice->fresh());
        $this->assertSame('active', $subscription->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.action', ['recurring-billing', 'subscriptions', $subscription->id, 'bill_now']))->assertSessionHasNoErrors();
        $this->assertSame(4, $subscription->invoices()->count());

        $this->actingAs($owner)->get(route('apps.show', 'recurring-billing'))->assertOk()->assertSee('Monthly recurring revenue')->assertSee('60.00');
        $this->actingAs($owner)->get(route('apps.reports', 'recurring-billing'))->assertOk()->assertSee('Revenue by plan')->assertSee('Pro');
    }

    public function test_gateway_payments_work_out_fees_and_refuse_duplicates(): void
    {
        [$owner, $workspace] = $this->appWorkspace('payments');
        $paynow = $this->record($workspace, 'payments', 'gateways', 'Paynow', 'live', ['provider' => 'paynow', 'fee_percent' => 2.5]);
        $sandbox = $this->record($workspace, 'payments', 'gateways', 'Sandbox', 'test', ['provider' => 'stripe']);
        $old = $this->record($workspace, 'payments', 'gateways', 'Old', 'disabled', ['provider' => 'other']);

        $payment = ['title' => 'Order 1', 'status' => 'successful', 'amount' => 200, 'occurs_on' => today()->toDateString(), 'data' => ['gateway' => $paynow->id, 'gateway_reference' => 'PN-1']];
        $this->actingAs($owner)->post(route('apps.records.store', ['payments', 'transactions']), $payment)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', ['payments', 'transactions']), $payment)->assertSessionHasErrors('data.gateway_reference');
        $this->actingAs($owner)->post(route('apps.records.store', ['payments', 'transactions']), [...$payment, 'data' => ['gateway' => $old->id]])->assertSessionHasErrors('data.gateway');
        $this->actingAs($owner)->post(route('apps.records.store', ['payments', 'transactions']), [...$payment, 'status' => 'refunded', 'data' => ['gateway' => $paynow->id]])->assertSessionHasErrors('status');

        $logged = Record::query()->ofEntity('payments', 'transactions')->firstOrFail();
        $this->assertSame(5.0, (float) $logged->value('fee'));
        $this->assertSame(195.0, (float) $logged->value('_net'));

        $this->record($workspace, 'payments', 'transactions', 'Failed', 'failed', ['gateway' => $paynow->id], ['amount' => 50, 'occurs_on' => today()]);
        $this->record($workspace, 'payments', 'transactions', 'Test', 'successful', ['gateway' => $sandbox->id], ['amount' => 999, 'occurs_on' => today()]);

        $this->actingAs($owner)->get($paynow->url())->assertOk()->assertSee('Net settled')->assertSee('195.00')->assertSee('50%');
        $this->actingAs($owner)->get(route('apps.show', 'payments'))->assertOk()->assertSee('Net of fees');
        $this->actingAs($owner)->get(route('apps.reports', 'payments'))->assertOk()->assertSee('Collections by gateway')->assertSee('Paynow')->assertDontSee('Sandbox');
    }

    public function test_job_costing_tracks_costs_and_invoices_billable_ones(): void
    {
        [$owner, $workspace] = $this->appWorkspace('job-costing', ['invoicing']);
        $client = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'City Council']);
        $job = $this->record($workspace, 'job-costing', 'jobs', 'Office fit-out', 'in_progress', ['budget_cost' => 1000, 'quoted_price' => 1500], ['contact_id' => $client->id]);
        $closed = $this->record($workspace, 'job-costing', 'jobs', 'Old job', 'closed');

        $this->actingAs($owner)->post(route('apps.records.store', ['job-costing', 'costs']), [
            'title' => 'Paint', 'status' => 'recorded', 'amount' => 10, 'data' => ['job' => $closed->id, 'type' => 'materials'],
        ])->assertSessionHasErrors('data.job');

        $this->record($workspace, 'job-costing', 'costs', 'Labour', 'recorded', ['job' => $job->id, 'type' => 'labour'], ['amount' => 800, 'occurs_on' => today()]);
        $this->record($workspace, 'job-costing', 'costs', 'Boards', 'recorded', ['job' => $job->id, 'type' => 'materials', 'billable' => true], ['amount' => 300, 'occurs_on' => today()]);

        $job->refresh();
        $this->assertSame(1100.0, (float) $job->value('_actual_cost'));
        $this->assertSame(300.0, (float) $job->value('_unbilled'));
        $this->actingAs($owner)->get($job->url())->assertOk()->assertSee('Job cost')->assertSee('400.00')->assertSee('Invoice billable costs');
        $this->actingAs($owner)->get(route('apps.show', 'job-costing'))->assertOk()->assertSee('Jobs over budget')->assertSee('Office fit-out');

        $this->actingAs($owner)->post(route('apps.records.action', ['job-costing', 'jobs', $job->id, 'invoice_costs']))->assertSessionHasNoErrors();
        $invoice = Invoice::query()->where('contact_id', $client->id)->firstOrFail();
        $this->assertSame(300.0, (float) $invoice->total);
        $this->assertSame(0.0, (float) $job->fresh()->value('_unbilled'));
        $this->assertSame(1, Record::query()->ofEntity('job-costing', 'costs')->where('status', 'billed')->count());

        $this->actingAs($owner)->get(route('apps.reports', 'job-costing'))->assertOk()->assertSee('Job profitability')->assertSee('Costs by type');
    }

    public function test_consolidation_eliminates_matched_transactions_before_it_is_final(): void
    {
        [$owner, $workspace] = $this->appWorkspace('multi-entity-consolidation');
        $holdco = $this->record($workspace, 'multi-entity-consolidation', 'entities', 'Holdco', 'active', ['ownership_percent' => 100]);
        $subsidiary = $this->record($workspace, 'multi-entity-consolidation', 'entities', 'Subco', 'active', ['ownership_percent' => 70]);

        $this->actingAs($owner)->post(route('apps.records.store', ['multi-entity-consolidation', 'entities']), [
            'title' => 'Bad', 'status' => 'active', 'data' => ['ownership_percent' => 120],
        ])->assertSessionHasErrors('data.ownership_percent');
        $this->actingAs($owner)->post(route('apps.records.store', ['multi-entity-consolidation', 'intercompany']), [
            'title' => 'Loan', 'status' => 'recorded', 'amount' => 10, 'data' => ['from_entity' => $holdco->id, 'to_entity' => $holdco->id, 'type' => 'loan'],
        ])->assertSessionHasErrors('data.to_entity');

        $fee = $this->record($workspace, 'multi-entity-consolidation', 'intercompany', 'Management fee', 'matched', ['from_entity' => $subsidiary->id, 'to_entity' => $holdco->id, 'type' => 'management_fee'], ['amount' => 500, 'occurs_on' => today()->subDays(5)]);
        $loan = $this->record($workspace, 'multi-entity-consolidation', 'intercompany', 'Loan', 'recorded', ['from_entity' => $holdco->id, 'to_entity' => $subsidiary->id, 'type' => 'loan'], ['amount' => 1000, 'occurs_on' => today()->subDays(3)]);
        $run = $this->record($workspace, 'multi-entity-consolidation', 'consolidations', 'Q3', 'draft', [], ['occurs_on' => today()]);

        $finalise = ['title' => 'Q3', 'status' => 'final', 'occurs_on' => today()->toDateString(), 'data' => []];
        $this->actingAs($owner)->put(route('apps.records.update', ['multi-entity-consolidation', 'consolidations', $run->id]), $finalise)->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.action', ['multi-entity-consolidation', 'consolidations', $run->id, 'eliminate']))->assertSessionHasNoErrors();
        $this->assertSame('eliminated', $fee->fresh()->status);
        $this->assertSame(500.0, (float) $run->fresh()->value('_eliminated_total'));

        $loan->update(['status' => 'matched']);
        $this->actingAs($owner)->put(route('apps.records.update', ['multi-entity-consolidation', 'consolidations', $run->id]), $finalise)->assertSessionHasNoErrors();

        $this->actingAs($owner)->get($run->url())->assertOk()->assertSee('Eliminated by this run');
        $this->actingAs($owner)->get(route('apps.reports', 'multi-entity-consolidation'))->assertOk()->assertSee('Holdco → Subco')->assertSee('Minority interests')->assertSee('30%');
    }

    public function test_inventory_valuation_values_stock_and_adds_up_landed_costs(): void
    {
        [$owner, $workspace] = $this->appWorkspace('inventory-valuation-fifo-weighted', ['invoicing']);
        Item::query()->create(['workspace_id' => $workspace->id, 'type' => 'product', 'name' => 'Cement', 'price' => 12, 'cost' => 8, 'stock_qty' => 10, 'is_active' => true]);
        Item::query()->create(['workspace_id' => $workspace->id, 'type' => 'product', 'name' => 'Nails', 'price' => 2, 'cost' => 0.5, 'stock_qty' => 100, 'is_active' => true]);
        Item::query()->create(['workspace_id' => $workspace->id, 'type' => 'service', 'name' => 'Delivery', 'price' => 20, 'cost' => 5, 'is_active' => true]);

        $landed = $this->record($workspace, 'inventory-valuation-fifo-weighted', 'landed_costs', 'Container 7', 'draft', ['supplier_cost' => 1000, 'freight' => 150, 'duty' => 100, 'clearing' => 50]);
        $this->assertSame(1300.0, (float) $landed->amount);
        $this->assertSame(30.0, (float) $landed->value('_uplift'));

        $valuation = $this->record($workspace, 'inventory-valuation-fifo-weighted', 'valuations', 'October', 'draft', ['method' => 'weighted_average'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post(route('apps.records.action', ['inventory-valuation-fifo-weighted', 'valuations', $valuation->id, 'calculate']))->assertSessionHasNoErrors();

        $valuation->refresh();
        $this->assertSame(130.0, (float) $valuation->amount);
        $this->assertCount(2, $valuation->value('_lines'));
        $this->actingAs($owner)->get(route('apps.records.document', ['inventory-valuation-fifo-weighted', 'valuations', $valuation->id, 'stock_sheet']))->assertOk()
            ->assertSee('Stock valuation')->assertSee('Cement')->assertDontSee('Delivery')->assertSee('130.00');
        $this->actingAs($owner)->get(route('apps.reports', 'inventory-valuation-fifo-weighted'))->assertOk()->assertSee('Landed costs')->assertSee('Container 7');
    }

    public function test_treasury_works_out_returns_and_matures_investments(): void
    {
        [$owner, $workspace] = $this->appWorkspace('treasury');

        $this->actingAs($owner)->post(route('apps.records.store', ['treasury', 'investments']), [
            'title' => 'Bad dates', 'status' => 'active', 'amount' => 1000, 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(),
            'data' => ['type' => 'fixed_deposit', 'institution' => 'CBZ'],
        ])->assertSessionHasErrors('due_on');

        $deposit = $this->record($workspace, 'treasury', 'investments', '90-day deposit', 'active', ['type' => 'fixed_deposit', 'institution' => 'CBZ', 'interest_rate' => 10], ['amount' => 36500, 'occurs_on' => today()->subDays(10), 'due_on' => today()->addDays(355)]);
        $this->assertSame(3650.0, (float) $deposit->value('expected_return'));

        $bill = $this->record($workspace, 'treasury', 'investments', 'T-bill', 'active', ['type' => 'treasury_bill', 'institution' => 'RBZ', 'interest_rate' => 5], ['amount' => 1000, 'occurs_on' => today()->subDays(30), 'due_on' => today()->addDays(20)]);
        $bill->forceFill(['due_on' => today()->subDay()])->saveQuietly();
        Artisan::call('zonseo:run-app-schedules', ['--app' => 'treasury']);
        $this->assertSame('matured', $bill->fresh()->status);

        $position = $this->record($workspace, 'treasury', 'fx_positions', 'US dollars', 'open', ['currency_code' => 'usd', 'foreign_amount' => 100, 'rate' => 26.5]);
        $this->assertSame(2650.0, (float) $position->amount);

        $this->actingAs($owner)->get(route('apps.show', 'treasury'))->assertOk()->assertSee('Maturing within 30 days')->assertSee('T-bill');
        $this->actingAs($owner)->get(route('apps.reports', 'treasury'))->assertOk()->assertSee('Investment portfolio')->assertSee('USD');
    }

    public function test_hire_purchase_tracks_instalments_arrears_and_default(): void
    {
        [$owner, $workspace] = $this->appWorkspace('hire-purchase');
        $customer = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Tendai']);

        $this->actingAs($owner)->post(route('apps.records.store', ['hire-purchase', 'agreements']), [
            'title' => 'Fridge', 'status' => 'active', 'amount' => 500, 'data' => ['type' => 'hire_purchase', 'deposit' => 600],
        ])->assertSessionHasErrors('data.deposit');

        $fridge = $this->record($workspace, 'hire-purchase', 'agreements', 'Fridge', 'active', ['type' => 'hire_purchase', 'deposit' => 100, 'instalments' => 4], ['amount' => 500, 'contact_id' => $customer->id, 'occurs_on' => today()->subMonths(2)->subDay()]);
        $this->assertSame(100.0, (float) $fridge->value('instalment'));
        $this->assertSame(100.0, (float) $fridge->value('paid_to_date'));
        $this->assertSame(200.0, (float) $fridge->value('_arrears'));

        $payment = ['title' => 'Payment', 'status' => 'received', 'occurs_on' => today()->toDateString(), 'data' => ['agreement' => $fridge->id, 'method' => 'cash']];
        $this->actingAs($owner)->post(route('apps.records.store', ['hire-purchase', 'payments']), [...$payment, 'amount' => 450])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', ['hire-purchase', 'payments']), [...$payment, 'amount' => 200])->assertSessionHasNoErrors();

        $fridge->refresh();
        $this->assertSame(300.0, (float) $fridge->value('paid_to_date'));
        $this->assertSame(0.0, (float) $fridge->value('_arrears'));
        $this->actingAs($owner)->get(route('apps.records.document', ['hire-purchase', 'agreements', $fridge->id, 'statement']))->assertOk()->assertSee('Account statement')->assertSee('200.00');

        $this->actingAs($owner)->post(route('apps.records.store', ['hire-purchase', 'payments']), [...$payment, 'amount' => 200])->assertSessionHasNoErrors();
        $this->assertSame('completed', $fridge->fresh()->status);

        $stove = $this->record($workspace, 'hire-purchase', 'agreements', 'Stove', 'active', ['type' => 'lay_by', 'instalment' => 50, 'instalments' => 6], ['amount' => 300, 'occurs_on' => today()->subMonths(4)->subDay()]);
        $this->assertSame(200.0, (float) $stove->value('_arrears'));
        $this->actingAs($owner)->get(route('apps.show', 'hire-purchase'))->assertOk()->assertSee('Agreements in arrears')->assertSee('Stove');

        Artisan::call('zonseo:run-app-schedules', ['--app' => 'hire-purchase']);
        $this->assertSame('defaulted', $stove->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', 'hire-purchase'))->assertOk()->assertSee('Agreement book')->assertSee('Collections by month');
    }
}
