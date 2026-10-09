<?php

use App\Blueprints\Logic\AccountingLogic;
use App\Blueprints\Logic\BankingLogic;
use App\Blueprints\Logic\BudgetingLogic;
use App\Blueprints\Logic\ConsolidationLogic;
use App\Blueprints\Logic\HirePurchaseLogic;
use App\Blueprints\Logic\InventoryValuationLogic;
use App\Blueprints\Logic\JobCostingLogic;
use App\Blueprints\Logic\PayablesLogic;
use App\Blueprints\Logic\PaymentGatewaysLogic;
use App\Blueprints\Logic\PayrollLogic;
use App\Blueprints\Logic\PosLogic;
use App\Blueprints\Logic\PurchasingLogic;
use App\Blueprints\Logic\ReceivablesLogic;
use App\Blueprints\Logic\RecurringBillingLogic;
use App\Blueprints\Logic\SavingsGroupsLogic;
use App\Blueprints\Logic\TaxLogic;
use App\Blueprints\Logic\TreasuryLogic;

/*
 * Finance apps: books, banking, payroll, tax and specialist money businesses.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 * Field spec: "key:type=options|Label*" (type defaults to text, * = required).
 */

return [
    'accounting' => ['Accounting', 'book-open', 'Chart of accounts and journal entries for a simple general ledger.', [
        'accounts' => ['Account', 'Account name', 'active,archived', [
            'code|Account code*',
            'type:select=asset,liability,equity,income,expense*',
            'parent|Parent account',
            'opening_balance:money|Opening balance',
            'description:textarea',
        ], ['icon' => 'list-tree', 'prefix' => 'GL-', 'list' => ['code', 'type', 'opening_balance']]],
        'journals' => ['Journal entry', 'Narration', 'draft,posted,reversed', [
            'debit_account:record=accounts|Debit account*',
            'credit_account:record=accounts|Credit account*',
            'reference',
            'source:select=manual,invoice,payment,expense,payroll,adjustment',
        ], ['icon' => 'book-open', 'prefix' => 'JNL-', 'plural' => 'Journal entries', 'amount' => 'Amount', 'date' => 'Entry date', 'list' => ['debit_account', 'credit_account', 'reference']]],
    ], ['logic' => AccountingLogic::class]],

    'banking' => ['Banking & reconciliation', 'landmark', 'Bank accounts, statement lines and reconciliation.', [
        'accounts' => ['Bank account', 'Account name', 'active,closed', [
            'bank*',
            'account_number|Account number*',
            'branch_code|Branch code',
            'currency:select=usd,zwg,zar,bwp,kes,ngn,ghs,gbp,eur',
            'opening_balance:money|Opening balance',
        ], ['icon' => 'landmark', 'prefix' => 'BA-', 'list' => ['bank', 'account_number', 'currency']]],
        'transactions' => ['Statement line', 'Description', 'unreconciled,reconciled,queried', [
            'account:record=accounts|Bank account*',
            'direction:select=money_in,money_out*',
            'reference',
            'matched_to|Matched to (invoice, bill or receipt)',
        ], ['icon' => 'arrow-left-right', 'prefix' => 'BT-', 'amount' => 'Amount', 'date' => 'Transaction date', 'list' => ['account', 'direction', 'reference']]],
        'reconciliations' => ['Reconciliation', 'Period', 'in_progress,completed', [
            'account:record=accounts|Bank account*',
            'statement_balance:money|Statement closing balance*',
            'book_balance:money|Book balance',
            'notes:textarea',
        ], ['icon' => 'check-check', 'prefix' => 'REC-', 'date' => 'Statement date', 'assignee' => true, 'list' => ['account', 'statement_balance', 'book_balance']]],
    ], ['logic' => BankingLogic::class]],

    'payroll' => ['Payroll', 'banknote', 'Pay runs and payslips with statutory deductions.', [
        'runs' => ['Pay run', 'Pay period', 'draft,approved,paid', [
            'frequency:select=monthly,fortnightly,weekly*',
            'employee_count:number|Employees',
            'gross_total:money|Gross pay',
            'deductions_total:money|Total deductions',
            'notes:textarea',
        ], ['icon' => 'calendar-range', 'prefix' => 'PR-', 'amount' => 'Net pay', 'date' => 'Pay date', 'assignee' => true, 'list' => ['frequency', 'employee_count', 'gross_total']]],
        'payslips' => ['Payslip', 'Employee name', 'draft,approved,paid', [
            'run:record=runs|Pay run*',
            'employee_number|Employee number',
            'basic_pay:money|Basic pay*',
            'allowances:money',
            'overtime:money',
            'paye:money|PAYE / income tax',
            'social_security:money|Social security (NSSA, NHIF, UIF…)',
            'other_deductions:money|Other deductions',
            'bank_account|Bank account',
        ], ['icon' => 'file-text', 'prefix' => 'PS-', 'amount' => 'Net pay', 'list' => ['run', 'employee_number', 'basic_pay']]],
    ], ['logic' => PayrollLogic::class]],

    'budgeting' => ['Budgeting & forecasting', 'trending-up', 'Budgets by period and category, with actuals and variance.', [
        'budgets' => ['Budget', 'Budget name', 'draft,approved,closed', [
            'period_start:date|Period start*',
            'period_end:date|Period end*',
            'department',
            'notes:textarea',
        ], ['icon' => 'trending-up', 'prefix' => 'BUD-', 'amount' => 'Total budget', 'assignee' => true, 'list' => ['period_start', 'period_end', 'department']]],
        'lines' => ['Budget line', 'Category', 'on_track,over_budget,under_budget', [
            'budget:record=budgets|Budget*',
            'type:select=income,expense*',
            'actual:money|Actual to date',
            'forecast:money|Forecast',
        ], ['icon' => 'list', 'prefix' => 'BL-', 'amount' => 'Budgeted', 'list' => ['budget', 'type', 'actual']]],
    ], ['logic' => BudgetingLogic::class]],

    'tax' => ['Tax & VAT', 'percent', 'Tax returns, filing deadlines and withholding certificates.', [
        'returns' => ['Tax return', 'Return', 'due,in_progress,filed,paid,overdue', [
            'tax_type:select=vat,income_tax,paye,withholding,provisional,customs,other*',
            'period|Tax period*',
            'output_tax:money|Output tax',
            'input_tax:money|Input tax',
            'submission_reference|Submission reference',
        ], ['icon' => 'percent', 'prefix' => 'TAX-', 'amount' => 'Tax payable', 'due' => 'Filing deadline', 'assignee' => true, 'list' => ['tax_type', 'period']]],
        'withholdings' => ['Withholding certificate', 'Supplier or payee', 'issued,received,claimed', [
            'direction:select=deducted_by_us,deducted_from_us*',
            'gross_amount:money|Gross amount',
            'rate:number|Rate %',
            'certificate_number|Certificate number',
        ], ['icon' => 'file-check', 'prefix' => 'WHT-', 'contact' => 'Counterparty', 'amount' => 'Tax withheld', 'date' => 'Date', 'list' => ['direction', 'rate', 'certificate_number']]],
    ], ['logic' => TaxLogic::class]],

    'pos' => ['Point of sale', 'monitor-smartphone', 'Tills, cashier shifts and counter sales.', [
        'sales' => ['Sale', 'Receipt', 'completed,refunded,voided', [
            'till:record=tills|Till',
            'items:textarea|Items sold*',
            'payment_method:select=cash,card,mobile_money,account,split*',
            'tendered:money|Amount tendered',
            'change:money|Change given',
            'discount:money',
        ], ['icon' => 'shopping-cart', 'prefix' => 'POS-', 'bill' => ['once' => true], 'contact' => 'Customer', 'amount' => 'Total', 'date' => 'Sale date', 'assignee' => true, 'list' => ['till', 'payment_method']]],
        'tills' => ['Till', 'Till name', 'active,inactive', [
            'mode:select=retail,restaurant,pharmacy*',
            'location',
            'receipt_footer:textarea|Receipt footer',
        ], ['icon' => 'monitor-smartphone', 'prefix' => 'TIL-', 'list' => ['mode', 'location']]],
        'shifts' => ['Cashier shift', 'Shift', 'open,closed,short,over', [
            'till:record=tills|Till*',
            'cashier:user|Cashier*',
            'opening_float:money|Opening float',
            'expected_cash:money|Expected cash',
            'counted_cash:money|Counted cash',
        ], ['icon' => 'clock', 'prefix' => 'SHF-', 'date' => 'Shift date', 'list' => ['till', 'cashier', 'counted_cash']]],
    ], ['depends' => ['contacts', 'invoicing'], 'logic' => PosLogic::class]],

    'purchasing' => ['Purchasing & procurement', 'shopping-bag', 'Requisitions, RFQs and purchase orders to suppliers.', [
        'requisitions' => ['Requisition', 'What is needed', 'draft,submitted,approved,rejected,ordered', [
            'department',
            'items:textarea|Items & quantities*',
            'justification:textarea',
            'priority:select=low,normal,urgent',
        ], ['icon' => 'clipboard-list', 'prefix' => 'REQ-', 'amount' => 'Estimated cost', 'date' => 'Requested on', 'due' => 'Needed by', 'assignee' => true, 'list' => ['department', 'priority']]],
        'rfqs' => ['Request for quotation', 'Subject', 'open,evaluating,awarded,cancelled', [
            'requisition:record=requisitions|Requisition',
            'suppliers_invited:textarea|Suppliers invited',
            'awarded_to|Awarded to',
        ], ['icon' => 'file-question', 'prefix' => 'RFQ-', 'plural' => 'RFQs', 'date' => 'Issued on', 'due' => 'Closing date', 'list' => ['requisition', 'awarded_to']]],
        'orders' => ['Purchase order', 'Order description', 'draft,sent,part_received,received,cancelled', [
            'requisition:record=requisitions|Requisition',
            'items:textarea|Items, quantities & prices*',
            'delivery_address|Delivery address',
            'payment_terms|Payment terms',
        ], ['icon' => 'shopping-bag', 'prefix' => 'PO-', 'contact' => 'Supplier', 'amount' => 'Order total', 'date' => 'Order date', 'due' => 'Expected delivery', 'assignee' => true, 'list' => ['requisition', 'payment_terms']]],
    ], ['logic' => PurchasingLogic::class]],

    'receivables' => ['Receivables & credit control', 'hand-coins', 'Customer accounts, statements, reminders and collections.', [
        'accounts' => ['Debtor account', 'Customer', 'current,overdue,in_collection,handed_over,settled', [
            'credit_limit:money|Credit limit',
            'payment_terms:select=cod,7_days,14_days,30_days,60_days|Payment terms',
            'days_overdue:number|Days overdue',
            'promise_to_pay:date|Promise to pay date',
        ], ['icon' => 'hand-coins', 'prefix' => 'DR-', 'contact' => 'Customer', 'amount' => 'Balance owing', 'assignee' => true, 'list' => ['payment_terms', 'days_overdue', 'promise_to_pay']]],
        'actions' => ['Collection action', 'Summary', 'planned,done', [
            'account:record=accounts|Debtor account*',
            'action:select=statement_sent,reminder_sms,reminder_email,phone_call,letter_of_demand,handed_to_lawyer*',
            'outcome:textarea',
        ], ['icon' => 'phone-call', 'prefix' => 'CA-', 'date' => 'Date', 'assignee' => true, 'list' => ['account', 'action']]],
    ], ['depends' => ['contacts', 'invoicing'], 'logic' => ReceivablesLogic::class]],

    'payables' => ['Payables & bills', 'file-minus', 'Supplier bills, approvals and payment runs.', [
        'bills' => ['Bill', 'Description', 'received,approved,part_paid,paid,disputed', [
            'supplier_invoice_number|Supplier invoice number*',
            'category:select=stock,services,utilities,rent,transport,professional_fees,other',
            'vat:money|VAT',
            'paid_amount:money|Amount paid',
        ], ['icon' => 'file-minus', 'prefix' => 'BILL-', 'contact' => 'Supplier', 'amount' => 'Bill total', 'date' => 'Bill date', 'due' => 'Due date', 'assignee' => true, 'list' => ['supplier_invoice_number', 'category']]],
        'payments' => ['Supplier payment', 'Reference', 'scheduled,paid,cancelled', [
            'bill:record=bills|Bill*',
            'method:select=bank_transfer,cheque,cash,mobile_money*',
            'cheque_number|Cheque number',
        ], ['icon' => 'send', 'prefix' => 'SP-', 'amount' => 'Amount', 'date' => 'Payment date', 'list' => ['bill', 'method']]],
    ], ['logic' => PayablesLogic::class]],

    'savings-groups' => ['Savings groups & SACCOs', 'users-round', 'Members, contributions and internal loans for savings clubs, stokvels and SACCOs.', [
        'members' => ['Member', 'Member name', 'active,suspended,exited', [
            'member_number|Member number',
            'phone:phone',
            'shares:number',
            'next_of_kin|Next of kin',
        ], ['icon' => 'user-round', 'prefix' => 'MEM-', 'contact' => 'Contact', 'date' => 'Joined on', 'list' => ['member_number', 'phone', 'shares']]],
        'contributions' => ['Contribution', 'Reference', 'received,missed,reversed', [
            'member:record=members|Member*',
            'type:select=savings,shares,social_fund,fine,other*',
            'method:select=cash,mobile_money,bank',
        ], ['icon' => 'coins', 'prefix' => 'CON-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['member', 'type']]],
        'loans' => ['Member loan', 'Purpose', 'applied,approved,disbursed,repaid,in_arrears,written_off', [
            'member:record=members|Member*',
            'interest_rate:number|Interest rate % (per month)',
            'repaid:money|Amount repaid',
            'guarantors:textarea',
        ], ['icon' => 'piggy-bank', 'prefix' => 'ML-', 'amount' => 'Principal', 'date' => 'Disbursed on', 'due' => 'Due date', 'list' => ['member', 'interest_rate', 'repaid']]],
        'meetings' => ['Meeting', 'Meeting', 'planned,held,cancelled', [
            'venue',
            'attendance:number|Members present',
            'minutes:textarea',
        ], ['icon' => 'calendar', 'prefix' => 'MTG-', 'amount' => 'Total collected', 'date' => 'Meeting date', 'list' => ['venue', 'attendance']]],
    ], ['logic' => SavingsGroupsLogic::class]],

    'recurring-billing' => ['Subscription billing', 'repeat', 'Subscription plans and recurring charges for your own customers.', [
        'plans' => ['Plan', 'Plan name', 'active,retired', [
            'interval:select=weekly,monthly,quarterly,yearly*',
            'price:money|Price*',
            'description:textarea',
        ], ['icon' => 'layers', 'prefix' => 'PLN-', 'list' => ['interval', 'price']]],
        'subscriptions' => ['Subscription', 'Subscription', 'trial,active,past_due,paused,cancelled', [
            'plan:record=plans|Plan*',
            'quantity:number',
            'payment_method:select=card,debit_order,mobile_money,invoice',
        ], ['icon' => 'repeat', 'prefix' => 'SUB-', 'contact' => 'Customer', 'amount' => 'Recurring amount', 'date' => 'Started on', 'due' => 'Next bill date', 'bill' => ['periodic' => true], 'list' => ['plan', 'quantity', 'payment_method']]],
    ], ['depends' => ['contacts', 'invoicing'], 'logic' => RecurringBillingLogic::class]],

    'payments' => ['Payment gateways & wallet', 'credit-card', 'Online and mobile-money collections, with a log of every gateway transaction.', [
        'gateways' => ['Gateway', 'Gateway name', 'live,test,disabled', [
            'provider:select=stripe,paypal,paystack,flutterwave,paynow,mpesa,ecocash,mtn_momo,airtel_money,other*',
            'merchant_id|Merchant / shortcode',
            'settlement_account|Settlement account',
            'fee_percent:number|Fee %',
        ], ['icon' => 'plug', 'prefix' => 'GW-', 'list' => ['provider', 'merchant_id', 'fee_percent']]],
        'transactions' => ['Payment', 'Reference', 'pending,successful,failed,refunded', [
            'gateway:record=gateways|Gateway*',
            'payer_phone:phone|Payer phone',
            'gateway_reference|Gateway reference',
            'paid_for|Paid for (invoice, order…)',
            'fee:money',
        ], ['icon' => 'credit-card', 'prefix' => 'PAY-', 'contact' => 'Payer', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['gateway', 'gateway_reference', 'paid_for']]],
    ], ['logic' => PaymentGatewaysLogic::class]],

    'job-costing' => ['Job costing & project accounting', 'pie-chart', 'Cost centres and jobs with budgeted and actual costs.', [
        'jobs' => ['Job', 'Job name', 'open,in_progress,completed,closed', [
            'cost_centre|Cost centre',
            'budget_cost:money|Budgeted cost',
            'quoted_price:money|Quoted price',
        ], ['icon' => 'pie-chart', 'prefix' => 'JOB-', 'contact' => 'Client', 'amount' => 'Revenue', 'date' => 'Start date', 'due' => 'End date', 'assignee' => true, 'list' => ['cost_centre', 'budget_cost', 'quoted_price']]],
        'costs' => ['Job cost', 'Description', 'recorded,billed', [
            'job:record=jobs|Job*',
            'type:select=labour,materials,subcontract,equipment,travel,overhead*',
            'quantity:number',
            'billable:checkbox',
        ], ['icon' => 'receipt', 'prefix' => 'JC-', 'amount' => 'Cost', 'date' => 'Date', 'list' => ['job', 'type', 'quantity']]],
    ], ['logic' => JobCostingLogic::class]],

    'multi-entity-consolidation' => ['Multi-entity consolidation', 'network', 'Group companies, inter-company transactions and consolidation runs.', [
        'entities' => ['Group entity', 'Company name', 'active,dormant,disposed', [
            'registration_number|Registration number',
            'country',
            'ownership_percent:number|Ownership %',
            'functional_currency|Functional currency',
        ], ['icon' => 'building-2', 'prefix' => 'ENT-', 'plural' => 'Group entities', 'list' => ['country', 'ownership_percent', 'functional_currency']]],
        'intercompany' => ['Inter-company transaction', 'Description', 'recorded,matched,eliminated', [
            'from_entity:record=entities|From entity*',
            'to_entity:record=entities|To entity*',
            'type:select=loan,sale,management_fee,dividend,recharge*',
        ], ['icon' => 'arrow-left-right', 'prefix' => 'ICT-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['from_entity', 'to_entity', 'type']]],
        'consolidations' => ['Consolidation run', 'Period', 'draft,reviewed,final', [
            'exchange_rates:textarea|Exchange rates used',
            'adjustments:textarea',
        ], ['icon' => 'calculator', 'prefix' => 'CNS-', 'date' => 'Period end', 'assignee' => true]],
    ], ['logic' => ConsolidationLogic::class]],

    'inventory-valuation-fifo-weighted' => ['Inventory valuation (FIFO / weighted average / landed cost)', 'calculator', 'Cost layers and landed-cost allocations behind your stock value.', [
        'valuations' => ['Valuation', 'Period', 'draft,final', [
            'method:select=fifo,weighted_average,standard_cost*',
            'location',
            'notes:textarea',
        ], ['icon' => 'calculator', 'prefix' => 'VAL-', 'amount' => 'Closing stock value', 'date' => 'Valuation date', 'assignee' => true, 'list' => ['method', 'location']]],
        'landed_costs' => ['Landed cost', 'Shipment', 'draft,allocated', [
            'supplier_cost:money|Supplier cost*',
            'freight:money',
            'duty:money|Customs duty',
            'clearing:money|Clearing & handling',
            'allocation:select=by_value,by_quantity,by_weight|Allocate',
        ], ['icon' => 'ship', 'prefix' => 'LC-', 'amount' => 'Total landed cost', 'date' => 'Arrival date', 'list' => ['supplier_cost', 'freight', 'duty']]],
    ], ['logic' => InventoryValuationLogic::class]],

    'treasury' => ['Treasury', 'vault', 'Fixed deposits, investments and foreign-currency positions.', [
        'investments' => ['Investment', 'Investment', 'active,matured,redeemed', [
            'type:select=fixed_deposit,treasury_bill,bond,money_market,shares,other*',
            'institution*',
            'interest_rate:number|Interest rate %',
            'expected_return:money|Expected return',
        ], ['icon' => 'vault', 'prefix' => 'INV-', 'amount' => 'Principal', 'date' => 'Placed on', 'due' => 'Maturity date', 'list' => ['type', 'institution', 'interest_rate']]],
        'fx_positions' => ['FX position', 'Currency', 'open,closed', [
            'currency_code|Currency code*',
            'foreign_amount:money|Foreign amount*',
            'rate:number|Exchange rate',
            'bank',
        ], ['icon' => 'circle-dollar-sign', 'prefix' => 'FX-', 'plural' => 'FX positions', 'amount' => 'Local equivalent', 'date' => 'Date', 'list' => ['currency_code', 'foreign_amount', 'rate']]],
    ], ['logic' => TreasuryLogic::class]],

    'hire-purchase' => ['Hire purchase', 'calendar-clock', 'Hire purchase, lay-by and instalment plans.', [
        'agreements' => ['Agreement', 'Goods', 'active,completed,defaulted,repossessed,cancelled', [
            'type:select=hire_purchase,lay_by,instalment_plan*',
            'deposit:money',
            'instalment:money|Instalment amount',
            'instalments:number|Number of instalments',
            'paid_to_date:money|Paid to date',
        ], ['icon' => 'file-signature', 'prefix' => 'HP-', 'contact' => 'Customer', 'amount' => 'Total price', 'date' => 'Start date', 'due' => 'Final payment due', 'list' => ['type', 'instalment', 'paid_to_date']]],
        'payments' => ['Instalment', 'Reference', 'received,missed,reversed', [
            'agreement:record=agreements|Agreement*',
            'method:select=cash,mobile_money,bank,card',
        ], ['icon' => 'coins', 'prefix' => 'INS-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['agreement', 'method']]],
    ], ['logic' => HirePurchaseLogic::class]],

    'credit-scoring' => ['Credit scoring', 'shield-check', 'KYC checks, AML screening and credit scores for applicants.', [
        'applicants' => ['Applicant', 'Full name', 'pending,verified,rejected', [
            'id_number|ID / passport number*',
            'date_of_birth:date|Date of birth',
            'employer',
            'monthly_income:money|Monthly income',
            'proof_of_address:checkbox|Proof of address seen',
            'id_verified:checkbox|ID verified',
        ], ['icon' => 'user-check', 'prefix' => 'APP-', 'contact' => 'Contact', 'assignee' => true, 'list' => ['id_number', 'employer', 'monthly_income']]],
        'assessments' => ['Assessment', 'Assessment', 'in_progress,approved,declined,referred', [
            'applicant:record=applicants|Applicant*',
            'score:number|Credit score*',
            'bureau|Credit bureau',
            'aml_result:select=clear,match,possible_match|AML / sanctions result',
            'recommendation:textarea',
        ], ['icon' => 'gauge', 'prefix' => 'CS-', 'amount' => 'Recommended limit', 'date' => 'Assessed on', 'assignee' => true, 'list' => ['applicant', 'score', 'aml_result']]],
    ]],

    'accounting-practice' => ['Accounting practice management', 'briefcase', 'Clients, statutory deadlines, filings and engagement letters for accounting firms.', [
        'clients' => ['Client', 'Client name', 'active,onboarding,inactive', [
            'entity_type:select=individual,sole_trader,partnership,company,trust,ngo|Entity type',
            'tax_number|Tax number',
            'year_end|Financial year end',
            'services:textarea',
        ], ['icon' => 'briefcase', 'prefix' => 'CL-', 'contact' => 'Contact', 'amount' => 'Monthly fee', 'assignee' => true, 'list' => ['entity_type', 'tax_number', 'year_end']]],
        'deadlines' => ['Filing', 'Filing', 'upcoming,in_progress,filed,overdue', [
            'client:record=clients|Client*',
            'type:select=vat_return,income_tax,paye,annual_return,financial_statements,audit,other*',
            'period',
            'submission_reference|Submission reference',
        ], ['icon' => 'calendar-clock', 'prefix' => 'FIL-', 'due' => 'Deadline', 'assignee' => true, 'list' => ['client', 'type', 'period']]],
        'engagements' => ['Engagement letter', 'Scope', 'draft,sent,signed,expired', [
            'client:record=clients|Client*',
            'terms:textarea',
        ], ['icon' => 'file-signature', 'prefix' => 'ENG-', 'amount' => 'Fee', 'date' => 'Sent on', 'due' => 'Renewal date', 'list' => ['client']]],
    ]],

    'audit-working-papers' => ['Audit working papers', 'file-search', 'Audit engagements, working papers and findings.', [
        'engagements' => ['Audit engagement', 'Client & year', 'planning,fieldwork,review,reported,archived', [
            'year_end:date|Year end*',
            'materiality:money',
            'partner:user|Engagement partner',
        ], ['icon' => 'briefcase', 'prefix' => 'AUD-', 'contact' => 'Client', 'amount' => 'Audit fee', 'due' => 'Report deadline', 'assignee' => true, 'list' => ['year_end', 'partner']]],
        'papers' => ['Working paper', 'Area', 'not_started,prepared,reviewed,cleared', [
            'engagement:record=engagements|Engagement*',
            'reference|WP reference*',
            'procedures:textarea|Procedures performed',
            'conclusion:textarea',
        ], ['icon' => 'file-text', 'prefix' => 'WP-', 'assignee' => true, 'list' => ['engagement', 'reference']]],
        'findings' => ['Finding', 'Finding', 'open,agreed,resolved', [
            'engagement:record=engagements|Engagement*',
            'risk:select=low,medium,high*',
            'recommendation:textarea',
            'management_response:textarea|Management response',
        ], ['icon' => 'flag', 'prefix' => 'FND-', 'list' => ['engagement', 'risk']]],
    ]],

    'insurance-premium-financing' => ['Insurance premium financing', 'umbrella', 'Finance agreements that spread insurance premiums into instalments.', [
        'agreements' => ['Finance agreement', 'Policy', 'active,completed,cancelled,in_arrears', [
            'insurer*',
            'policy_number|Policy number*',
            'premium:money|Annual premium*',
            'interest_rate:number|Finance charge %',
            'instalments:number|Number of instalments',
        ], ['icon' => 'umbrella', 'prefix' => 'PF-', 'contact' => 'Insured', 'amount' => 'Amount financed', 'date' => 'Start date', 'due' => 'Final instalment', 'list' => ['insurer', 'policy_number', 'instalments']]],
        'collections' => ['Instalment', 'Reference', 'received,missed', [
            'agreement:record=agreements|Agreement*',
            'method:select=debit_order,mobile_money,cash,bank',
        ], ['icon' => 'coins', 'prefix' => 'PFI-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['agreement', 'method']]],
    ]],

    'forex-bureau-money-transfer' => ['Forex bureau / money transfer agent', 'circle-dollar-sign', 'Currency exchange deals, daily rates and remittances.', [
        'rates' => ['Rate', 'Currency pair', 'current,superseded', [
            'buy_rate:number|Buy rate*',
            'sell_rate:number|Sell rate*',
        ], ['icon' => 'trending-up', 'prefix' => 'RT-', 'date' => 'Effective date', 'list' => ['buy_rate', 'sell_rate']]],
        'deals' => ['FX deal', 'Customer name', 'completed,reversed', [
            'direction:select=buy,sell*',
            'currency|Foreign currency*',
            'foreign_amount:money|Foreign amount*',
            'rate:number*',
            'id_number|ID number',
        ], ['icon' => 'circle-dollar-sign', 'prefix' => 'FXD-', 'amount' => 'Local amount', 'date' => 'Date', 'assignee' => true, 'list' => ['direction', 'currency', 'foreign_amount']]],
        'transfers' => ['Money transfer', 'Sender name', 'sent,ready_for_collection,paid_out,cancelled', [
            'receiver|Receiver name*',
            'receiver_phone:phone|Receiver phone',
            'destination|Destination country / branch',
            'fee:money',
            'secret_code|Collection code',
        ], ['icon' => 'send', 'prefix' => 'MT-', 'amount' => 'Amount sent', 'date' => 'Date', 'list' => ['receiver', 'destination', 'fee']]],
    ]],

    'mobile-money-agent-float-management' => ['Mobile-money agent float management', 'smartphone', 'Float balances, top-ups and cash-in / cash-out at each agent outlet.', [
        'outlets' => ['Outlet', 'Outlet name', 'active,inactive', [
            'agent_number|Agent number*',
            'network:select=ecocash,mpesa,mtn_momo,airtel_money,onemoney,other*',
            'operator:user|Operator',
            'float_limit:money|Float limit',
        ], ['icon' => 'store', 'prefix' => 'OUT-', 'list' => ['agent_number', 'network', 'operator']]],
        'floats' => ['Float movement', 'Reference', 'recorded,reconciled', [
            'outlet:record=outlets|Outlet*',
            'type:select=top_up,rebalance,cash_in,cash_out,commission*',
            'closing_float:money|Closing e-float',
            'closing_cash:money|Closing cash',
        ], ['icon' => 'arrow-left-right', 'prefix' => 'FLT-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['outlet', 'type', 'closing_float']]],
    ]],

    'pawnshop-collateral-lending' => ['Pawnshop & collateral lending', 'gem', 'Pledged items, pawn loans, redemptions and forfeits.', [
        'pledges' => ['Pledge', 'Item description', 'pledged,redeemed,extended,forfeited,sold', [
            'category:select=jewellery,electronics,vehicle,tools,appliances,other*',
            'serial_number|Serial / IMEI',
            'valuation:money|Valuation*',
            'interest_rate:number|Interest % (per month)',
            'storage_location|Storage location',
            'id_number|Customer ID number',
        ], ['icon' => 'gem', 'prefix' => 'PWN-', 'contact' => 'Customer', 'amount' => 'Loan amount', 'date' => 'Pledged on', 'due' => 'Redeem by', 'assignee' => true, 'list' => ['category', 'valuation', 'interest_rate']]],
        'payments' => ['Pawn payment', 'Reference', 'received,reversed', [
            'pledge:record=pledges|Pledge*',
            'type:select=interest,part_payment,redemption*',
        ], ['icon' => 'coins', 'prefix' => 'PWP-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['pledge', 'type']]],
    ]],

    'investment-clubs-crowdfunding-of' => ['Investment clubs & crowdfunding of businesses', 'handshake', 'Pooled investments: members, contributions, ventures and returns.', [
        'members' => ['Investor', 'Investor name', 'active,exited', [
            'phone:phone',
            'units:number|Units held',
        ], ['icon' => 'user-round', 'prefix' => 'INV-', 'contact' => 'Contact', 'amount' => 'Total invested', 'date' => 'Joined on', 'list' => ['phone', 'units']]],
        'ventures' => ['Venture', 'Business / project', 'proposed,raising,funded,operating,exited,failed', [
            'target:money|Funding target',
            'raised:money|Raised to date',
            'expected_return:number|Expected return %',
            'pitch:textarea',
        ], ['icon' => 'rocket', 'prefix' => 'VEN-', 'amount' => 'Amount committed', 'date' => 'Opened on', 'due' => 'Funding deadline', 'list' => ['target', 'raised', 'expected_return']]],
        'contributions' => ['Contribution', 'Reference', 'received,refunded', [
            'investor:record=members|Investor*',
            'venture:record=ventures|Venture',
        ], ['icon' => 'coins', 'prefix' => 'IC-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['investor', 'venture']]],
        'distributions' => ['Distribution', 'Description', 'declared,paid', [
            'venture:record=ventures|Venture*',
            'type:select=dividend,profit_share,capital_return*',
        ], ['icon' => 'hand-coins', 'prefix' => 'DIS-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['venture', 'type']]],
    ]],

    'personal-finance-household-budgeting' => ['Personal finance & household budgeting', 'wallet', 'Household income, spending and monthly budgets.', [
        'transactions' => ['Transaction', 'Description', 'recorded', [
            'type:select=income,expense*',
            'category:select=salary,business,groceries,rent,school_fees,transport,utilities,airtime,health,entertainment,savings,other*',
            'paid_with:select=cash,card,mobile_money,bank|Paid with',
        ], ['icon' => 'wallet', 'prefix' => 'TX-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['type', 'category']]],
        'budgets' => ['Budget', 'Month', 'active,closed', [
            'category*',
            'limit:money|Limit*',
            'spent:money|Spent so far',
        ], ['icon' => 'target', 'prefix' => 'BGT-', 'list' => ['category', 'limit', 'spent']]],
    ]],
];
