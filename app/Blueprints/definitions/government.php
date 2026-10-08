<?php

/*
 * Government, public-sector and compliance apps.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'permits' => ['Permits & licences', 'stamp', 'Permit and licence applications, inspections and issued licences.', [
        'applications' => ['Application', 'Applicant name', 'received,under_review,inspection,approved,rejected,issued', [
            'type:select=business_licence,building_plan,trading_permit,liquor_licence,event_permit,signage,health_certificate,other*',
            'property|Premises / property',
            'documents:textarea|Documents submitted',
            'licence_number|Licence number issued',
            'valid_until:date|Valid until',
        ], ['icon' => 'stamp', 'prefix' => 'APP-', 'contact' => 'Applicant', 'amount' => 'Fee', 'date' => 'Received on', 'due' => 'Decision due', 'assignee' => true, 'list' => ['type', 'licence_number', 'valid_until']]],
        'inspections' => ['Inspection', 'Premises', 'scheduled,passed,failed,re_inspect', [
            'application:record=applications|Application*',
            'findings:textarea',
        ], ['icon' => 'clipboard-check', 'prefix' => 'INSP-', 'date' => 'Date', 'assignee' => true, 'list' => ['application']]],
    ]],

    'risk-management-register' => ['Risk register', 'shield-alert', 'Risks with likelihood, impact, owners and mitigations.', [
        'risks' => ['Risk', 'Risk', 'identified,assessed,mitigating,accepted,closed', [
            'category:select=strategic,operational,financial,compliance,reputational,it_cyber,health_safety*',
            'likelihood:select=1_rare,2_unlikely,3_possible,4_likely,5_almost_certain*',
            'impact:select=1_insignificant,2_minor,3_moderate,4_major,5_catastrophic*',
            'controls:textarea|Existing controls',
            'mitigation:textarea|Mitigation plan',
        ], ['icon' => 'shield-alert', 'prefix' => 'RSK-', 'date' => 'Identified on', 'due' => 'Review date', 'assignee' => true, 'list' => ['category', 'likelihood', 'impact']]],
    ]],

    'health-safety-incident-reporting' => ['Incident reporting (H&S)', 'triangle-alert', 'Incidents, near misses, investigations and corrective actions.', [
        'incidents' => ['Incident', 'What happened', 'reported,investigating,actions_open,closed', [
            'type:select=injury,near_miss,property_damage,environmental,security,occupational_illness*',
            'severity:select=minor,lost_time,serious,fatal',
            'location*',
            'time:time',
            'people_involved:textarea|People involved',
            'root_cause:textarea|Root cause',
            'reportable:checkbox|Reportable to authority',
        ], ['icon' => 'triangle-alert', 'prefix' => 'INC-', 'date' => 'Date', 'assignee' => true, 'list' => ['type', 'severity', 'location']]],
        'actions' => ['Corrective action', 'Action', 'open,done,verified', [
            'incident:record=incidents|Incident*',
        ], ['icon' => 'list-checks', 'prefix' => 'CA-', 'due' => 'Due date', 'assignee' => true, 'list' => ['incident']]],
    ]],

    'e-citizen-service-desk-complaints' => ['Citizen service desk & complaints', 'messages-square', 'Citizen requests and complaints routed to departments.', [
        'requests' => ['Citizen request', 'Subject', 'received,assigned,in_progress,resolved,closed,escalated', [
            'type:select=complaint,service_request,enquiry,compliment,fault_report*',
            'department',
            'channel:select=walk_in,phone,email,website,whatsapp,social_media',
            'ward|Ward / area',
            'description:textarea*',
            'resolution:textarea',
        ], ['icon' => 'messages-square', 'prefix' => 'CSR-', 'contact' => 'Citizen', 'date' => 'Received on', 'due' => 'Service-level due', 'assignee' => true, 'list' => ['type', 'department', 'ward']]],
    ]],

    'council-revenue' => ['Council revenue (rates)', 'landmark', 'Rateable properties, rates bills and payments.', [
        'properties' => ['Rateable property', 'Stand / erf number', 'active,exempt,in_arrears,handed_over', [
            'owner|Owner*',
            'address:textarea',
            'valuation:money|Municipal valuation',
            'category:select=residential,business,industrial,agricultural,state,vacant_land',
            'balance:money',
        ], ['icon' => 'house', 'prefix' => 'ERF-', 'contact' => 'Owner', 'list' => ['owner', 'category', 'balance']]],
        'bills' => ['Rates bill', 'Period', 'billed,part_paid,paid,overdue', [
            'property:record=properties|Property*',
            'rates:money',
            'refuse:money',
            'water:money',
            'sewer:money',
        ], ['icon' => 'receipt', 'prefix' => 'RB-', 'amount' => 'Total', 'date' => 'Billed on', 'due' => 'Due date', 'list' => ['property', 'rates']]],
    ]],

    'court-case-management-cause' => ['Court case management & cause lists', 'gavel', 'Court files, hearings and cause lists.', [
        'cases' => ['Court case', 'Case title (A v B)', 'filed,pending,part_heard,judgment_reserved,decided,appealed,closed', [
            'case_number|Case number*',
            'court|Court / division',
            'type:select=civil,criminal,family,labour,commercial,small_claims*',
            'judge|Presiding officer',
            'parties:textarea',
        ], ['icon' => 'scale', 'prefix' => 'CRT-', 'date' => 'Filed on', 'assignee' => true, 'list' => ['case_number', 'court', 'type']]],
        'hearings' => ['Hearing', 'Purpose', 'scheduled,heard,postponed,struck_off', [
            'case:record=cases|Case*',
            'time:time',
            'courtroom',
            'outcome:textarea',
        ], ['icon' => 'gavel', 'prefix' => 'HRG-', 'plural' => 'Hearings / cause list', 'date' => 'Date', 'assignee' => true, 'list' => ['case', 'time', 'courtroom']]],
    ]],

    'police-occurrence-book-case' => ['Police occurrence book & case dockets', 'siren', 'Occurrence-book entries, case dockets and exhibits.', [
        'occurrences' => ['OB entry', 'Summary', 'recorded,docket_opened,no_further_action', [
            'ob_number|OB number*',
            'time:time',
            'reported_by|Reported by',
            'location',
            'details:textarea*',
        ], ['icon' => 'notebook-pen', 'prefix' => 'OB-', 'plural' => 'Occurrence book', 'date' => 'Date', 'assignee' => true, 'list' => ['ob_number', 'location', 'reported_by']]],
        'dockets' => ['Case docket', 'Offence', 'under_investigation,to_prosecutor,in_court,closed_undetected,finalised', [
            'occurrence:record=occurrences|OB entry',
            'docket_number|Docket number*',
            'complainant',
            'suspects:textarea',
        ], ['icon' => 'folder-open', 'prefix' => 'CAS-', 'date' => 'Opened on', 'assignee' => true, 'list' => ['docket_number', 'complainant']]],
        'exhibits' => ['Exhibit', 'Description', 'booked_in,at_lab,in_court,returned,disposed', [
            'docket:record=dockets|Docket*',
            'exhibit_number|Exhibit number',
            'storage_location|Storage location',
        ], ['icon' => 'package', 'prefix' => 'EXH-', 'date' => 'Booked in on', 'assignee' => true, 'list' => ['docket', 'exhibit_number']]],
    ]],

    'land-registry-title-deeds' => ['Land registry & title deeds', 'file-badge', 'Land parcels, title deeds and registered transfers.', [
        'titles' => ['Title deed', 'Parcel / erf number', 'registered,encumbered,transferred,cancelled', [
            'title_number|Title deed number*',
            'owner*',
            'extent:number|Extent (m²)',
            'location',
            'bonds:textarea|Bonds / servitudes',
        ], ['icon' => 'file-badge', 'prefix' => 'TD-', 'date' => 'Registered on', 'list' => ['title_number', 'owner', 'extent']]],
        'transfers' => ['Transfer', 'Transfer', 'lodged,examined,registered,rejected', [
            'title:record=titles|Title deed*',
            'from_owner|From (seller)*',
            'to_owner|To (buyer)*',
            'conveyancer',
            'consideration:money|Purchase price',
        ], ['icon' => 'arrow-right-left', 'prefix' => 'TRF-', 'amount' => 'Fees', 'date' => 'Lodged on', 'assignee' => true, 'list' => ['title', 'to_owner', 'conveyancer']]],
    ]],

    'civil-registry' => ['Civil registry (births, deaths, marriages)', 'scroll-text', 'Birth, death and marriage registrations and certificates.', [
        'registrations' => ['Registration', 'Name(s)', 'registered,certificate_issued,amended,cancelled', [
            'type:select=birth,death,marriage,divorce*',
            'registration_number|Registration number',
            'event_date:date|Date of event*',
            'place|Place of event',
            'informant',
            'details:textarea|Parents / spouses / cause of death',
        ], ['icon' => 'scroll-text', 'prefix' => 'CR-', 'amount' => 'Fee', 'date' => 'Registered on', 'assignee' => true, 'list' => ['type', 'registration_number', 'event_date']]],
    ]],

    'e-procurement-tender-portal' => ['E-procurement & tenders', 'file-search', 'Tenders, supplier bids and evaluation scores.', [
        'tenders' => ['Tender', 'Tender title', 'draft,published,closed,evaluating,awarded,cancelled', [
            'tender_number|Tender number*',
            'category',
            'budget:money',
            'briefing_date:date|Briefing session',
            'documents_url:url|Tender documents',
        ], ['icon' => 'file-search', 'prefix' => 'TEN-', 'date' => 'Published on', 'due' => 'Closing date', 'assignee' => true, 'list' => ['tender_number', 'category', 'budget']]],
        'bids' => ['Bid', 'Bidder name', 'received,compliant,non_compliant,shortlisted,awarded,unsuccessful', [
            'tender:record=tenders|Tender*',
            'technical_score:number|Technical score',
            'price_score:number|Price score',
            'preference_points:number|Preference points',
            'tax_compliant:checkbox|Tax compliant',
        ], ['icon' => 'file-text', 'prefix' => 'BID-', 'contact' => 'Bidder', 'amount' => 'Bid price', 'date' => 'Received on', 'list' => ['tender', 'technical_score', 'price_score']]],
    ]],

    'traffic-fines-vehicle-licensing' => ['Traffic fines & vehicle licensing', 'car', 'Vehicle licence renewals and traffic fines.', [
        'vehicles' => ['Licensed vehicle', 'Registration', 'licensed,expired,deregistered', [
            'owner*',
            'make_model|Make & model',
            'vin|VIN',
            'licence_expiry:date|Licence expiry*',
        ], ['icon' => 'car', 'prefix' => 'VL-', 'contact' => 'Owner', 'amount' => 'Licence fee', 'list' => ['owner', 'make_model', 'licence_expiry']]],
        'fines' => ['Traffic fine', 'Notice number', 'issued,paid,contested,warrant,cancelled', [
            'vehicle:record=vehicles|Vehicle',
            'offence:select=speeding,red_light,no_licence,unroadworthy,parking,overloading,other*',
            'location',
            'officer|Officer',
        ], ['icon' => 'receipt', 'prefix' => 'FINE-', 'amount' => 'Fine', 'date' => 'Issued on', 'due' => 'Pay by', 'list' => ['vehicle', 'offence', 'location']]],
    ]],

    'grants-subsidies-management' => ['Grants & subsidies management', 'hand-coins', 'Grant programmes, applications, awards and reporting.', [
        'programmes' => ['Programme', 'Programme name', 'open,closed,completed', [
            'budget:money*',
            'eligibility:textarea',
        ], ['icon' => 'folder-tree', 'prefix' => 'GP-', 'date' => 'Opens on', 'due' => 'Closes on', 'assignee' => true, 'list' => ['budget']]],
        'applications' => ['Grant application', 'Applicant / organisation', 'submitted,screening,approved,rejected,disbursed,reporting,closed', [
            'programme:record=programmes|Programme*',
            'requested:money|Amount requested',
            'score:number',
            'report_due:date|Report due',
        ], ['icon' => 'hand-coins', 'prefix' => 'GA-', 'contact' => 'Applicant', 'amount' => 'Amount awarded', 'date' => 'Submitted on', 'assignee' => true, 'list' => ['programme', 'requested', 'score']]],
    ]],

    'data-privacy-gdpr-popia' => ['Data privacy (GDPR / POPIA)', 'lock', 'Processing register, data-subject requests and breaches.', [
        'processing' => ['Processing activity', 'Activity', 'active,retired', [
            'purpose:textarea*',
            'data_categories|Personal data categories',
            'lawful_basis:select=consent,contract,legal_obligation,vital_interest,public_task,legitimate_interest*',
            'retention|Retention period',
            'processors|Processors / third parties',
        ], ['icon' => 'database', 'prefix' => 'ROPA-', 'plural' => 'Processing register', 'assignee' => true, 'list' => ['lawful_basis', 'retention']]],
        'requests' => ['Data-subject request', 'Requester name', 'received,verifying_id,in_progress,completed,refused', [
            'type:select=access,correction,deletion,objection,portability*',
            'email:email',
        ], ['icon' => 'user-search', 'prefix' => 'DSR-', 'date' => 'Received on', 'due' => 'Respond by', 'assignee' => true, 'list' => ['type', 'email']]],
        'breaches' => ['Breach', 'What happened', 'detected,contained,notified,closed', [
            'records_affected:number|Records affected',
            'regulator_notified:checkbox|Regulator notified',
            'subjects_notified:checkbox|Data subjects notified',
            'actions:textarea',
        ], ['icon' => 'lock-open', 'prefix' => 'BR-', 'date' => 'Detected on', 'assignee' => true, 'list' => ['records_affected', 'regulator_notified']]],
    ]],

    'environmental-monitoring-permits' => ['Environmental monitoring & permits', 'leaf', 'Environmental permits, conditions and monitoring samples.', [
        'permits' => ['Environmental permit', 'Permit', 'applied,active,expired,suspended', [
            'permit_number|Permit number',
            'type:select=eia,water_use,air_emission,waste,mining,other*',
            'conditions:textarea',
        ], ['icon' => 'file-badge', 'prefix' => 'ENV-', 'date' => 'Issued on', 'due' => 'Expires on', 'assignee' => true, 'list' => ['permit_number', 'type']]],
        'samples' => ['Monitoring sample', 'Sampling point', 'collected,at_lab,compliant,non_compliant', [
            'permit:record=permits|Permit',
            'medium:select=water,air,soil,noise,dust*',
            'parameter|Parameter*',
            'result:number',
            'limit:number',
            'lab_reference|Lab reference',
        ], ['icon' => 'test-tube', 'prefix' => 'SMP-', 'date' => 'Sampled on', 'assignee' => true, 'list' => ['medium', 'parameter', 'result']]],
    ]],

    'parliament-council' => ['Parliament / council business', 'landmark', 'Sittings, motions, bills and resolutions.', [
        'sittings' => ['Sitting', 'Sitting', 'scheduled,held,adjourned,cancelled', [
            'type:select=ordinary,special,committee*',
            'venue',
            'agenda:textarea',
            'minutes_url:url|Minutes / Hansard',
        ], ['icon' => 'calendar-days', 'prefix' => 'SIT-', 'date' => 'Date', 'assignee' => true, 'list' => ['type', 'venue']]],
        'items' => ['Motion / bill', 'Title', 'tabled,first_reading,committee,second_reading,passed,rejected,withdrawn', [
            'type:select=motion,bill,question,petition,report*',
            'sitting:record=sittings|Sitting',
            'sponsor|Sponsor / member',
            'votes_for:number|Votes for',
            'votes_against:number|Votes against',
            'resolution:textarea',
        ], ['icon' => 'scroll-text', 'prefix' => 'MOT-', 'plural' => 'Motions & bills', 'date' => 'Tabled on', 'list' => ['type', 'sponsor', 'votes_for']]],
    ]],

    'prison-correctional-records' => ['Prison & correctional records', 'lock-keyhole', 'Inmate register, sentences, cells and visits.', [
        'inmates' => ['Inmate', 'Inmate name', 'remand,sentenced,released,transferred,escaped', [
            'inmate_number|Inmate number*',
            'offence',
            'sentence|Sentence',
            'cell|Cell / block',
            'release_date:date|Release date',
        ], ['icon' => 'user-round', 'prefix' => 'INM-', 'date' => 'Admitted on', 'assignee' => true, 'list' => ['inmate_number', 'cell', 'release_date']]],
        'visits' => ['Visit', 'Visitor name', 'booked,completed,refused', [
            'inmate:record=inmates|Inmate*',
            'relationship',
            'id_number|Visitor ID',
            'time:time',
        ], ['icon' => 'users', 'prefix' => 'VIS-', 'date' => 'Date', 'list' => ['inmate', 'relationship', 'time']]],
    ]],
];
