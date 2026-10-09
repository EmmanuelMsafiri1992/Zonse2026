<?php

/*
 * General business apps (finance, sales, HR, projects). Format: see
 * App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

use App\Blueprints\Logic\CrmLogic;
use App\Blueprints\Logic\ExpensesLogic;
use App\Blueprints\Logic\FixedAssetsLogic;
use App\Blueprints\Logic\InsuranceLogic;
use App\Blueprints\Logic\LeaveLogic;
use App\Blueprints\Logic\LoansLogic;
use App\Blueprints\Logic\PettyCashLogic;
use App\Blueprints\Logic\ProjectsLogic;
use App\Blueprints\Logic\RecruitmentLogic;

return [
    'crm' => ['CRM & sales pipeline', 'target', 'Leads and deals moving through your sales pipeline.', [
        'deals' => ['Deal', 'Deal name', 'lead,qualified,proposal,negotiation,won,lost', [
            'source:select=referral,walk_in,website,social,cold_call,event,other',
            'probability:number|Probability %',
            'next_step|Next step',
            'lost_reason|Lost reason',
            'notes:textarea',
        ], ['icon' => 'target', 'prefix' => 'DL-', 'contact' => 'Customer', 'amount' => 'Deal value', 'date' => 'Opened on', 'due' => 'Expected close', 'assignee' => true, 'list' => ['source', 'next_step']]],
    ], ['logic' => CrmLogic::class]],

    'expenses' => ['Expenses', 'receipt', 'Business spending and staff expense claims.', [
        'expenses' => ['Expense', 'Description', 'draft,submitted,approved,reimbursed,rejected', [
            'category:select=fuel,travel,meals,office,utilities,repairs,airtime,other*',
            'payment_method:select=cash,card,mobile_money,bank,personal|Paid with',
            'supplier',
            'receipt_number|Receipt number',
            'billable:checkbox|Billable to a client',
        ], ['icon' => 'receipt', 'prefix' => 'EXP-', 'amount' => 'Amount', 'date' => 'Date', 'assignee' => true, 'list' => ['category', 'supplier']]],
    ], ['logic' => ExpensesLogic::class]],

    'petty-cash' => ['Petty cash & cash books', 'wallet', 'Cash in and out of the till or petty-cash box.', [
        'entries' => ['Cash entry', 'Description', 'recorded,reconciled', [
            'direction:select=in,out*',
            'category',
            'voucher|Voucher number',
        ], ['icon' => 'wallet', 'prefix' => 'PC-', 'plural' => 'Cash entries', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['direction', 'category']]],
    ], ['logic' => PettyCashLogic::class]],

    'fixed-assets' => ['Fixed assets', 'building', 'Asset register with locations, custodians and depreciation basics.', [
        'assets' => ['Asset', 'Asset name', 'in_use,in_storage,under_repair,disposed', [
            'asset_tag|Asset tag',
            'category:select=computers,furniture,vehicles,machinery,buildings,other',
            'location',
            'custodian:user|Custodian',
            'useful_life_years:number|Useful life (years)',
            'serial_number|Serial number',
        ], ['icon' => 'archive', 'prefix' => 'FA-', 'amount' => 'Cost', 'date' => 'Purchased on', 'due' => 'Warranty ends', 'list' => ['asset_tag', 'category', 'custodian']]],
    ], ['logic' => FixedAssetsLogic::class]],

    'loans' => ['Loans & microfinance', 'piggy-bank', 'Loan book: applications, disbursements and repayments.', [
        'loans' => ['Loan', 'Borrower name', 'application,approved,disbursed,in_arrears,repaid,written_off', [
            'product:select=personal,business,group,salary_advance,asset*',
            'interest_rate:number|Interest rate % (per month)',
            'term_months:number|Term (months)',
            'collateral:textarea',
            'guarantor',
        ], ['icon' => 'piggy-bank', 'prefix' => 'LN-', 'contact' => 'Borrower', 'amount' => 'Principal', 'date' => 'Disbursed on', 'due' => 'Maturity date', 'assignee' => true, 'list' => ['product', 'interest_rate', 'term_months']]],
        'repayments' => ['Repayment', 'Reference', 'received,reversed', [
            'loan:record=loans|Loan*',
            'method:select=cash,mobile_money,bank,payroll',
        ], ['icon' => 'coins', 'prefix' => 'RP-', 'date' => 'Date', 'amount' => 'Amount', 'list' => ['loan', 'method']]],
    ], ['logic' => LoansLogic::class]],

    'insurance' => ['Insurance management', 'shield', 'Policies, renewals and claims for brokers.', [
        'policies' => ['Policy', 'Policy number', 'quoted,active,lapsed,cancelled', [
            'insurer*',
            'class:select=motor,household,life,funeral,medical,business,other',
            'sum_insured:money|Sum insured',
        ], ['icon' => 'shield', 'prefix' => 'POL-', 'plural' => 'Policies', 'contact' => 'Policyholder', 'amount' => 'Premium', 'date' => 'Inception', 'due' => 'Renewal', 'assignee' => true, 'list' => ['insurer', 'class']]],
        'claims' => ['Claim', 'Incident', 'reported,submitted,approved,paid,declined', [
            'policy:record=policies|Policy*',
            'incident_date:date|Incident date',
            'details:textarea',
        ], ['icon' => 'file-warning', 'prefix' => 'IC-', 'amount' => 'Claim amount', 'date' => 'Reported on', 'assignee' => true, 'list' => ['policy', 'incident_date']]],
    ], ['logic' => InsuranceLogic::class]],

    'recruitment' => ['Recruitment', 'user-search', 'Vacancies and candidates through your hiring stages.', [
        'vacancies' => ['Vacancy', 'Job title', 'open,on_hold,filled,cancelled', [
            'department',
            'employment_type:select=full_time,part_time,contract,internship|Employment type',
            'positions:number',
            'requirements:textarea',
        ], ['icon' => 'briefcase', 'prefix' => 'VAC-', 'plural' => 'Vacancies', 'amount' => 'Salary budget', 'date' => 'Opened on', 'due' => 'Closing date', 'assignee' => true, 'list' => ['department', 'employment_type']]],
        'candidates' => ['Candidate', 'Full name', 'applied,screening,interview,offer,hired,rejected', [
            'vacancy:record=vacancies|Vacancy*',
            'email:email',
            'phone:phone',
            'cv_link:url|CV link',
            'rating:select=1,2,3,4,5',
            'notes:textarea',
        ], ['icon' => 'user-round', 'prefix' => 'CAN-', 'date' => 'Applied on', 'list' => ['vacancy', 'rating']]],
    ], ['logic' => RecruitmentLogic::class]],

    'leave' => ['Leave management', 'calendar-off', 'Leave requests and approvals.', [
        'requests' => ['Leave request', 'Employee', 'requested,approved,declined,taken,cancelled', [
            'type:select=annual,sick,family,maternity,study,unpaid*',
            'days:number|Days*',
            'reason:textarea',
        ], ['icon' => 'calendar-off', 'prefix' => 'LV-', 'date' => 'From', 'due' => 'To', 'assignee' => true, 'list' => ['type', 'days']]],
    ], ['logic' => LeaveLogic::class]],

    'projects' => ['Projects', 'kanban-square', 'Client and internal projects with milestones.', [
        'projects' => ['Project', 'Project name', 'planning,active,on_hold,completed,cancelled', [
            'description:textarea',
            'priority:select=low,normal,high',
        ], ['icon' => 'kanban-square', 'prefix' => 'PRJ-', 'contact' => 'Client', 'amount' => 'Budget', 'date' => 'Start date', 'due' => 'Deadline', 'assignee' => true, 'list' => ['priority']]],
        'milestones' => ['Milestone', 'Milestone', 'pending,in_progress,done', [
            'project:record=projects|Project*',
            'deliverables:textarea',
        ], ['icon' => 'flag', 'prefix' => 'MS-', 'amount' => 'Billing amount', 'due' => 'Due', 'assignee' => true, 'list' => ['project']]],
    ], ['logic' => ProjectsLogic::class]],
];
