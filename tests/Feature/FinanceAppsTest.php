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
use Modules\Invoicing\Models\Payment;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The finance apps' rules: the ledger, bank reconciliation, payroll, budgets, tax, purchasing, debtors and creditors. */
class FinanceAppsTest extends TestCase
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

    public function test_accounting_posts_entries_and_reverses_them(): void
    {
        [$owner, $workspace] = $this->appWorkspace('accounting');
        $cash = $this->record($workspace, 'accounting', 'accounts', 'Cash', 'active', ['code' => '1000', 'type' => 'asset', 'opening_balance' => 100]);
        $sales = $this->record($workspace, 'accounting', 'accounts', 'Sales', 'active', ['code' => '4000', 'type' => 'income']);
        $rent = $this->record($workspace, 'accounting', 'accounts', 'Rent', 'active', ['code' => '6000', 'type' => 'expense']);
        $this->record($workspace, 'accounting', 'accounts', 'Capital', 'active', ['code' => '3000', 'type' => 'equity', 'opening_balance' => 100]);

        $this->actingAs($owner)->post(route('apps.records.store', ['accounting', 'accounts']), [
            'title' => 'Petty cash', 'status' => 'active', 'data' => ['code' => '1000', 'type' => 'asset'],
        ])->assertSessionHasErrors('data.code');

        $this->actingAs($owner)->post(route('apps.records.store', ['accounting', 'journals']), [
            'title' => 'Oops', 'status' => 'posted', 'amount' => 10, 'occurs_on' => today()->toDateString(),
            'data' => ['debit_account' => $cash->id, 'credit_account' => $cash->id],
        ])->assertSessionHasErrors('data.credit_account');

        $this->actingAs($owner)->post(route('apps.records.store', ['accounting', 'journals']), [
            'title' => 'Cash sales', 'status' => 'posted', 'amount' => 500, 'occurs_on' => today()->toDateString(),
            'data' => ['debit_account' => $cash->id, 'credit_account' => $sales->id],
        ])->assertSessionHasNoErrors();
        $rentEntry = $this->record($workspace, 'accounting', 'journals', 'October rent', 'posted', ['debit_account' => $rent->id, 'credit_account' => $cash->id], ['amount' => 200, 'occurs_on' => today()]);

        $logic = $cash->appLogic();
        $this->assertSame(['income' => 500.0, 'expense' => 200.0], $logic->profitAndLoss(today()->startOfYear(), today()));

        // A posted entry cannot be edited; it is reversed instead.
        $this->actingAs($owner)->put(route('apps.records.update', ['accounting', 'journals', $rentEntry->id]), [
            'title' => 'October rent', 'status' => 'posted', 'amount' => 250, 'occurs_on' => today()->toDateString(),
            'data' => ['debit_account' => $rent->id, 'credit_account' => $cash->id],
        ])->assertSessionHasErrors('amount');

        $this->actingAs($owner)->post(route('apps.records.action', ['accounting', 'journals', $rentEntry->id, 'reverse']))->assertSessionHasNoErrors();
        $this->assertSame('reversed', $rentEntry->fresh()->status);
        $reversal = Record::query()->ofEntity('accounting', 'journals')->where('data->reference', $rentEntry->number)->firstOrFail();
        $this->assertSame($cash->id, (int) $reversal->value('debit_account'));
        $this->assertSame(['income' => 500.0, 'expense' => 0.0], $logic->profitAndLoss(today()->startOfYear(), today()));

        $this->actingAs($owner)->get($cash->url())->assertOk()->assertSee('Account balance')->assertSee('600.00');
        $this->actingAs($owner)->get(route('apps.reports', 'accounting'))->assertOk()
            ->assertSee('Trial balance')->assertSee('The books balance.')->assertSee('Profit and loss')->assertSee('Balance sheet');
    }

    public function test_banking_reconciliation_must_agree_with_the_books(): void
    {
        [$owner, $workspace] = $this->appWorkspace('banking');
        $account = $this->record($workspace, 'banking', 'accounts', 'Current account', 'active', ['bank' => 'CBZ', 'account_number' => '123', 'opening_balance' => 100]);
        $in = $this->record($workspace, 'banking', 'transactions', 'Deposit', 'unreconciled', ['account' => $account->id, 'direction' => 'money_in'], ['amount' => 50, 'occurs_on' => today()->subDays(3)]);
        $this->record($workspace, 'banking', 'transactions', 'Bank fees', 'unreconciled', ['account' => $account->id, 'direction' => 'money_out'], ['amount' => 20, 'occurs_on' => today()->subDays(2)]);
        $this->record($workspace, 'banking', 'transactions', 'Unknown debit', 'queried', ['account' => $account->id, 'direction' => 'money_out'], ['amount' => 999, 'occurs_on' => today()->subDay()]);
        $later = $this->record($workspace, 'banking', 'transactions', 'Next week', 'unreconciled', ['account' => $account->id, 'direction' => 'money_in'], ['amount' => 5, 'occurs_on' => today()->addWeek()]);

        $this->actingAs($owner)->post(route('apps.records.store', ['banking', 'transactions']), [
            'title' => 'Zero', 'status' => 'unreconciled', 'amount' => 0, 'data' => ['account' => $account->id, 'direction' => 'money_in'],
        ])->assertSessionHasErrors('amount');

        $reconciliation = ['title' => 'September', 'status' => 'completed', 'occurs_on' => today()->toDateString(), 'data' => ['account' => $account->id, 'statement_balance' => 120]];
        $this->actingAs($owner)->post(route('apps.records.store', ['banking', 'reconciliations']), $reconciliation)->assertSessionHasErrors('data.statement_balance');

        $reconciliation['data']['statement_balance'] = 130;
        $this->actingAs($owner)->post(route('apps.records.store', ['banking', 'reconciliations']), $reconciliation)->assertSessionHasNoErrors();
        $saved = Record::query()->ofEntity('banking', 'reconciliations')->firstOrFail();
        $this->assertSame(130.0, (float) $saved->value('book_balance'));
        $this->assertSame('reconciled', $in->fresh()->status);
        $this->assertSame($saved->id, (int) $in->fresh()->value('_reconciliation'));
        $this->assertSame('unreconciled', $later->fresh()->status, 'lines after the statement date stay open');

        $this->actingAs($owner)->get(route('apps.show', 'banking'))->assertOk()->assertSee('Bank balances')->assertSee('135.00');
        $this->actingAs($owner)->get(route('apps.reports', 'banking'))->assertOk()->assertSee('Cash movement')->assertSee('Next week');
    }

    public function test_payroll_works_out_net_pay_and_rolls_up_the_run(): void
    {
        [$owner, $workspace] = $this->appWorkspace('payroll');
        $run = $this->record($workspace, 'payroll', 'runs', 'October 2026', 'draft', ['frequency' => 'monthly'], ['occurs_on' => today()]);

        $this->actingAs($owner)->post(route('apps.records.store', ['payroll', 'payslips']), [
            'title' => 'Rudo Banda', 'status' => 'draft', 'data' => ['run' => $run->id, 'basic_pay' => 100, 'paye' => 150],
        ])->assertSessionHasErrors('data.other_deductions');

        $this->actingAs($owner)->post(route('apps.records.store', ['payroll', 'payslips']), [
            'title' => 'Rudo Banda', 'status' => 'draft',
            'data' => ['run' => $run->id, 'basic_pay' => 1000, 'allowances' => 200, 'overtime' => 50, 'paye' => 150, 'social_security' => 40.5],
        ])->assertSessionHasNoErrors();
        $second = $this->record($workspace, 'payroll', 'payslips', 'Farai Ncube', 'draft', ['run' => $run->id, 'basic_pay' => 800, 'paye' => 80]);

        $first = Record::query()->ofEntity('payroll', 'payslips')->where('title', 'Rudo Banda')->firstOrFail();
        $this->assertSame(1059.5, (float) $first->amount);
        $run->refresh();
        $this->assertSame(2, (int) $run->value('employee_count'));
        $this->assertSame(2050.0, (float) $run->value('gross_total'));
        $this->assertSame(270.5, (float) $run->value('deductions_total'));
        $this->assertSame(1779.5, (float) $run->amount);

        $second->delete();
        $this->assertSame(1059.5, (float) $run->fresh()->amount);

        $run->fresh()->update(['status' => 'approved']);
        $this->assertSame('approved', $first->fresh()->status);

        $this->actingAs($owner)->get(route('apps.records.document', ['payroll', 'payslips', $first->id, 'payslip']))->assertOk()
            ->assertSee('Payslip')->assertSee('October 2026')->assertSee('1,059.50');
        $this->actingAs($owner)->get(route('apps.reports', 'payroll'))->assertOk()->assertSee('Deductions to pay over')->assertSee('150.00');
    }

    public function test_budget_lines_flag_variance_and_the_budget_adds_up(): void
    {
        [$owner, $workspace] = $this->appWorkspace('budgeting');
        $this->actingAs($owner)->post(route('apps.records.store', ['budgeting', 'budgets']), [
            'title' => 'Backwards', 'status' => 'draft', 'data' => ['period_start' => '2026-12-31', 'period_end' => '2026-01-01'],
        ])->assertSessionHasErrors('data.period_end');

        $budget = $this->record($workspace, 'budgeting', 'budgets', 'FY2026', 'approved', ['period_start' => '2026-01-01', 'period_end' => '2026-12-31']);
        $fuel = $this->record($workspace, 'budgeting', 'lines', 'Fuel', 'on_track', ['budget' => $budget->id, 'type' => 'expense', 'actual' => 1200], ['amount' => 1000]);
        $wages = $this->record($workspace, 'budgeting', 'lines', 'Wages', 'over_budget', ['budget' => $budget->id, 'type' => 'expense', 'actual' => 500], ['amount' => 3000]);
        $sales = $this->record($workspace, 'budgeting', 'lines', 'Sales', 'on_track', ['budget' => $budget->id, 'type' => 'income', 'actual' => 4000], ['amount' => 9000]);

        $this->assertSame('over_budget', $fuel->status);
        $this->assertSame('on_track', $wages->status);
        $this->assertSame('under_budget', $sales->status);
        $this->assertSame(4000.0, (float) $budget->fresh()->amount, 'only spending lines make up the total budget');

        $this->actingAs($owner)->get($budget->url())->assertOk()->assertSee('Spending against budget')->assertSee('43%');
        $this->actingAs($owner)->get(route('apps.show', 'budgeting'))->assertOk()->assertSee('Over budget')->assertSee('Fuel');
        $this->actingAs($owner)->get(route('apps.reports', 'budgeting'))->assertOk()->assertSee('Budget against actual')->assertSee('Wages');
    }

    public function test_tax_returns_work_out_payable_and_go_overdue(): void
    {
        [$owner, $workspace] = $this->appWorkspace('tax');
        $this->actingAs($owner)->post(route('apps.records.store', ['tax', 'returns']), [
            'title' => 'VAT Sept', 'status' => 'filed', 'due_on' => today()->toDateString(),
            'data' => ['tax_type' => 'vat', 'period' => '2026-09', 'output_tax' => 1500, 'input_tax' => 400],
        ])->assertSessionHasErrors('data.submission_reference');

        $vat = $this->record($workspace, 'tax', 'returns', 'VAT Sept', 'due', ['tax_type' => 'vat', 'period' => '2026-09', 'output_tax' => 1500, 'input_tax' => 400], ['due_on' => today()->subDay()]);
        $paye = $this->record($workspace, 'tax', 'returns', 'PAYE Oct', 'due', ['tax_type' => 'paye', 'period' => '2026-10'], ['amount' => 300, 'due_on' => today()->addDays(10)]);
        $this->assertSame(1100.0, (float) $vat->amount);
        $this->assertSame(300.0, (float) $paye->amount, 'a return without VAT figures keeps the amount typed in');

        $wht = $this->record($workspace, 'tax', 'withholdings', 'Consultant', 'issued', ['direction' => 'deducted_by_us', 'gross_amount' => 2000, 'rate' => 15], ['occurs_on' => today()]);
        $this->assertSame(300.0, (float) $wht->amount);

        Artisan::call('zonseo:run-app-schedules', ['--app' => 'tax']);
        $this->assertSame('overdue', $vat->fresh()->status);
        $this->assertSame('due', $paye->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', 'tax'))->assertOk()->assertSee('Filing deadlines')->assertSee('PAYE Oct');
        $this->actingAs($owner)->get(route('apps.reports', 'tax'))->assertOk()->assertSee('Withholding tax')->assertSee('300.00');
    }

    public function test_purchasing_turns_an_approved_requisition_into_an_order(): void
    {
        [$owner, $workspace] = $this->appWorkspace('purchasing');
        $requisition = $this->record($workspace, 'purchasing', 'requisitions', 'Printer toner', 'approved', ['items' => '4 x HP 26A', 'department' => 'Admin'], ['amount' => 320, 'due_on' => today()->addWeek()]);
        $this->record($workspace, 'purchasing', 'requisitions', 'Office chairs', 'submitted', ['items' => '6 chairs', 'department' => 'Admin'], ['amount' => 900]);

        $this->actingAs($owner)->post(route('apps.records.action', ['purchasing', 'requisitions', $requisition->id, 'raise_order']))->assertSessionHasNoErrors();
        $order = Record::query()->ofEntity('purchasing', 'orders')->firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertSame(320.0, (float) $order->amount);
        $this->assertSame($requisition->id, (int) $order->value('requisition'));
        $this->assertSame('4 x HP 26A', $order->value('items'));
        $this->assertSame('ordered', $requisition->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', ['purchasing', 'rfqs']), [
            'title' => 'Toner', 'status' => 'awarded', 'data' => ['requisition' => $requisition->id],
        ])->assertSessionHasErrors('data.awarded_to');

        $supplier = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Office World']);
        $order->update(['status' => 'sent', 'contact_id' => $supplier->id, 'due_on' => today()->subDays(2)]);

        $this->actingAs($owner)->get($requisition->url())->assertOk()->assertSee($order->number);
        $this->actingAs($owner)->get(route('apps.show', 'purchasing'))->assertOk()->assertSee('Office chairs')->assertSee('Late deliveries')->assertSee('2 days late');
        $this->actingAs($owner)->get(route('apps.reports', 'purchasing'))->assertOk()->assertSee('Office World')->assertSee('320.00');
    }

    public function test_receivables_reads_balances_from_invoices_and_prints_a_statement(): void
    {
        [$owner, $workspace] = $this->appWorkspace('receivables');
        $this->assertTrue($workspace->hasModule('invoicing'));
        $customer = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Mbare Hardware']);
        $late = Invoice::factory()->overdue()->withLines([['description' => 'Cement', 'quantity' => 10, 'unit_price' => 15, 'tax_rate' => 0]])
            ->create(['workspace_id' => $workspace->id, 'contact_id' => $customer->id]);
        Invoice::factory()->sent()->withLines([['description' => 'Sand', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0]])
            ->create(['workspace_id' => $workspace->id, 'contact_id' => $customer->id]);

        $account = $this->record($workspace, 'receivables', 'accounts', 'Mbare Hardware', 'current', ['payment_terms' => '30_days', 'credit_limit' => 100], ['contact_id' => $customer->id]);
        $this->assertSame(200.0, (float) $account->amount);
        $this->assertSame('overdue', $account->status);
        $this->assertSame(10, (int) $account->value('days_overdue'));

        $this->actingAs($owner)->get($account->url())->assertOk()->assertSee('Ageing')->assertSee('1–30 days')->assertSee('150.00');
        $this->actingAs($owner)->get(route('apps.show', 'receivables'))->assertOk()->assertSee('Overdue customers')->assertSee('Mbare Hardware');
        $this->actingAs($owner)->get(route('apps.records.document', ['receivables', 'accounts', $account->id, 'statement']))->assertOk()
            ->assertSee('Statement of account')->assertSee($late->number)->assertSee('200.00');
        $this->actingAs($owner)->get(route('apps.reports', 'receivables'))->assertOk()->assertSee('Aged debtors')->assertSee('Mbare Hardware');

        Invoice::query()->where('contact_id', $customer->id)->get()->each(function (Invoice $invoice) {
            Payment::factory()->create(['invoice_id' => $invoice->id, 'workspace_id' => $invoice->workspace_id, 'amount' => $invoice->total]);
            $invoice->refreshPaymentStatus();
        });
        $this->actingAs($owner)->post(route('apps.records.action', ['receivables', 'accounts', $account->id, 'refresh']))->assertSessionHasNoErrors();
        $this->assertSame('settled', $account->fresh()->status);
        $this->assertSame(0.0, (float) $account->fresh()->amount);
    }

    public function test_payables_tracks_payments_against_approved_bills(): void
    {
        [$owner, $workspace] = $this->appWorkspace('payables');
        $supplier = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'ZESA']);
        $bill = $this->record($workspace, 'payables', 'bills', 'Electricity', 'received', ['supplier_invoice_number' => 'INV-77', 'category' => 'utilities'],
            ['amount' => 300, 'contact_id' => $supplier->id, 'occurs_on' => today(), 'due_on' => today()->addDays(5)]);

        $this->actingAs($owner)->post(route('apps.records.store', ['payables', 'bills']), [
            'title' => 'Duplicate', 'status' => 'received', 'amount' => 300, 'contact_id' => $supplier->id, 'data' => ['supplier_invoice_number' => 'INV-77'],
        ])->assertSessionHasErrors('data.supplier_invoice_number');

        $payment = ['title' => 'EFT 1', 'status' => 'paid', 'amount' => 100, 'occurs_on' => today()->toDateString(), 'data' => ['bill' => $bill->id, 'method' => 'bank_transfer']];
        $this->actingAs($owner)->post(route('apps.records.store', ['payables', 'payments']), $payment)->assertSessionHasErrors('data.bill');

        $bill->update(['status' => 'approved']);
        $this->actingAs($owner)->post(route('apps.records.store', ['payables', 'payments']), [...$payment, 'amount' => 500])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', ['payables', 'payments']), [...$payment, 'data' => ['bill' => $bill->id, 'method' => 'cheque']])
            ->assertSessionHasErrors('data.cheque_number');
        $this->actingAs($owner)->post(route('apps.records.store', ['payables', 'payments']), $payment)->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame('part_paid', $bill->status);
        $this->assertSame(100.0, (float) $bill->value('paid_amount'));

        $this->actingAs($owner)->get(route('apps.show', 'payables'))->assertOk()->assertSee('Bills due in the next 14 days')->assertSee('200.00');

        $this->actingAs($owner)->post(route('apps.records.action', ['payables', 'bills', $bill->id, 'pay_in_full']), ['method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(300.0, (float) $bill->fresh()->value('paid_amount'));

        Record::query()->ofEntity('payables', 'payments')->where('title', 'EFT 1')->firstOrFail()->delete();
        $this->assertSame('part_paid', $bill->fresh()->status);

        $this->actingAs($owner)->get($bill->url())->assertOk()->assertSee('Still owed')->assertSee('100.00');
        $this->actingAs($owner)->get(route('apps.reports', 'payables'))->assertOk()->assertSee('Aged creditors')->assertSee('ZESA')->assertSee('Utilities');
    }
}
