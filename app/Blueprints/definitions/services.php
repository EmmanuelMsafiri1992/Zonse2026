<?php

use App\Blueprints\Logic\FieldServiceLogic;
use App\Blueprints\Logic\GarageLogic;
use App\Blueprints\Logic\GymLogic;
use App\Blueprints\Logic\LaundryLogic;
use App\Blueprints\Logic\LegalLogic;
use App\Blueprints\Logic\PhotographyLogic;
use App\Blueprints\Logic\SalonLogic;
use App\Blueprints\Logic\SecurityCompanyLogic;

/*
 * Service-business apps. Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'salon' => ['Salon, spa & barber', 'scissors', 'Service menu, client cards and stylist commissions.', [
        'services' => ['Service', 'Service name', 'active,inactive', [
            'category:select=hair,nails,skin,massage,barber,makeup,other',
            'duration_min:number|Duration (min)',
            'commission_percent:number|Commission %',
        ], ['icon' => 'sparkles', 'prefix' => 'SVC-', 'amount' => 'Price', 'list' => ['category', 'duration_min']]],
        'client_cards' => ['Client card', 'Client name', 'active,inactive', [
            'phone:phone',
            'hair_type|Hair / skin type',
            'colour_formula:textarea|Colour formula',
            'allergies:textarea',
            'preferred_stylist:user|Preferred stylist',
        ], ['icon' => 'contact', 'prefix' => 'CC-', 'contact' => 'Contact', 'list' => ['phone', 'preferred_stylist']]],
        'visits' => ['Visit', 'Services done', 'completed,cancelled,no_show', [
            'client:record=client_cards|Client*',
            'service:record=services|Main service',
            'tip:money',
            'notes:textarea',
        ], ['icon' => 'calendar-check', 'prefix' => 'SV-', 'bill' => ['via' => 'client'], 'date' => 'Date', 'amount' => 'Total paid', 'assignee' => true, 'list' => ['client', 'service']]],
    ], ['depends' => ['contacts', 'invoicing'], 'logic' => SalonLogic::class]],

    'gym' => ['Gym & fitness', 'dumbbell', 'Members, plans, check-ins and personal-training sessions.', [
        'members' => ['Member', 'Full name', 'active,frozen,expired,cancelled', [
            'plan:select=monthly,quarterly,annual,pay_as_you_go*',
            'phone:phone',
            'emergency_contact|Emergency contact',
            'goals:textarea',
            'health_notes:textarea|Health notes',
        ], ['icon' => 'user-round', 'prefix' => 'GM-', 'contact' => 'Contact', 'amount' => 'Plan fee', 'date' => 'Joined on', 'due' => 'Renews on', 'list' => ['plan', 'phone']]],
        'check_ins' => ['Check-in', 'Member name', 'checked_in', [
            'member:record=members|Member*',
            'time_in:time|Time in',
            'area:select=gym_floor,class,pool,sauna',
        ], ['icon' => 'log-in', 'prefix' => 'CI-', 'date' => 'Date', 'list' => ['member', 'time_in', 'area']]],
        'pt_sessions' => ['PT session', 'Focus', 'booked,completed,cancelled,no_show', [
            'member:record=members|Member*',
            'start_time:time|Start time',
            'notes:textarea',
        ], ['icon' => 'dumbbell', 'prefix' => 'PT-', 'plural' => 'PT sessions', 'date' => 'Date', 'amount' => 'Fee', 'assignee' => true, 'list' => ['member', 'start_time']]],
    ], ['logic' => GymLogic::class]],

    'field-service' => ['Field service & job cards', 'wrench', 'Call-outs, job cards and technician visits for trades and installers.', [
        'jobs' => ['Job card', 'Job description', 'new,scheduled,in_progress,on_hold,completed,invoiced,cancelled', [
            'site_address:textarea|Site address*',
            'category:select=installation,repair,maintenance,inspection,quote_visit',
            'priority:select=low,normal,high,emergency',
            'equipment|Equipment / model',
            'work_done:textarea|Work done',
            'materials_used:textarea|Materials used',
            'hours:number|Labour hours',
            'customer_signed:checkbox|Customer signed off',
        ], ['icon' => 'clipboard-list', 'prefix' => 'JC-', 'contact' => 'Customer', 'amount' => 'Job value', 'date' => 'Scheduled for', 'due' => 'Complete by', 'assignee' => true, 'list' => ['category', 'priority']]],
    ], ['logic' => FieldServiceLogic::class]],

    'legal' => ['Legal practice', 'scale', 'Matters, court dates and billable time for law firms.', [
        'matters' => ['Matter', 'Matter name', 'open,on_hold,closed', [
            'practice_area:select=litigation,conveyancing,family,criminal,commercial,labour,estates,other|Practice area*',
            'opposing_party|Opposing party',
            'case_number|Court case number',
            'court',
            'fee_arrangement:select=hourly,fixed,contingency,retainer|Fee arrangement',
            'summary:textarea',
        ], ['icon' => 'briefcase', 'prefix' => 'MAT-', 'contact' => 'Client', 'amount' => 'Estimated fees', 'date' => 'Opened on', 'assignee' => true, 'list' => ['practice_area', 'case_number']]],
        'court_dates' => ['Court date', 'Hearing', 'scheduled,attended,postponed,cancelled', [
            'matter:record=matters|Matter*',
            'time:time',
            'court',
            'outcome:textarea',
        ], ['icon' => 'gavel', 'prefix' => 'CRT-', 'date' => 'Date', 'assignee' => true, 'list' => ['matter', 'time', 'court']]],
        'time_entries' => ['Time entry', 'Work done', 'unbilled,billed,written_off', [
            'matter:record=matters|Matter*',
            'hours:number|Hours*',
            'rate:money|Hourly rate',
        ], ['icon' => 'timer', 'prefix' => 'TE-', 'plural' => 'Time entries', 'date' => 'Date', 'amount' => 'Value', 'assignee' => true, 'list' => ['matter', 'hours']]],
    ], ['logic' => LegalLogic::class]],

    'garage' => ['Garage & workshop', 'car', 'Vehicles, workshop job cards and service history.', [
        'vehicles' => ['Vehicle', 'Registration', 'active,sold,scrapped', [
            'make*',
            'model',
            'year:number',
            'vin|VIN / chassis',
            'odometer:number|Odometer (km)',
        ], ['icon' => 'car', 'prefix' => 'VEH-', 'contact' => 'Owner', 'list' => ['make', 'model', 'year']]],
        'job_cards' => ['Job card', 'Work requested', 'booked,in_workshop,awaiting_parts,ready,collected,cancelled', [
            'vehicle:record=vehicles|Vehicle*',
            'odometer_in:number|Odometer in',
            'diagnosis:textarea',
            'parts_used:textarea|Parts used',
            'labour_hours:number|Labour hours',
        ], ['icon' => 'wrench', 'prefix' => 'GJ-', 'contact' => 'Customer', 'amount' => 'Job total', 'date' => 'Booked in', 'due' => 'Promised by', 'assignee' => true, 'list' => ['vehicle', 'odometer_in']]],
    ], ['logic' => GarageLogic::class]],

    'laundry' => ['Laundry & dry-cleaning', 'shirt', 'Drop-off tickets from intake to collection.', [
        'orders' => ['Order', 'Items', 'received,washing,ready,collected,cancelled', [
            'pieces:number|Pieces*',
            'service:select=wash_and_fold,dry_clean,ironing,duvet,mixed',
            'express:checkbox',
            'paid:checkbox',
            'notes:textarea|Stains / special care',
        ], ['icon' => 'shirt', 'prefix' => 'LDY-', 'contact' => 'Customer', 'amount' => 'Price', 'date' => 'Received on', 'due' => 'Ready by', 'list' => ['pieces', 'service', 'paid']]],
    ], ['logic' => LaundryLogic::class]],

    'security-company' => ['Security company', 'shield-check', 'Client sites, guard posts and occurrence-book entries.', [
        'sites' => ['Site', 'Site name', 'active,suspended,ended', [
            'address:textarea*',
            'guards_required:number|Guards required',
            'shift_pattern:select=day,night,24_hours|Shift pattern',
            'site_contact|Site contact person',
        ], ['icon' => 'building', 'prefix' => 'SIT-', 'contact' => 'Client', 'amount' => 'Monthly fee', 'list' => ['guards_required', 'shift_pattern']]],
        'occurrences' => ['Occurrence', 'What happened', 'logged,escalated,closed', [
            'site:record=sites|Site*',
            'time:time',
            'type:select=patrol,incident,visitor,alarm,handover,other',
            'details:textarea*',
        ], ['icon' => 'notebook-pen', 'prefix' => 'OB-', 'date' => 'Date', 'assignee' => true, 'list' => ['site', 'type', 'time']]],
    ], ['logic' => SecurityCompanyLogic::class]],

    'photography' => ['Photography studio', 'camera', 'Shoots, galleries and delivery deadlines.', [
        'shoots' => ['Shoot', 'Shoot name', 'enquiry,booked,shot,editing,delivered,cancelled', [
            'type:select=wedding,portrait,event,product,corporate,family',
            'location',
            'deposit_paid:money|Deposit paid',
            'gallery_link:url|Gallery link',
        ], ['icon' => 'camera', 'prefix' => 'SH-', 'contact' => 'Client', 'amount' => 'Package price', 'date' => 'Shoot date', 'due' => 'Deliver by', 'assignee' => true, 'list' => ['type', 'location']]],
    ], ['logic' => PhotographyLogic::class]],

    'cleaning-home-services' => ['Cleaning & home services', 'spray-can', 'Recurring home-service jobs, cleaners and customer sites.', [
        'customers' => ['Service address', 'Customer name', 'active,paused,ended', [
            'address:textarea*',
            'frequency:select=once_off,weekly,fortnightly,monthly',
            'access_notes|Access notes (keys, alarm, pets)',
            'phone:phone',
        ], ['icon' => 'house', 'prefix' => 'ADR-', 'plural' => 'Service addresses', 'contact' => 'Customer', 'list' => ['frequency', 'phone']]],
        'jobs' => ['Service job', 'Service', 'scheduled,in_progress,done,missed,invoiced', [
            'customer:record=customers|Service address*',
            'service:select=standard_clean,deep_clean,move_out,carpets,windows,laundry,garden,pest_control*',
            'start_time:time|Start time',
            'hours:number',
            'checklist_notes:textarea|Checklist notes',
        ], ['icon' => 'spray-can', 'prefix' => 'HS-', 'amount' => 'Price', 'date' => 'Date', 'assignee' => true, 'list' => ['customer', 'service', 'start_time']]],
    ]],

    'consultancy-client-portals' => ['Consultancy with client portals', 'briefcase', 'Engagements, deliverables and client sign-off.', [
        'engagements' => ['Engagement', 'Engagement name', 'proposal,active,on_hold,completed', [
            'scope:textarea*',
            'billing:select=fixed_fee,time_and_materials,retainer',
            'portal_access:checkbox|Client has portal access',
        ], ['icon' => 'briefcase', 'prefix' => 'ENG-', 'contact' => 'Client', 'amount' => 'Fee', 'date' => 'Start date', 'due' => 'End date', 'assignee' => true, 'list' => ['billing', 'portal_access']]],
        'deliverables' => ['Deliverable', 'Deliverable', 'not_started,in_progress,with_client,approved,rejected', [
            'engagement:record=engagements|Engagement*',
            'file_url:url|File link',
            'client_feedback:textarea|Client feedback',
        ], ['icon' => 'file-check', 'prefix' => 'DLV-', 'due' => 'Due date', 'assignee' => true, 'list' => ['engagement']]],
    ]],

    'car-wash' => ['Car wash', 'droplets', 'Wash packages, cars washed and washer commissions.', [
        'packages' => ['Wash package', 'Package name', 'active,retired', [
            'vehicle_size:select=small,sedan,suv,bakkie,minibus,truck*',
            'price:money*',
            'commission:money|Washer commission',
        ], ['icon' => 'sparkles', 'prefix' => 'PKG-', 'list' => ['vehicle_size', 'price', 'commission']]],
        'washes' => ['Wash', 'Vehicle registration', 'waiting,washing,done,paid', [
            'package:record=packages|Package*',
            'washer:user|Washer',
            'customer_phone:phone|Customer phone',
            'payment:select=cash,card,mobile_money,account',
        ], ['icon' => 'droplets', 'prefix' => 'WSH-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['package', 'washer', 'payment']]],
    ]],

    'tailoring' => ['Tailoring & alterations', 'scissors', 'Customer measurements, garment orders and fittings.', [
        'measurements' => ['Measurement card', 'Customer name', 'current,outdated', [
            'phone:phone',
            'chest:number|Chest (cm)',
            'waist:number|Waist (cm)',
            'hips:number|Hips (cm)',
            'length:number|Length (cm)',
            'sleeve:number|Sleeve (cm)',
            'inseam:number|Inseam (cm)',
            'notes:textarea',
        ], ['icon' => 'ruler', 'prefix' => 'MSR-', 'contact' => 'Customer', 'date' => 'Measured on', 'list' => ['phone', 'chest', 'waist']]],
        'orders' => ['Garment order', 'Garment', 'received,cutting,sewing,fitting,ready,collected', [
            'measurements:record=measurements|Measurement card',
            'type:select=new_garment,alteration,repair*',
            'fabric',
            'deposit:money',
            'fitting_date:date|Fitting date',
        ], ['icon' => 'scissors', 'prefix' => 'TLR-', 'contact' => 'Customer', 'amount' => 'Price', 'date' => 'Received on', 'due' => 'Ready by', 'assignee' => true, 'list' => ['type', 'fabric', 'fitting_date']]],
    ]],

    'printing' => ['Print shop', 'printer', 'Print jobs with artwork approval and production.', [
        'jobs' => ['Print job', 'Job description', 'quote,artwork,proof_sent,approved,printing,finishing,ready,collected', [
            'product:select=business_cards,flyers,banners,posters,booklets,t_shirts,signage,large_format,other*',
            'quantity:number*',
            'size|Size / material',
            'artwork_url:url|Artwork link',
            'proof_approved:checkbox|Proof approved',
            'deposit:money',
        ], ['icon' => 'printer', 'prefix' => 'PRT-', 'contact' => 'Customer', 'amount' => 'Price', 'date' => 'Received on', 'due' => 'Due date', 'assignee' => true, 'list' => ['product', 'quantity', 'proof_approved']]],
    ]],

    'pet-grooming' => ['Pet grooming & boarding', 'paw-print', 'Pets, grooming appointments and boarding stays.', [
        'pets' => ['Pet', 'Pet name', 'active,inactive', [
            'species:select=dog,cat,rabbit,bird,other*',
            'breed',
            'temperament|Temperament / notes',
            'vaccinations_until:date|Vaccinations valid until',
        ], ['icon' => 'dog', 'prefix' => 'PET-', 'contact' => 'Owner', 'list' => ['species', 'breed', 'vaccinations_until']]],
        'appointments' => ['Appointment', 'Service', 'booked,checked_in,in_progress,ready,collected,cancelled', [
            'pet:record=pets|Pet*',
            'type:select=full_groom,bath_brush,nail_clip,boarding,daycare*',
            'drop_off:time|Drop-off time',
            'notes:textarea',
        ], ['icon' => 'paw-print', 'prefix' => 'GRM-', 'amount' => 'Price', 'date' => 'Date', 'due' => 'Collect by', 'assignee' => true, 'list' => ['pet', 'type', 'drop_off']]],
    ]],

    'it-services' => ['IT services & MSP', 'server', 'Managed clients, device inventory and service agreements.', [
        'clients' => ['Managed client', 'Client name', 'onboarding,managed,ad_hoc,ended', [
            'agreement:select=fully_managed,co_managed,block_hours,ad_hoc',
            'users_covered:number|Users covered',
            'sla|SLA',
            'renewal_date:date|Renewal date',
        ], ['icon' => 'building-2', 'prefix' => 'MSP-', 'contact' => 'Main contact', 'amount' => 'Monthly fee', 'assignee' => true, 'list' => ['agreement', 'users_covered', 'renewal_date']]],
        'devices' => ['Device', 'Device name', 'active,in_repair,retired', [
            'client:record=clients|Client*',
            'type:select=laptop,desktop,server,firewall,switch,printer,phone,other*',
            'serial_number|Serial number',
            'operating_system|Operating system',
            'warranty_until:date|Warranty until',
            'assigned_user|Used by',
        ], ['icon' => 'monitor', 'prefix' => 'DEV-', 'list' => ['client', 'type', 'warranty_until']]],
        'credentials' => ['Credential note', 'System', 'current,rotated', [
            'client:record=clients|Client*',
            'username',
            'location|Where the password is kept',
        ], ['icon' => 'key-round', 'prefix' => 'CRD-', 'plural' => 'Credential notes', 'list' => ['client', 'username']]],
    ]],

    'hosting-billing' => ['Hosting & domain billing', 'globe', 'Hosting accounts, domains and renewals.', [
        'domains' => ['Domain', 'Domain name', 'active,pending_transfer,expired,cancelled', [
            'registrar',
            'auto_renew:checkbox|Auto-renew',
            'nameservers',
        ], ['icon' => 'globe', 'prefix' => 'DOM-', 'contact' => 'Customer', 'amount' => 'Renewal price', 'date' => 'Registered on', 'due' => 'Expires on', 'list' => ['registrar', 'auto_renew']]],
        'hosting' => ['Hosting account', 'Primary domain', 'active,suspended,cancelled', [
            'plan:select=shared,reseller,vps,dedicated,email_only*',
            'server',
            'username|Control panel username',
            'disk_quota:number|Disk quota (GB)',
        ], ['icon' => 'server', 'prefix' => 'HST-', 'contact' => 'Customer', 'amount' => 'Monthly price', 'date' => 'Created on', 'due' => 'Next due', 'list' => ['plan', 'server']]],
    ]],

    'translation' => ['Translation & interpreting', 'languages', 'Translation jobs and interpreting bookings.', [
        'jobs' => ['Translation job', 'Document / job', 'quoted,assigned,translating,proofreading,delivered', [
            'type:select=translation,sworn_translation,interpreting,transcription,subtitling*',
            'source_language|From*',
            'target_language|To*',
            'word_count:number|Word count',
            'rate:money|Rate',
            'translator:user|Translator',
        ], ['icon' => 'languages', 'prefix' => 'TRN-', 'contact' => 'Client', 'amount' => 'Price', 'date' => 'Received on', 'due' => 'Due date', 'list' => ['type', 'source_language', 'target_language']]],
    ]],

    'recruitment-agency' => ['Recruitment agency', 'user-search', 'Vacancies, candidates and placements with fees.', [
        'vacancies' => ['Vacancy', 'Job title', 'open,shortlisting,interviewing,filled,cancelled', [
            'location',
            'salary_range|Salary range',
            'fee_percent:number|Fee %',
            'requirements:textarea',
        ], ['icon' => 'briefcase', 'prefix' => 'VAC-', 'plural' => 'Vacancies', 'contact' => 'Client company', 'date' => 'Opened on', 'due' => 'Closing date', 'assignee' => true, 'list' => ['location', 'salary_range']]],
        'candidates' => ['Candidate', 'Candidate name', 'new,screened,submitted,interview,offer,placed,rejected', [
            'vacancy:record=vacancies|Vacancy',
            'email:email',
            'phone:phone',
            'current_role|Current role',
            'expected_salary:money|Expected salary',
            'cv_url:url|CV link',
        ], ['icon' => 'user-search', 'prefix' => 'CAN-', 'amount' => 'Placement fee', 'date' => 'Added on', 'assignee' => true, 'list' => ['vacancy', 'current_role', 'expected_salary']]],
    ]],

    'music-studio' => ['Music & recording studio', 'music', 'Studio sessions, projects and music lessons.', [
        'sessions' => ['Studio session', 'Artist / band', 'booked,in_session,completed,cancelled', [
            'room|Studio room',
            'type:select=recording,mixing,mastering,rehearsal,lesson,podcast*',
            'start_time:time|Start',
            'hours:number',
            'engineer:user|Engineer / teacher',
        ], ['icon' => 'music', 'prefix' => 'SES-', 'contact' => 'Client', 'amount' => 'Charge', 'date' => 'Date', 'list' => ['type', 'start_time', 'engineer']]],
        'projects' => ['Music project', 'Title', 'pre_production,tracking,mixing,mastering,released', [
            'tracks:number',
            'files_url:url|Files link',
        ], ['icon' => 'disc-3', 'prefix' => 'MP-', 'contact' => 'Client', 'amount' => 'Budget', 'due' => 'Release date', 'assignee' => true, 'list' => ['tracks']]],
    ]],

    'tattoo' => ['Tattoo & piercing studio', 'pen-tool', 'Bookings, consent forms and aftercare.', [
        'bookings' => ['Booking', 'Client name', 'consultation,booked,in_progress,healed,touch_up,cancelled', [
            'type:select=tattoo,piercing,touch_up,removal*',
            'placement|Placement on body',
            'design:textarea|Design description',
            'artist:user|Artist',
            'deposit:money',
            'consent_signed:checkbox|Consent signed',
            'over_18_verified:checkbox|Age verified',
        ], ['icon' => 'pen-tool', 'prefix' => 'TAT-', 'contact' => 'Client', 'amount' => 'Price', 'date' => 'Appointment', 'list' => ['type', 'artist', 'consent_signed']]],
    ]],
];
