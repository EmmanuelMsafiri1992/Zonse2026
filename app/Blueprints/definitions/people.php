<?php

/*
 * HR & people apps: employee records, time, rosters, performance and staff welfare.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'hr' => ['Employees (HR)', 'users', 'Employee records, contracts and HR documents.', [
        'employees' => ['Employee', 'Full name', 'active,probation,on_leave,suspended,exited', [
            'employee_number|Employee number*',
            'job_title|Job title*',
            'department',
            'employment_type:select=permanent,fixed_term,casual,intern,contractor*',
            'id_number|ID number',
            'phone:phone',
            'email:email',
            'manager:user|Reports to',
            'bank_account|Bank account',
            'next_of_kin|Next of kin',
        ], ['icon' => 'user-round', 'prefix' => 'EMP-', 'amount' => 'Basic salary', 'date' => 'Start date', 'list' => ['employee_number', 'job_title', 'department']]],
        'contracts' => ['Employment contract', 'Contract', 'draft,signed,expired,terminated', [
            'employee:record=employees|Employee*',
            'type:select=permanent,fixed_term,casual,internship*',
            'notice_period|Notice period',
            'terms:textarea',
        ], ['icon' => 'file-signature', 'prefix' => 'EC-', 'amount' => 'Salary', 'date' => 'Start date', 'due' => 'End date', 'list' => ['employee', 'type']]],
        'documents' => ['HR document', 'Document', 'valid,expired', [
            'employee:record=employees|Employee*',
            'type:select=id,qualification,medical,police_clearance,work_permit,other*',
            'file_url:url|File link',
        ], ['icon' => 'file-badge', 'prefix' => 'HRD-', 'due' => 'Expires on', 'list' => ['employee', 'type']]],
    ]],

    'attendance' => ['Attendance & timesheets', 'clock', 'Clock-ins, daily attendance and weekly timesheets.', [
        'clockings' => ['Clock-in', 'Employee', 'present,late,absent,on_leave', [
            'clock_in:time|Clock in*',
            'clock_out:time|Clock out',
            'method:select=biometric,mobile_gps,card,manual',
            'location',
        ], ['icon' => 'fingerprint', 'prefix' => 'CLK-', 'date' => 'Date', 'assignee' => true, 'list' => ['clock_in', 'clock_out', 'method']]],
        'timesheets' => ['Timesheet', 'Week', 'draft,submitted,approved,rejected', [
            'employee:user|Employee*',
            'normal_hours:number|Normal hours',
            'overtime_hours:number|Overtime hours',
            'notes:textarea',
        ], ['icon' => 'calendar-range', 'prefix' => 'TS-', 'date' => 'Week starting', 'list' => ['employee', 'normal_hours', 'overtime_hours']]],
    ]],

    'rosters' => ['Shifts & rosters', 'calendar-days', 'Shift patterns and who works when.', [
        'shifts' => ['Shift', 'Shift', 'planned,confirmed,swapped,completed,no_show', [
            'start_time:time|Start*',
            'end_time:time|End*',
            'role|Role / post',
            'location',
        ], ['icon' => 'calendar-days', 'prefix' => 'SH-', 'date' => 'Date', 'assignee' => true, 'list' => ['start_time', 'end_time', 'role']]],
        'swaps' => ['Swap request', 'Reason', 'requested,approved,declined', [
            'shift:record=shifts|Shift*',
            'swap_with:user|Swap with*',
        ], ['icon' => 'repeat', 'prefix' => 'SWP-', 'date' => 'Requested on', 'assignee' => true, 'list' => ['shift', 'swap_with']]],
    ]],

    'performance' => ['Performance & OKRs', 'award', 'Reviews, objectives and 360 feedback.', [
        'reviews' => ['Review', 'Review period', 'scheduled,self_review,manager_review,completed', [
            'employee:user|Employee*',
            'reviewer:user|Reviewer',
            'rating:select=1,2,3,4,5',
            'strengths:textarea',
            'improvements:textarea|Areas to improve',
        ], ['icon' => 'award', 'prefix' => 'REV-', 'date' => 'Review date', 'list' => ['employee', 'reviewer', 'rating']]],
        'objectives' => ['Objective', 'Objective', 'on_track,at_risk,behind,achieved', [
            'owner:user|Owner*',
            'key_results:textarea|Key results',
            'progress:number|Progress %',
        ], ['icon' => 'target', 'prefix' => 'OKR-', 'due' => 'Due date', 'list' => ['owner', 'progress']]],
        'feedback' => ['360 feedback', 'Feedback', 'requested,given', [
            'about:user|About*',
            'from:user|From',
            'comments:textarea*',
        ], ['icon' => 'messages-square', 'prefix' => 'FBK-', 'plural' => '360 feedback', 'date' => 'Date', 'list' => ['about', 'from']]],
    ]],

    'training' => ['Training & certifications', 'graduation-cap', 'Courses, onboarding plans and staff certifications.', [
        'courses' => ['Course', 'Course name', 'planned,running,completed', [
            'provider',
            'type:select=onboarding,safety,technical,compliance,leadership,other',
            'attendees:textarea',
        ], ['icon' => 'presentation', 'prefix' => 'CRS-', 'amount' => 'Cost', 'date' => 'Start date', 'due' => 'End date', 'assignee' => true, 'list' => ['provider', 'type']]],
        'certifications' => ['Certification', 'Certificate', 'valid,expiring,expired', [
            'employee:user|Employee*',
            'course:record=courses|Course',
            'certificate_number|Certificate number',
        ], ['icon' => 'file-badge', 'prefix' => 'CRT-', 'date' => 'Issued on', 'due' => 'Expires on', 'list' => ['employee', 'course']]],
    ]],

    'employee-self-service-portal' => ['Employee self-service portal', 'user-cog', 'Staff requests for payslips, letters, detail changes and more.', [
        'requests' => ['Staff request', 'Subject', 'submitted,in_progress,done,declined', [
            'employee:user|Employee*',
            'type:select=payslip_copy,employment_letter,bank_change,address_change,leave_query,equipment,other*',
            'details:textarea*',
            'response:textarea',
        ], ['icon' => 'inbox', 'prefix' => 'ESS-', 'date' => 'Submitted on', 'assignee' => true, 'list' => ['employee', 'type']]],
        'announcements' => ['Announcement', 'Title', 'draft,published,archived', [
            'body:textarea*',
            'audience|Audience',
        ], ['icon' => 'megaphone', 'prefix' => 'ANN-', 'date' => 'Published on', 'list' => ['audience']]],
    ]],

    'disciplinary-grievance-case-management' => ['Disciplinary & grievance case management', 'gavel', 'Disciplinary cases, hearings, outcomes and staff grievances.', [
        'cases' => ['Case', 'Allegation / complaint', 'reported,investigating,hearing_scheduled,outcome,appeal,closed', [
            'type:select=disciplinary,grievance*',
            'employee:user|Employee*',
            'category:select=misconduct,attendance,performance,harassment,pay,working_conditions,other',
            'hearing_at:datetime|Hearing date',
            'outcome:select=no_action,verbal_warning,written_warning,final_warning,suspension,dismissal,upheld,not_upheld',
            'details:textarea',
        ], ['icon' => 'gavel', 'prefix' => 'DC-', 'date' => 'Reported on', 'assignee' => true, 'list' => ['type', 'employee', 'outcome']]],
    ]],

    'staffing-outsourcing-agency-placements' => ['Staffing / outsourcing agency (placements, client billing, worker payouts)', 'user-plus', 'Workers placed at clients, hours billed and payouts.', [
        'workers' => ['Worker', 'Worker name', 'available,placed,inactive', [
            'skills',
            'phone:phone',
            'pay_rate:money|Pay rate (per hour)',
        ], ['icon' => 'user-round', 'prefix' => 'WKR-', 'list' => ['skills', 'pay_rate']]],
        'placements' => ['Placement', 'Role', 'active,ended', [
            'worker:record=workers|Worker*',
            'bill_rate:money|Bill rate (per hour)*',
            'site',
        ], ['icon' => 'briefcase', 'prefix' => 'PLC-', 'contact' => 'Client', 'date' => 'Start date', 'due' => 'End date', 'assignee' => true, 'list' => ['worker', 'bill_rate', 'site']]],
        'timesheets' => ['Worker timesheet', 'Week', 'submitted,billed,paid', [
            'placement:record=placements|Placement*',
            'hours:number*',
            'payout:money|Worker payout',
        ], ['icon' => 'clock', 'prefix' => 'WTS-', 'amount' => 'Client billing', 'date' => 'Week starting', 'list' => ['placement', 'hours', 'payout']]],
    ]],

    'overtime' => ['Overtime', 'clock-plus', 'Overtime claims, allowances and staff loans and advances.', [
        'claims' => ['Overtime claim', 'Reason', 'submitted,approved,rejected,paid', [
            'employee:user|Employee*',
            'hours:number*',
            'rate:select=1.5x,2x,public_holiday',
        ], ['icon' => 'clock-plus', 'prefix' => 'OT-', 'amount' => 'Amount', 'date' => 'Worked on', 'list' => ['employee', 'hours', 'rate']]],
        'allowances' => ['Allowance', 'Allowance', 'active,ended', [
            'employee:user|Employee*',
            'type:select=housing,transport,meal,phone,risk,acting,other*',
        ], ['icon' => 'wallet', 'prefix' => 'ALW-', 'amount' => 'Monthly amount', 'date' => 'From', 'due' => 'Until', 'list' => ['employee', 'type']]],
        'advances' => ['Loan / advance', 'Purpose', 'requested,approved,repaying,repaid,rejected', [
            'employee:user|Employee*',
            'instalment:money|Monthly deduction',
            'balance:money',
        ], ['icon' => 'hand-coins', 'prefix' => 'ADV-', 'plural' => 'Loans & advances', 'amount' => 'Amount', 'date' => 'Issued on', 'list' => ['employee', 'instalment', 'balance']]],
    ]],

    'exit-offboarding-clearance' => ['Exit / offboarding & clearance', 'log-out', 'Resignations, clearance checklists and exit interviews.', [
        'exits' => ['Exit', 'Employee', 'notice_given,clearing,cleared,final_pay_done', [
            'reason:select=resignation,retirement,dismissal,retrenchment,contract_end,death,other*',
            'last_day:date|Last working day*',
            'assets_returned:checkbox|Company assets returned',
            'it_access_removed:checkbox|IT access removed',
            'finance_cleared:checkbox|Finance cleared',
            'exit_interview:textarea|Exit interview notes',
        ], ['icon' => 'log-out', 'prefix' => 'EX-', 'amount' => 'Final pay', 'date' => 'Notice date', 'assignee' => true, 'list' => ['reason', 'last_day']]],
    ]],

    'org-chart-succession-planning' => ['Org chart & succession planning', 'network', 'Positions, reporting lines and successors for key roles.', [
        'positions' => ['Position', 'Position title', 'filled,vacant,frozen', [
            'holder:user|Current holder',
            'reports_to:record=positions|Reports to',
            'department',
            'grade',
        ], ['icon' => 'network', 'prefix' => 'POS-', 'list' => ['holder', 'reports_to', 'department']]],
        'successors' => ['Successor', 'Candidate', 'ready_now,ready_1_2_years,development_needed', [
            'position:record=positions|Position*',
            'development_plan:textarea|Development plan',
        ], ['icon' => 'user-check', 'prefix' => 'SUC-', 'list' => ['position']]],
    ]],

    'health-safety' => ['Health & safety', 'hard-hat', 'Incidents, PPE issued and toolbox talks.', [
        'incidents' => ['Incident', 'What happened', 'reported,investigating,closed', [
            'type:select=injury,near_miss,property_damage,environmental,illness*',
            'severity:select=minor,lost_time,serious,fatal',
            'location',
            'injured_person|Injured person',
            'corrective_action:textarea|Corrective action',
        ], ['icon' => 'triangle-alert', 'prefix' => 'INC-', 'date' => 'Occurred on', 'assignee' => true, 'list' => ['type', 'severity', 'location']]],
        'ppe' => ['PPE issue', 'Item', 'issued,returned,replaced', [
            'employee:user|Employee*',
            'size',
            'quantity:number',
        ], ['icon' => 'hard-hat', 'prefix' => 'PPE-', 'plural' => 'PPE issues', 'date' => 'Issued on', 'due' => 'Replace by', 'list' => ['employee', 'quantity']]],
        'talks' => ['Toolbox talk', 'Topic', 'planned,held', [
            'presenter:user|Presenter',
            'attendees:textarea',
        ], ['icon' => 'presentation', 'prefix' => 'TBT-', 'date' => 'Date', 'list' => ['presenter']]],
    ]],
];
