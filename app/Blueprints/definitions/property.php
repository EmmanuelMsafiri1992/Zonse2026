<?php

use App\Blueprints\Logic\RentalsLogic;

/*
 * Property apps. Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'tenants' => ['Rentals & tenants', 'key', 'Properties, units, leases and maintenance for landlords and agents.', [
        'properties' => ['Property', 'Property name', 'active,sold', [
            'address:textarea*',
            'type:select=residential,commercial,mixed,industrial',
            'units_count:number|Number of units',
        ], ['icon' => 'building-2', 'prefix' => 'PRP-', 'plural' => 'Properties', 'contact' => 'Owner', 'list' => ['type', 'units_count']]],
        'units' => ['Unit', 'Unit number', 'vacant,occupied,under_repair', [
            'property:record=properties|Property*',
            'bedrooms:number',
            'size_m2:number|Size (m²)',
        ], ['icon' => 'door-closed', 'prefix' => 'UN-', 'amount' => 'Market rent', 'list' => ['property', 'bedrooms']]],
        'leases' => ['Lease', 'Tenant name', 'draft,active,notice_given,ended', [
            'unit:record=units|Unit*',
            'deposit:money',
            'escalation_percent:number|Annual escalation %',
            'payment_day:number|Rent due day',
        ], ['icon' => 'file-signature', 'prefix' => 'LS-', 'bill' => ['periodic' => true], 'contact' => 'Tenant', 'amount' => 'Monthly rent', 'date' => 'Start date', 'due' => 'End date', 'list' => ['unit', 'deposit']]],
    ], ['depends' => ['contacts', 'invoicing'], 'logic' => RentalsLogic::class]],

    'maintenance-requests' => ['Maintenance requests', 'hammer', 'Repairs reported by tenants, from report to fix.', [
        'requests' => ['Request', 'Problem', 'reported,assigned,in_progress,done,cancelled', [
            'location|Unit / location*',
            'category:select=plumbing,electrical,roofing,appliances,security,other',
            'urgency:select=low,normal,urgent',
            'details:textarea',
            'contractor|Contractor',
        ], ['icon' => 'hammer', 'prefix' => 'MR-', 'contact' => 'Reported by', 'amount' => 'Cost', 'date' => 'Reported on', 'due' => 'Fix by', 'assignee' => true, 'list' => ['location', 'category', 'urgency']]],
    ]],

    'property-listings' => ['Property listings & agents', 'building-2', 'Listings, mandates and viewings for estate agents.', [
        'listings' => ['Listing', 'Listing title', 'draft,on_market,under_offer,sold,let,withdrawn', [
            'deal:select=sale,rent*',
            'address:textarea',
            'bedrooms:number',
            'bathrooms:number',
            'size_m2:number|Size (m²)',
            'mandate:select=sole,open,none',
            'description:textarea',
        ], ['icon' => 'home', 'prefix' => 'LST-', 'contact' => 'Seller / landlord', 'amount' => 'Asking price', 'date' => 'Listed on', 'due' => 'Mandate expires', 'assignee' => true, 'list' => ['deal', 'bedrooms']]],
        'viewings' => ['Viewing', 'Prospect name', 'booked,done,no_show,cancelled', [
            'listing:record=listings|Listing*',
            'time:time',
            'feedback:textarea',
        ], ['icon' => 'eye', 'prefix' => 'VW-', 'contact' => 'Prospect', 'date' => 'Date', 'assignee' => true, 'list' => ['listing', 'time']]],
    ]],

    'rent-collection' => ['Rent collection', 'coins', 'Rent charges, payments received, arrears and utility recharges.', [
        'charges' => ['Rent charge', 'Tenant & period', 'due,part_paid,paid,in_arrears,written_off', [
            'unit|Unit / property*',
            'period|Rent period*',
            'rent:money|Rent*',
            'utilities:money|Utility recharges',
            'paid:money|Paid',
        ], ['icon' => 'file-text', 'prefix' => 'RC-', 'contact' => 'Tenant', 'amount' => 'Total due', 'date' => 'Billed on', 'due' => 'Due date', 'list' => ['unit', 'period', 'paid']]],
        'payments' => ['Rent payment', 'Reference', 'received,bounced,reversed', [
            'charge:record=charges|Rent charge*',
            'method:select=bank,cash,mobile_money,debit_order,card*',
        ], ['icon' => 'coins', 'prefix' => 'RP-', 'contact' => 'Tenant', 'amount' => 'Amount', 'date' => 'Received on', 'list' => ['charge', 'method']]],
    ]],

    'estate-management' => ['Estates, HOAs & levies', 'fence', 'Owners, levies, estate rules and committee matters.', [
        'owners' => ['Owner / unit', 'Owner name', 'current,in_arrears,sold', [
            'unit|Unit / stand number*',
            'participation_quota:number|Participation quota %',
            'levy:money|Monthly levy',
            'balance:money',
        ], ['icon' => 'house', 'prefix' => 'OWN-', 'plural' => 'Owners & units', 'contact' => 'Owner', 'list' => ['unit', 'levy', 'balance']]],
        'levies' => ['Levy', 'Period', 'billed,paid,overdue', [
            'owner:record=owners|Owner / unit*',
            'type:select=ordinary,special,reserve_fund,penalty*',
        ], ['icon' => 'receipt', 'prefix' => 'LEV-', 'amount' => 'Amount', 'date' => 'Billed on', 'due' => 'Due date', 'list' => ['owner', 'type']]],
        'matters' => ['Estate matter', 'Subject', 'open,in_committee,resolved', [
            'type:select=complaint,rule_breach,maintenance,security,approval_request*',
            'raised_by|Raised by',
            'resolution:textarea',
        ], ['icon' => 'gavel', 'prefix' => 'EM-', 'date' => 'Raised on', 'assignee' => true, 'list' => ['type', 'raised_by']]],
    ]],

    'short-stay-rental-airbnb-style' => ['Short-stay rental (Airbnb-style)', 'house', 'Listings, guest stays, cleaning turnovers and owner payouts.', [
        'listings' => ['Listing', 'Listing name', 'active,blocked,unlisted', [
            'address*',
            'bedrooms:number',
            'nightly_rate:money|Nightly rate',
            'cleaning_fee:money|Cleaning fee',
            'owner|Owner',
        ], ['icon' => 'house', 'prefix' => 'LST-', 'list' => ['bedrooms', 'nightly_rate', 'owner']]],
        'stays' => ['Stay', 'Guest name', 'booked,checked_in,checked_out,cancelled', [
            'listing:record=listings|Listing*',
            'channel:select=direct,airbnb,booking_com,vrbo,other',
            'guests:number',
            'access_code|Door / key code',
            'cleaning_done:checkbox|Turnover clean done',
        ], ['icon' => 'calendar-check', 'prefix' => 'STY-', 'contact' => 'Guest', 'amount' => 'Payout', 'date' => 'Check-in', 'due' => 'Check-out', 'assignee' => true, 'list' => ['listing', 'channel', 'cleaning_done']]],
    ]],

    'land-plot-sales' => ['Land & plot sales', 'map', 'Subdivided plots, buyers and instalment sales.', [
        'plots' => ['Plot', 'Plot / stand number', 'available,reserved,sold,transferred', [
            'development|Development / subdivision*',
            'size:number|Size (m²)',
            'price:money*',
            'title_deed|Title deed number',
        ], ['icon' => 'map', 'prefix' => 'PLT-', 'list' => ['development', 'size', 'price']]],
        'sales' => ['Plot sale', 'Buyer name', 'reserved,paying,fully_paid,transferred,cancelled', [
            'plot:record=plots|Plot*',
            'deposit:money',
            'instalment:money|Monthly instalment',
            'paid_to_date:money|Paid to date',
        ], ['icon' => 'file-signature', 'prefix' => 'PS-', 'contact' => 'Buyer', 'amount' => 'Sale price', 'date' => 'Sale date', 'due' => 'Final payment', 'assignee' => true, 'list' => ['plot', 'instalment', 'paid_to_date']]],
    ]],

    'mortgage-home-loan-origination' => ['Mortgage / home-loan origination', 'landmark', 'Home-loan applications from enquiry to registration.', [
        'applications' => ['Home-loan application', 'Applicant name', 'enquiry,documents,submitted,valuation,approved,declined,registered', [
            'property_address|Property address*',
            'purchase_price:money|Purchase price',
            'deposit:money',
            'household_income:money|Gross monthly income',
            'lender|Lender / bank',
            'interest_rate:number|Interest rate %',
            'term_years:number|Term (years)',
        ], ['icon' => 'landmark', 'prefix' => 'HL-', 'contact' => 'Applicant', 'amount' => 'Loan amount', 'date' => 'Applied on', 'assignee' => true, 'list' => ['lender', 'purchase_price', 'interest_rate']]],
    ]],

    'property-valuation' => ['Property valuation', 'ruler', 'Valuation instructions, inspections and reports.', [
        'valuations' => ['Valuation', 'Property address', 'instructed,inspected,report_draft,issued,cancelled', [
            'purpose:select=mortgage,sale,insurance,rates,estate,dispute*',
            'property_type:select=residential,commercial,industrial,agricultural,land',
            'erf_size:number|Erf size (m²)',
            'building_size:number|Building size (m²)',
            'market_value:money|Market value',
            'report_url:url|Report link',
        ], ['icon' => 'ruler', 'prefix' => 'VAL-', 'contact' => 'Client', 'amount' => 'Fee', 'date' => 'Inspected on', 'due' => 'Report due', 'assignee' => true, 'list' => ['purpose', 'property_type', 'market_value']]],
    ]],

    'facility-management-cleaning-schedules' => ['Facility management & cleaning schedules', 'spray-can', 'Sites, cleaning schedules and checklists done.', [
        'areas' => ['Area', 'Area / site', 'active,closed', [
            'building',
            'frequency:select=hourly,daily,weekly,monthly*',
            'checklist:textarea*',
        ], ['icon' => 'building', 'prefix' => 'AREA-', 'list' => ['building', 'frequency']]],
        'checks' => ['Cleaning check', 'Area', 'done,missed,issue', [
            'area:record=areas|Area*',
            'cleaner:user|Cleaner*',
            'time:time',
            'issues:textarea',
        ], ['icon' => 'spray-can', 'prefix' => 'CLN-', 'date' => 'Date', 'list' => ['area', 'cleaner', 'time']]],
    ]],

    'coworking' => ['Co-working & desk booking', 'armchair', 'Members, desk and room bookings for a co-working space.', [
        'members' => ['Member', 'Member name', 'active,paused,cancelled', [
            'plan:select=hot_desk,dedicated_desk,private_office,virtual_office,day_pass*',
            'company',
            'access_card|Access card',
        ], ['icon' => 'user-round', 'prefix' => 'CWM-', 'contact' => 'Contact', 'amount' => 'Monthly fee', 'date' => 'Start date', 'list' => ['plan', 'company']]],
        'bookings' => ['Desk / room booking', 'Desk or room', 'booked,checked_in,completed,cancelled', [
            'member:record=members|Member',
            'start_time:time|Start*',
            'end_time:time|End',
        ], ['icon' => 'armchair', 'prefix' => 'DSK-', 'amount' => 'Charge', 'date' => 'Date', 'list' => ['member', 'start_time', 'end_time']]],
    ]],

    'self-storage-units' => ['Self-storage units', 'warehouse', 'Storage units, rentals and gate access.', [
        'units' => ['Storage unit', 'Unit number', 'vacant,occupied,reserved,overlocked', [
            'size:select=small,medium,large,garage,container*',
            'area:number|Area (m²)',
            'monthly_rate:money|Monthly rate',
        ], ['icon' => 'warehouse', 'prefix' => 'SU-', 'list' => ['size', 'monthly_rate']]],
        'rentals' => ['Unit rental', 'Customer name', 'active,in_arrears,ended', [
            'unit:record=units|Unit*',
            'gate_code|Gate code',
            'insurance:checkbox|Contents insured',
            'deposit:money',
        ], ['icon' => 'key', 'prefix' => 'SR-', 'contact' => 'Customer', 'amount' => 'Monthly rent', 'date' => 'Move-in', 'due' => 'Paid until', 'list' => ['unit', 'gate_code']]],
    ]],

    'parking-management' => ['Parking management', 'square-parking', 'Parking bays, permits and sessions with fees.', [
        'permits' => ['Permit', 'Holder name', 'active,expired,cancelled', [
            'registration|Vehicle registration*',
            'bay|Bay / zone',
            'type:select=monthly,annual,staff,visitor,disabled',
        ], ['icon' => 'id-card', 'prefix' => 'PRM-', 'contact' => 'Holder', 'amount' => 'Fee', 'date' => 'From', 'due' => 'Until', 'list' => ['registration', 'bay', 'type']]],
        'sessions' => ['Parking session', 'Vehicle registration', 'parked,paid,exited,unpaid', [
            'entry_time:time|Entry*',
            'exit_time:time|Exit',
            'zone',
        ], ['icon' => 'square-parking', 'prefix' => 'PK-', 'amount' => 'Fee', 'date' => 'Date', 'assignee' => true, 'list' => ['entry_time', 'exit_time', 'zone']]],
    ]],

    'tenant-portal' => ['Tenant portal', 'door-open', 'Tenant portal accounts and the requests tenants send.', [
        'accounts' => ['Portal account', 'Tenant name', 'invited,active,disabled', [
            'unit|Unit*',
            'email:email',
            'phone:phone',
        ], ['icon' => 'user-round', 'prefix' => 'TPA-', 'contact' => 'Tenant', 'list' => ['unit', 'email']]],
        'requests' => ['Tenant request', 'Subject', 'new,in_progress,done', [
            'account:record=accounts|Account*',
            'type:select=maintenance,statement,lease_renewal,notice_to_vacate,proof_of_payment,complaint*',
            'message:textarea*',
            'reply:textarea',
        ], ['icon' => 'inbox', 'prefix' => 'TPR-', 'date' => 'Received on', 'assignee' => true, 'list' => ['account', 'type']]],
    ]],
];
