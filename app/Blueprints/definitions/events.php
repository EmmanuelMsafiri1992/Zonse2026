<?php

/*
 * Events & community apps. Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'church' => ['Church & ministry', 'church', 'Members, giving, services and ministry groups.', [
        'members' => ['Member', 'Full name', 'member,visitor,inactive', [
            'phone:phone',
            'date_of_birth:date|Date of birth',
            'marital_status:select=single,married,widowed,divorced|Marital status',
            'cell_group|Cell / home group',
            'baptised:checkbox',
            'ministry|Ministry / department',
        ], ['icon' => 'user-round', 'prefix' => 'CM-', 'contact' => 'Contact', 'date' => 'Joined on', 'list' => ['phone', 'cell_group']]],
        'giving' => ['Gift', 'Giver name', 'received', [
            'member:record=members|Member',
            'type:select=tithe,offering,pledge,building_fund,mission,other*',
            'method:select=cash,mobile_money,bank,card',
        ], ['icon' => 'hand-coins', 'prefix' => 'GV-', 'plural' => 'Giving', 'date' => 'Date', 'amount' => 'Amount', 'list' => ['member', 'type', 'method']]],
        'services' => ['Service', 'Service / event', 'planned,held,cancelled', [
            'preacher',
            'theme',
            'attendance:number',
            'notes:textarea',
        ], ['icon' => 'calendar', 'prefix' => 'SRV-', 'date' => 'Date', 'list' => ['preacher', 'attendance']]],
    ]],

    'ngo' => ['NGO, donors & grants', 'heart-handshake', 'Donors, grants and beneficiaries for non-profits.', [
        'grants' => ['Grant', 'Grant name', 'pipeline,applied,awarded,reporting,closed,declined', [
            'funder*',
            'programme',
            'report_frequency:select=monthly,quarterly,biannual,annual|Reporting',
            'conditions:textarea',
        ], ['icon' => 'landmark', 'prefix' => 'GR-', 'contact' => 'Funder contact', 'amount' => 'Grant value', 'date' => 'Start date', 'due' => 'End date', 'assignee' => true, 'list' => ['funder', 'programme']]],
        'beneficiaries' => ['Beneficiary', 'Full name', 'active,graduated,exited', [
            'programme',
            'grant:record=grants|Funded by',
            'location|Village / district',
            'sex:select=female,male',
            'age:number',
        ], ['icon' => 'users', 'prefix' => 'BEN-', 'date' => 'Enrolled on', 'list' => ['programme', 'location']]],
    ]],

    'volunteers' => ['Volunteers', 'hand-helping', 'Volunteer register and shifts.', [
        'volunteers' => ['Volunteer', 'Full name', 'active,inactive', [
            'phone:phone',
            'skills:textarea',
            'availability:select=weekdays,weekends,evenings,any',
        ], ['icon' => 'user-round', 'prefix' => 'VOL-', 'contact' => 'Contact', 'list' => ['phone', 'availability']]],
        'shifts' => ['Shift', 'Task', 'scheduled,completed,missed', [
            'volunteer:record=volunteers|Volunteer*',
            'hours:number',
            'location',
        ], ['icon' => 'clock', 'prefix' => 'VS-', 'date' => 'Date', 'list' => ['volunteer', 'hours']]],
    ]],

    'memberships' => ['Memberships & clubs', 'badge-check', 'Member register, membership tiers and renewals.', [
        'members' => ['Member', 'Full name', 'active,lapsed,cancelled', [
            'membership_number|Membership number',
            'tier:select=standard,silver,gold,honorary',
            'phone:phone',
        ], ['icon' => 'badge-check', 'prefix' => 'MEM-', 'contact' => 'Contact', 'amount' => 'Annual fee', 'date' => 'Joined on', 'due' => 'Renews on', 'list' => ['membership_number', 'tier']]],
    ]],

    'ticketing' => ['Ticketing', 'tickets', 'Events, ticket types, sales and door check-in.', [
        'events' => ['Event', 'Event name', 'draft,on_sale,sold_out,completed,cancelled', [
            'venue*',
            'start_time:time|Doors open',
            'capacity:number*',
            'tickets_sold:number|Tickets sold',
        ], ['icon' => 'calendar-days', 'prefix' => 'EVT-', 'date' => 'Event date', 'assignee' => true, 'list' => ['venue', 'capacity', 'tickets_sold']]],
        'tickets' => ['Ticket', 'Ticket holder', 'issued,checked_in,refunded,void', [
            'event:record=events|Event*',
            'tier:select=general,early_bird,vip,student,complimentary*',
            'ticket_code|Ticket / QR code',
            'email:email',
        ], ['icon' => 'ticket', 'prefix' => 'TIX-', 'contact' => 'Buyer', 'amount' => 'Price', 'date' => 'Sold on', 'list' => ['event', 'tier', 'ticket_code']]],
    ]],

    'event-registration' => ['Event registration', 'clipboard-pen', 'Conferences and workshops with attendee registration and badges.', [
        'events' => ['Event', 'Event name', 'open,closed,completed,cancelled', [
            'venue',
            'capacity:number',
            'fee:money|Registration fee',
            'agenda:textarea',
        ], ['icon' => 'presentation', 'prefix' => 'CNF-', 'date' => 'Starts on', 'due' => 'Ends on', 'assignee' => true, 'list' => ['venue', 'capacity', 'fee']]],
        'registrations' => ['Registration', 'Attendee name', 'registered,paid,attended,no_show,cancelled', [
            'event:record=events|Event*',
            'organisation',
            'email:email*',
            'phone:phone',
            'dietary|Dietary needs',
            'badge_printed:checkbox|Badge printed',
        ], ['icon' => 'id-card', 'prefix' => 'REG-', 'contact' => 'Attendee', 'amount' => 'Fee paid', 'date' => 'Registered on', 'list' => ['event', 'organisation', 'badge_printed']]],
    ]],

    'venue-booking' => ['Venue & facility booking', 'calendar-range', 'Book halls, courts, rooms and fields by the hour.', [
        'facilities' => ['Facility', 'Facility name', 'available,closed', [
            'type:select=hall,court,field,pool,meeting_room,studio,other*',
            'hourly_rate:money|Hourly rate',
            'capacity:number',
        ], ['icon' => 'building', 'prefix' => 'FAC-', 'plural' => 'Facilities', 'list' => ['type', 'hourly_rate', 'capacity']]],
        'bookings' => ['Facility booking', 'Booked by', 'requested,confirmed,completed,cancelled', [
            'facility:record=facilities|Facility*',
            'start_time:time|Start*',
            'end_time:time|End*',
            'purpose',
        ], ['icon' => 'calendar-range', 'prefix' => 'FB-', 'contact' => 'Customer', 'amount' => 'Charge', 'date' => 'Date', 'list' => ['facility', 'start_time', 'end_time']]],
    ]],

    'mosque' => ['Mosque & madrasa', 'moon-star', 'Congregants, zakat and sadaqah, and madrasa students.', [
        'members' => ['Congregant', 'Name', 'active,moved,deceased', [
            'phone:phone',
            'address:textarea',
            'household_size:number|Household size',
        ], ['icon' => 'users', 'prefix' => 'MSQ-', 'contact' => 'Contact', 'list' => ['phone', 'household_size']]],
        'contributions' => ['Contribution', 'Donor name', 'received,receipted', [
            'type:select=zakat,sadaqah,lillah,building_fund,fitrah,qurbani*',
            'method:select=cash,bank,mobile_money,card',
        ], ['icon' => 'hand-coins', 'prefix' => 'ZK-', 'amount' => 'Amount', 'date' => 'Received on', 'list' => ['type', 'method']]],
        'students' => ['Madrasa student', 'Student name', 'enrolled,completed,left', [
            'class|Class / level',
            'guardian|Parent / guardian',
            'guardian_phone:phone|Guardian phone',
            'hifz_progress|Hifz progress',
        ], ['icon' => 'book-open', 'prefix' => 'MDR-', 'amount' => 'Monthly fee', 'list' => ['class', 'guardian', 'hifz_progress']]],
    ]],

    'fundraising' => ['Fundraising & donations', 'hand-heart', 'Campaigns, donors and pledges.', [
        'campaigns' => ['Campaign', 'Campaign name', 'planning,live,closed', [
            'goal:money|Goal*',
            'raised:money|Raised',
            'story:textarea',
        ], ['icon' => 'megaphone', 'prefix' => 'CMP-', 'date' => 'Starts on', 'due' => 'Ends on', 'assignee' => true, 'list' => ['goal', 'raised']]],
        'donations' => ['Donation', 'Donor name', 'pledged,received,receipted,refunded', [
            'campaign:record=campaigns|Campaign',
            'method:select=cash,bank,card,mobile_money,debit_order',
            'recurring:checkbox',
            'tax_certificate:checkbox|Tax certificate issued',
        ], ['icon' => 'hand-heart', 'prefix' => 'DON-', 'contact' => 'Donor', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['campaign', 'method', 'recurring']]],
    ]],

    'voting-elections-online-polls' => ['Voting, elections & online polls', 'vote', 'Elections, candidates and the voter register.', [
        'elections' => ['Election / poll', 'Title', 'draft,nominations,voting,closed,results_published', [
            'positions:textarea|Positions / question*',
            'method:select=online,paper,show_of_hands',
            'eligible_voters:number|Eligible voters',
            'turnout:number',
        ], ['icon' => 'vote', 'prefix' => 'ELC-', 'date' => 'Voting opens', 'due' => 'Voting closes', 'assignee' => true, 'list' => ['method', 'eligible_voters', 'turnout']]],
        'candidates' => ['Candidate / option', 'Name', 'nominated,approved,withdrawn,elected,not_elected', [
            'election:record=elections|Election*',
            'position',
            'votes:number',
            'manifesto:textarea',
        ], ['icon' => 'user-check', 'prefix' => 'CND-', 'list' => ['election', 'position', 'votes']]],
        'voters' => ['Voter', 'Voter name', 'eligible,voted,ineligible', [
            'election:record=elections|Election*',
            'member_number|Member number',
            'email:email',
            'voting_code|Voting code',
        ], ['icon' => 'user-round', 'prefix' => 'VTR-', 'list' => ['election', 'member_number']]],
    ]],

    'cinema-theatre-booking' => ['Cinema & theatre booking', 'clapperboard', 'Shows, screenings and seat bookings.', [
        'shows' => ['Show / screening', 'Title', 'scheduled,on_sale,sold_out,completed,cancelled', [
            'screen|Screen / auditorium*',
            'start_time:time|Start time*',
            'rating|Age rating',
            'seats:number',
            'seats_sold:number|Seats sold',
        ], ['icon' => 'clapperboard', 'prefix' => 'SHW-', 'date' => 'Date', 'list' => ['screen', 'start_time', 'seats_sold']]],
        'bookings' => ['Seat booking', 'Customer name', 'reserved,paid,collected,cancelled', [
            'show:record=shows|Show*',
            'seats_booked|Seats (e.g. F7, F8)*',
            'concessions|Snacks & drinks',
        ], ['icon' => 'ticket', 'prefix' => 'SB-', 'contact' => 'Customer', 'amount' => 'Total', 'date' => 'Booked on', 'list' => ['show', 'seats_booked']]],
    ]],

    'sports-leagues' => ['Sports leagues & clubs', 'trophy', 'Teams, players, fixtures and results.', [
        'teams' => ['Team', 'Team name', 'active,withdrawn', [
            'division',
            'coach',
            'home_ground|Home ground',
        ], ['icon' => 'shield', 'prefix' => 'TM-', 'contact' => 'Team manager', 'amount' => 'Affiliation fee', 'list' => ['division', 'coach']]],
        'players' => ['Player', 'Player name', 'registered,suspended,injured,transferred', [
            'team:record=teams|Team*',
            'position',
            'jersey_number:number|Jersey number',
            'date_of_birth:date|Date of birth',
        ], ['icon' => 'user-round', 'prefix' => 'PLY-', 'list' => ['team', 'position', 'jersey_number']]],
        'fixtures' => ['Fixture', 'Match', 'scheduled,played,postponed,abandoned', [
            'home_team:record=teams|Home team*',
            'away_team:record=teams|Away team*',
            'venue',
            'kick_off:time|Kick-off',
            'home_score:number|Home score',
            'away_score:number|Away score',
        ], ['icon' => 'trophy', 'prefix' => 'FX-', 'date' => 'Date', 'assignee' => true, 'list' => ['home_team', 'away_team', 'home_score', 'away_score']]],
    ]],

    'wedding-event-planning-vendors' => ['Wedding & event planning (vendors)', 'heart', 'Client events, vendors booked and the planning checklist.', [
        'events' => ['Client event', 'Couple / client', 'enquiry,contracted,planning,completed,cancelled', [
            'type:select=wedding,engagement,birthday,corporate,anniversary,other*',
            'venue',
            'guests:number',
            'budget:money',
            'theme',
        ], ['icon' => 'heart', 'prefix' => 'WED-', 'contact' => 'Client', 'amount' => 'Planner fee', 'date' => 'Event date', 'assignee' => true, 'list' => ['type', 'venue', 'guests']]],
        'vendors' => ['Vendor booking', 'Vendor name', 'shortlisted,booked,deposit_paid,paid,cancelled', [
            'event:record=events|Event*',
            'service:select=venue,catering,photography,decor,flowers,music,cake,attire,transport,makeup,other*',
            'deposit:money',
        ], ['icon' => 'store', 'prefix' => 'VND-', 'contact' => 'Vendor', 'amount' => 'Cost', 'due' => 'Balance due', 'list' => ['event', 'service', 'deposit']]],
        'checklist' => ['Checklist item', 'Task', 'to_do,done', [
            'event:record=events|Event*',
            'notes:textarea',
        ], ['icon' => 'list-checks', 'prefix' => 'WCK-', 'plural' => 'Checklist', 'due' => 'Due date', 'assignee' => true, 'list' => ['event']]],
    ]],

    'alumni-professional-associations-cpd' => ['Alumni / professional associations (CPD)', 'graduation-cap', 'Members, annual subscriptions and CPD points.', [
        'members' => ['Member', 'Member name', 'active,lapsed,suspended,resigned,deceased', [
            'membership_number|Membership number*',
            'grade:select=student,associate,member,fellow,honorary,alumnus',
            'graduation_year:number|Graduation / qualification year',
            'employer',
            'email:email',
        ], ['icon' => 'user-round', 'prefix' => 'MBR-', 'contact' => 'Contact', 'amount' => 'Annual subscription', 'date' => 'Joined on', 'due' => 'Renewal date', 'list' => ['membership_number', 'grade', 'employer']]],
        'cpd' => ['CPD record', 'Activity', 'submitted,verified,rejected', [
            'member:record=members|Member*',
            'category:select=course,conference,webinar,self_study,publication,mentoring',
            'points:number|CPD points*',
            'evidence_url:url|Evidence',
        ], ['icon' => 'award', 'prefix' => 'CPD-', 'plural' => 'CPD records', 'date' => 'Completed on', 'list' => ['member', 'category', 'points']]],
    ]],

    'social-work-case-management' => ['Social work case management', 'hand-helping', 'Clients, cases, visits and referrals for social workers and NGOs.', [
        'cases' => ['Case', 'Client name', 'intake,assessment,active,referred,closed', [
            'category:select=child_protection,gbv,elderly,disability,poverty,substance_abuse,mental_health,other*',
            'risk:select=low,medium,high,urgent',
            'household_size:number|Household size',
            'address:textarea',
            'care_plan:textarea|Care plan',
        ], ['icon' => 'folder-heart', 'prefix' => 'CASE-', 'contact' => 'Client', 'date' => 'Opened on', 'due' => 'Review date', 'assignee' => true, 'list' => ['category', 'risk']]],
        'visits' => ['Visit / session', 'Purpose', 'scheduled,completed,missed', [
            'case:record=cases|Case*',
            'type:select=home_visit,office,phone,court,school',
            'notes:textarea*',
            'referral|Referred to',
        ], ['icon' => 'notebook-pen', 'prefix' => 'VST-', 'date' => 'Date', 'assignee' => true, 'list' => ['case', 'type', 'referral']]],
    ]],

    'funeral-services-funeral-policies' => ['Funeral services & funeral policies', 'flower-2', 'Funeral policies, premiums, claims and funeral arrangements.', [
        'policies' => ['Funeral policy', 'Main member', 'active,lapsed,claimed,cancelled', [
            'policy_number|Policy number*',
            'plan:select=single,family,extended_family,group*',
            'premium:money|Monthly premium*',
            'dependants:textarea|Covered dependants',
            'id_number|ID number',
            'phone:phone',
        ], ['icon' => 'shield', 'prefix' => 'FP-', 'contact' => 'Policyholder', 'amount' => 'Cover amount', 'date' => 'Start date', 'due' => 'Paid until', 'assignee' => true, 'list' => ['policy_number', 'plan', 'premium']]],
        'funerals' => ['Funeral', 'Deceased name', 'reported,documents,arranged,completed,paid', [
            'policy:record=policies|Policy',
            'date_of_death:date|Date of death*',
            'service_venue|Service venue',
            'cemetery|Cemetery / crematorium',
            'coffin',
            'death_certificate|Death certificate number',
        ], ['icon' => 'flower-2', 'prefix' => 'FUN-', 'contact' => 'Next of kin', 'amount' => 'Funeral cost', 'date' => 'Funeral date', 'assignee' => true, 'list' => ['policy', 'service_venue', 'cemetery']]],
    ]],
];
