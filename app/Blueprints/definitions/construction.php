<?php

/*
 * Construction & technical-services apps: BOQs, sites, subcontractors, plant and practices.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'construction' => ['Construction projects & BOQ', 'hard-hat', 'Tenders, bills of quantities and estimates for construction jobs.', [
        'tenders' => ['Tender', 'Project name', 'preparing,submitted,won,lost,withdrawn', [
            'tender_number|Tender number',
            'employer|Employer / client',
            'site',
            'markup:number|Markup %',
        ], ['icon' => 'hard-hat', 'prefix' => 'TND-', 'contact' => 'Client', 'amount' => 'Tender value', 'date' => 'Issued on', 'due' => 'Closing date', 'assignee' => true, 'list' => ['tender_number', 'site', 'markup']]],
        'boq_items' => ['BOQ item', 'Description', 'priced,approved,varied', [
            'tender:record=tenders|Tender / project*',
            'item_number|Item number',
            'unit:select=m,m2,m3,kg,t,no,item,sum,hr*',
            'quantity:number*',
            'rate:money*',
        ], ['icon' => 'list-ordered', 'prefix' => 'BOQ-', 'plural' => 'BOQ items', 'amount' => 'Amount', 'list' => ['tender', 'item_number', 'quantity', 'rate']]],
    ]],

    'site-diary' => ['Site diary', 'notebook-pen', 'Daily site reports: weather, labour, work done and photos.', [
        'entries' => ['Diary entry', 'Site', 'draft,submitted,signed_off', [
            'weather:select=sunny,cloudy,rain,storm,windy',
            'workers_on_site:number|Workers on site',
            'work_done:textarea|Work done*',
            'deliveries:textarea',
            'delays:textarea|Delays & issues',
            'photos_url:url|Progress photos',
        ], ['icon' => 'notebook-pen', 'prefix' => 'SD-', 'plural' => 'Diary entries', 'date' => 'Date', 'assignee' => true, 'list' => ['weather', 'workers_on_site']]],
    ]],

    'subcontractors' => ['Subcontractors', 'users', 'Subcontract orders, progress claims and payment certificates.', [
        'subcontracts' => ['Subcontract', 'Package / trade', 'tender,awarded,on_site,complete,final_account', [
            'project|Project*',
            'retention:number|Retention %',
            'insurance_expiry:date|Insurance expiry',
        ], ['icon' => 'file-signature', 'prefix' => 'SC-', 'contact' => 'Subcontractor', 'amount' => 'Subcontract value', 'date' => 'Start date', 'due' => 'Completion date', 'assignee' => true, 'list' => ['project', 'retention']]],
        'claims' => ['Progress claim', 'Claim', 'submitted,assessed,certified,paid,disputed', [
            'subcontract:record=subcontracts|Subcontract*',
            'claimed:money|Amount claimed*',
            'certificate_number|Payment certificate',
            'retention_held:money|Retention held',
        ], ['icon' => 'file-check', 'prefix' => 'PC-', 'amount' => 'Certified amount', 'date' => 'Claim date', 'due' => 'Pay by', 'list' => ['subcontract', 'claimed', 'certificate_number']]],
    ]],

    'plant-equipment-hire-and' => ['Plant & equipment hire and tracking', 'forklift', 'Plant register, hire agreements and hour-meter readings.', [
        'plant' => ['Plant item', 'Machine', 'available,on_hire,on_site,breakdown,service', [
            'fleet_number|Fleet number*',
            'type:select=excavator,tlb,grader,roller,dozer,crane,truck,generator,compressor,other*',
            'hour_meter:number|Hour meter',
            'service_due_hours:number|Service due at (hours)',
        ], ['icon' => 'forklift', 'prefix' => 'PLT-', 'plural' => 'Plant', 'list' => ['fleet_number', 'type', 'hour_meter']]],
        'hires' => ['Plant hire', 'Site / job', 'booked,on_hire,returned,invoiced', [
            'plant:record=plant|Plant item*',
            'rate_type:select=hourly,daily,weekly,monthly',
            'rate:money',
            'operator',
            'hours_used:number|Hours used',
        ], ['icon' => 'calendar-range', 'prefix' => 'PH-', 'contact' => 'Customer', 'amount' => 'Hire total', 'date' => 'Out on', 'due' => 'Return by', 'list' => ['plant', 'rate', 'hours_used']]],
    ]],

    'snag-lists' => ['Snag lists', 'list-checks', 'Inspections, snags and handover sign-off.', [
        'inspections' => ['Inspection', 'Unit / area', 'scheduled,done,handed_over', [
            'project|Project*',
            'inspector:user|Inspector',
            'type:select=pre_handover,practical_completion,final,client_walkthrough',
        ], ['icon' => 'clipboard-check', 'prefix' => 'INS-', 'date' => 'Date', 'list' => ['project', 'inspector', 'type']]],
        'snags' => ['Snag', 'Defect', 'open,fixed,verified', [
            'inspection:record=inspections|Inspection*',
            'trade',
            'location',
            'photo_url:url|Photo',
        ], ['icon' => 'circle-alert', 'prefix' => 'SNG-', 'due' => 'Fix by', 'assignee' => true, 'list' => ['inspection', 'trade', 'location']]],
    ]],

    'drawings-document-control' => ['Drawings & document control', 'pencil-ruler', 'Drawing register with revisions and transmittals.', [
        'drawings' => ['Drawing', 'Drawing title', 'preliminary,for_approval,for_construction,as_built,superseded', [
            'drawing_number|Drawing number*',
            'revision*',
            'discipline:select=architectural,structural,civil,electrical,mechanical,plumbing',
            'file_url:url|File link',
        ], ['icon' => 'pencil-ruler', 'prefix' => 'DWG-', 'date' => 'Revision date', 'assignee' => true, 'list' => ['drawing_number', 'revision', 'discipline']]],
        'transmittals' => ['Transmittal', 'Sent to', 'sent,acknowledged', [
            'drawings:textarea|Drawings & revisions*',
            'purpose:select=information,approval,construction,tender',
        ], ['icon' => 'send', 'prefix' => 'TRN-', 'contact' => 'Recipient', 'date' => 'Sent on', 'list' => ['purpose']]],
    ]],

    'architecture-engineering-practice-management' => ['Architecture / engineering practice management', 'drafting-compass', 'Commissions by work stage, fee tracking and staff time.', [
        'commissions' => ['Commission', 'Project name', 'proposal,appointed,in_progress,on_hold,completed', [
            'stage:select=inception,concept,design_development,documentation,tender,construction,close_out*',
            'fee_basis:select=percentage,lump_sum,time_based',
            'fee_invoiced:money|Fee invoiced to date',
        ], ['icon' => 'drafting-compass', 'prefix' => 'COM-', 'contact' => 'Client', 'amount' => 'Total fee', 'date' => 'Appointed on', 'assignee' => true, 'list' => ['stage', 'fee_basis', 'fee_invoiced']]],
        'timesheets' => ['Time entry', 'Work done', 'logged,billed', [
            'commission:record=commissions|Commission*',
            'hours:number*',
            'stage:select=inception,concept,design_development,documentation,tender,construction,close_out',
        ], ['icon' => 'timer', 'prefix' => 'AT-', 'plural' => 'Time entries', 'date' => 'Date', 'assignee' => true, 'list' => ['commission', 'hours', 'stage']]],
    ]],

    'surveying-gis-jobs' => ['Surveying & GIS jobs', 'locate-fixed', 'Survey jobs, field work and deliverables.', [
        'jobs' => ['Survey job', 'Site / property', 'quoted,booked,fieldwork,processing,delivered,lodged', [
            'type:select=cadastral,topographic,engineering,setting_out,gis_mapping,drone*',
            'coordinates|Coordinates',
            'surveyor:user|Surveyor',
            'deliverables:textarea',
            'diagram_number|Diagram / SG number',
        ], ['icon' => 'locate-fixed', 'prefix' => 'SVY-', 'contact' => 'Client', 'amount' => 'Fee', 'date' => 'Field date', 'due' => 'Due date', 'assignee' => true, 'list' => ['type', 'surveyor', 'diagram_number']]],
    ]],

    'contractor-jobs' => ['Contractor job cards', 'wrench', 'Electrical, plumbing and HVAC job cards with materials and certificates.', [
        'jobs' => ['Job card', 'Job description', 'booked,on_the_way,in_progress,complete,invoiced', [
            'trade:select=electrical,plumbing,hvac,gas,solar,general*',
            'address:textarea|Site address*',
            'technician:user|Technician',
            'materials:textarea|Materials used',
            'hours:number|Labour hours',
            'certificate_number|Compliance certificate',
            'customer_signature|Signed off by',
        ], ['icon' => 'wrench', 'prefix' => 'JC-', 'contact' => 'Customer', 'amount' => 'Job total', 'date' => 'Job date', 'assignee' => true, 'list' => ['trade', 'technician', 'certificate_number']]],
    ]],

    'solar-installer' => ['Solar installer', 'solar-panel', 'Site surveys, system quotes, installations and monitoring.', [
        'surveys' => ['Site survey', 'Site / customer', 'booked,done,quoted,won,lost', [
            'address:textarea*',
            'monthly_usage:number|Monthly usage (kWh)',
            'roof_type:select=ir_sheet,tile,flat_concrete,ground_mount',
            'recommended_size:number|Recommended size (kW)',
        ], ['icon' => 'clipboard-list', 'prefix' => 'SS-', 'contact' => 'Customer', 'amount' => 'Quote', 'date' => 'Survey date', 'assignee' => true, 'list' => ['monthly_usage', 'recommended_size']]],
        'installations' => ['Installation', 'Customer / site', 'scheduled,installing,commissioned,handed_over', [
            'survey:record=surveys|Site survey',
            'panels|Panels (make × qty)',
            'inverter',
            'batteries',
            'serial_numbers:textarea|Serial numbers',
            'monitoring_url:url|Monitoring link',
            'warranty_until:date|Warranty until',
        ], ['icon' => 'solar-panel', 'prefix' => 'INST-', 'contact' => 'Customer', 'amount' => 'Contract value', 'date' => 'Install date', 'assignee' => true, 'list' => ['survey', 'inverter', 'warranty_until']]],
    ]],
];
