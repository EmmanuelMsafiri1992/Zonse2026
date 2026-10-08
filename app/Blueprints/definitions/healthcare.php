<?php

/*
 * Healthcare apps. Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 * Field spec: "key:type=options|Label*" (type defaults to text, * = required).
 */

return [
    'clinic' => ['Clinic & patients', 'stethoscope', 'Patient files, consultations and prescriptions for a practice of any size.', [
        'patients' => ['Patient', 'Full name', 'active,inactive,deceased', [
            'date_of_birth:date|Date of birth',
            'sex:select=female,male,other',
            'id_number|ID / passport number',
            'phone:phone',
            'medical_aid|Medical aid',
            'medical_aid_number|Member number',
            'allergies:textarea',
            'chronic_conditions:textarea|Chronic conditions',
            'next_of_kin|Next of kin',
            'next_of_kin_phone:phone|Next of kin phone',
        ], ['icon' => 'user-round', 'prefix' => 'PT-', 'contact' => 'Billing contact', 'list' => ['date_of_birth', 'phone', 'medical_aid']]],
        'visits' => ['Visit', 'Reason for visit', 'waiting,in_consultation,completed,cancelled', [
            'patient:record=patients|Patient*',
            'complaint:textarea|Presenting complaint',
            'blood_pressure|Blood pressure',
            'temperature:number|Temperature (°C)',
            'weight:number|Weight (kg)',
            'diagnosis:textarea',
            'treatment:textarea|Treatment plan',
            'follow_up:date|Follow-up date',
        ], ['icon' => 'clipboard-plus', 'prefix' => 'VIS-', 'date' => 'Visit date', 'amount' => 'Consultation fee', 'assignee' => true, 'list' => ['patient', 'diagnosis']]],
        'prescriptions' => ['Prescription', 'Medicine', 'active,completed,stopped', [
            'patient:record=patients|Patient*',
            'visit:record=visits|Visit',
            'dosage*',
            'frequency:select=once_daily,twice_daily,three_times_daily,four_times_daily,as_needed',
            'duration_days:number|Duration (days)',
            'instructions:textarea',
        ], ['icon' => 'pill', 'prefix' => 'RX-', 'date' => 'Prescribed on', 'list' => ['patient', 'dosage', 'frequency']]],
    ]],

    'patient-queue' => ['Patient queue & tokens', 'list-ordered', 'Walk-in tokens and a live waiting list for reception and doctors.', [
        'tokens' => ['Token', 'Patient name', 'waiting,called,in_service,done,no_show', [
            'service:select=consultation,pharmacy,laboratory,dressing,vaccination|Service',
            'priority:select=normal,elderly,emergency',
            'room|Room / counter',
            'notes:textarea',
        ], ['icon' => 'ticket', 'prefix' => 'Q-', 'date' => 'Date', 'assignee' => true, 'list' => ['service', 'priority', 'room']]],
    ]],

    'medical-claims' => ['Medical aid & insurance claims', 'file-check', 'Track claims submitted to medical aids until they are paid or rejected.', [
        'claims' => ['Claim', 'Patient name', 'draft,submitted,queried,paid,rejected', [
            'medical_aid|Medical aid*',
            'member_number|Member number',
            'icd10_codes|ICD-10 codes',
            'tariff_codes|Tariff codes',
            'amount_paid:money|Amount paid',
            'reference|Medical aid reference',
            'query_reason:textarea|Query / rejection reason',
        ], ['icon' => 'file-check', 'prefix' => 'CLM-', 'contact' => 'Patient contact', 'amount' => 'Claimed amount', 'date' => 'Service date', 'due' => 'Follow up by', 'list' => ['medical_aid', 'reference']]],
    ]],

    'laboratory' => ['Laboratory', 'flask-conical', 'Test requests, samples and results.', [
        'requests' => ['Lab request', 'Patient name', 'requested,sample_collected,processing,resulted,cancelled', [
            'tests:textarea|Tests requested*',
            'sample_type:select=blood,urine,stool,swab,sputum,other|Sample type',
            'urgency:select=routine,urgent,stat',
            'referred_by|Referred by',
            'results:textarea',
            'abnormal:checkbox|Abnormal result',
        ], ['icon' => 'test-tube', 'prefix' => 'LAB-', 'contact' => 'Patient contact', 'amount' => 'Fee', 'date' => 'Requested on', 'due' => 'Results due', 'assignee' => true, 'list' => ['sample_type', 'urgency']]],
    ]],

    'veterinary' => ['Veterinary clinic', 'paw-print', 'Animals, owners, consultations and vaccinations.', [
        'animals' => ['Animal', 'Name', 'active,deceased,transferred', [
            'species:select=dog,cat,cattle,goat,sheep,pig,poultry,horse,other*',
            'breed',
            'sex:select=male,female,neutered_male,spayed_female',
            'date_of_birth:date|Date of birth',
            'colour|Colour / markings',
            'microchip|Microchip / tag number',
        ], ['icon' => 'paw-print', 'prefix' => 'AN-', 'contact' => 'Owner', 'list' => ['species', 'breed']]],
        'consultations' => ['Consultation', 'Reason', 'open,completed,cancelled', [
            'animal:record=animals|Animal*',
            'weight:number|Weight (kg)',
            'findings:textarea',
            'treatment:textarea',
            'follow_up:date|Follow-up date',
        ], ['icon' => 'stethoscope', 'prefix' => 'VC-', 'date' => 'Date', 'amount' => 'Fee', 'assignee' => true, 'list' => ['animal', 'follow_up']]],
        'vaccinations' => ['Vaccination', 'Vaccine', 'given,due,overdue', [
            'animal:record=animals|Animal*',
            'batch_number|Batch number',
        ], ['icon' => 'syringe', 'prefix' => 'VAX-', 'date' => 'Given on', 'due' => 'Next due', 'list' => ['animal', 'batch_number']]],
    ]],

    'patient-appointments' => ['Patient appointments & reminders', 'calendar-clock', 'Patient bookings, reminders and a waitlist for cancellations.', [
        'appointments' => ['Appointment', 'Patient name', 'booked,confirmed,arrived,seen,no_show,cancelled', [
            'start_time:time|Time*',
            'practitioner:user|Practitioner*',
            'reason',
            'phone:phone|Patient phone',
            'reminder:select=none,sms,whatsapp,email|Reminder',
            'reminder_sent:checkbox|Reminder sent',
        ], ['icon' => 'calendar-clock', 'prefix' => 'APT-', 'contact' => 'Patient', 'date' => 'Date', 'list' => ['start_time', 'practitioner', 'reminder']]],
        'waitlist' => ['Waitlist entry', 'Patient name', 'waiting,offered,booked,removed', [
            'phone:phone*',
            'preferred_times|Preferred days / times',
            'urgency:select=routine,soon,urgent',
        ], ['icon' => 'list-ordered', 'prefix' => 'WL-', 'plural' => 'Waitlist', 'date' => 'Added on', 'list' => ['phone', 'urgency']]],
    ]],

    'emr' => ['Medical records (EMR)', 'clipboard-plus', 'Encounters, diagnoses with ICD-10 codes, allergies and history.', [
        'records' => ['Patient chart', 'Patient name', 'active,inactive,deceased', [
            'file_number|File number*',
            'date_of_birth:date|Date of birth',
            'blood_group:select=a_pos,a_neg,b_pos,b_neg,ab_pos,ab_neg,o_pos,o_neg|Blood group',
            'allergies:textarea',
            'chronic_conditions:textarea|Chronic conditions',
            'medical_aid|Medical aid / insurer',
        ], ['icon' => 'folder-heart', 'prefix' => 'MRN-', 'contact' => 'Patient', 'list' => ['file_number', 'date_of_birth', 'blood_group']]],
        'encounters' => ['Encounter', 'Presenting complaint', 'open,signed', [
            'chart:record=records|Patient chart*',
            'history:textarea',
            'examination:textarea',
            'icd10|ICD-10 code(s)',
            'diagnosis:textarea*',
            'plan:textarea|Plan / treatment',
        ], ['icon' => 'clipboard-plus', 'prefix' => 'ENC-', 'date' => 'Date', 'assignee' => true, 'list' => ['chart', 'icd10']]],
    ]],

    'pharmacy' => ['Pharmacy', 'pill', 'Dispensing, drug stock and a controlled-drugs register.', [
        'drugs' => ['Drug', 'Drug name', 'in_stock,low_stock,out_of_stock,discontinued', [
            'strength',
            'form:select=tablet,capsule,syrup,injection,cream,drops,inhaler,other',
            'quantity:number|Stock on hand',
            'reorder_level:number|Reorder level',
            'schedule:select=otc,prescription,controlled',
            'selling_price:money|Selling price',
        ], ['icon' => 'pill', 'prefix' => 'DRG-', 'due' => 'Nearest expiry', 'list' => ['strength', 'quantity', 'schedule']]],
        'dispensings' => ['Dispensing', 'Patient name', 'dispensed,partly_dispensed,returned', [
            'drug:record=drugs|Drug*',
            'quantity:number*',
            'dosage|Directions',
            'prescriber|Prescriber',
            'script_number|Script number',
        ], ['icon' => 'hand-heart', 'prefix' => 'DSP-', 'contact' => 'Patient', 'amount' => 'Charge', 'date' => 'Dispensed on', 'assignee' => true, 'list' => ['drug', 'quantity', 'prescriber']]],
        'controlled' => ['Controlled-drug entry', 'Entry', 'recorded,verified', [
            'drug:record=drugs|Drug*',
            'movement:select=received,dispensed,destroyed,returned*',
            'quantity:number*',
            'balance:number|Running balance',
            'witness:user|Witness',
        ], ['icon' => 'shield-alert', 'prefix' => 'CD-', 'plural' => 'Controlled-drug register', 'date' => 'Date', 'assignee' => true, 'list' => ['drug', 'movement', 'balance']]],
    ]],

    'hospital' => ['Hospital & wards', 'hospital', 'Admissions, beds and wards, theatre bookings and discharges.', [
        'wards' => ['Ward', 'Ward name', 'open,closed', [
            'type:select=general,maternity,paediatric,surgical,icu,private*',
            'beds:number|Beds',
            'occupied:number|Beds occupied',
        ], ['icon' => 'bed-double', 'prefix' => 'WRD-', 'list' => ['type', 'beds', 'occupied']]],
        'admissions' => ['Admission', 'Patient name', 'admitted,transferred,discharged,deceased', [
            'ward:record=wards|Ward*',
            'bed|Bed number',
            'diagnosis:textarea',
            'consultant:user|Consultant',
            'nursing_notes:textarea|Nursing notes',
            'discharge_summary:textarea|Discharge summary',
        ], ['icon' => 'bed', 'prefix' => 'ADM-', 'contact' => 'Patient', 'amount' => 'Bill to date', 'date' => 'Admitted on', 'due' => 'Expected discharge', 'list' => ['ward', 'bed', 'consultant']]],
        'theatre' => ['Theatre booking', 'Procedure', 'scheduled,in_theatre,completed,postponed', [
            'admission:record=admissions|Admission*',
            'surgeon:user|Surgeon*',
            'start_time:time|Start time',
            'anaesthetist',
            'theatre_number|Theatre',
        ], ['icon' => 'scissors', 'prefix' => 'THR-', 'date' => 'Date', 'list' => ['admission', 'surgeon', 'start_time']]],
    ]],

    'telemedicine' => ['Telemedicine', 'video', 'Video consultations and electronic prescriptions.', [
        'consults' => ['Video consult', 'Patient name', 'booked,waiting_room,in_call,completed,no_show', [
            'start_time:time|Time*',
            'doctor:user|Doctor*',
            'meeting_link:url|Video link',
            'notes:textarea|Consultation notes',
        ], ['icon' => 'video', 'prefix' => 'TEL-', 'contact' => 'Patient', 'amount' => 'Fee', 'date' => 'Date', 'list' => ['start_time', 'doctor']]],
        'eprescriptions' => ['E-prescription', 'Medication', 'issued,sent_to_pharmacy,dispensed,cancelled', [
            'consult:record=consults|Consult*',
            'dosage|Dosage & directions*',
            'pharmacy|Pharmacy',
        ], ['icon' => 'file-heart', 'prefix' => 'ERX-', 'plural' => 'E-prescriptions', 'date' => 'Issued on', 'list' => ['consult', 'pharmacy']]],
    ]],

    'specialist-practice' => ['Dental, optometry & physio', 'smile', 'Specialist treatment plans and procedures for dental, eye and physio practices.', [
        'plans' => ['Treatment plan', 'Patient name', 'proposed,accepted,in_progress,completed', [
            'speciality:select=dental,optometry,physiotherapy,dermatology,other*',
            'findings:textarea|Findings / charting*',
            'plan:textarea|Planned treatment',
            'sessions:number|Sessions planned',
        ], ['icon' => 'smile', 'prefix' => 'TP-', 'contact' => 'Patient', 'amount' => 'Estimate', 'date' => 'Date', 'assignee' => true, 'list' => ['speciality', 'sessions']]],
        'procedures' => ['Procedure', 'Procedure', 'scheduled,done,cancelled', [
            'plan:record=plans|Treatment plan*',
            'code|Procedure code',
            'tooth_or_site|Tooth / eye / site',
            'lab_work|Lab work',
        ], ['icon' => 'stethoscope', 'prefix' => 'PRC-', 'amount' => 'Fee', 'date' => 'Date', 'assignee' => true, 'list' => ['plan', 'code', 'tooth_or_site']]],
    ]],

    'radiology-diagnostic-imaging-ris' => ['Radiology & diagnostic imaging (RIS)', 'scan', 'Imaging requests, scans and radiologist reports.', [
        'studies' => ['Imaging study', 'Patient name', 'requested,booked,scanned,reported,cancelled', [
            'modality:select=x_ray,ultrasound,ct,mri,mammography,fluoroscopy*',
            'body_part|Body part*',
            'referring_doctor|Referring doctor',
            'clinical_info:textarea|Clinical information',
            'dicom_link:url|DICOM / PACS link',
            'report:textarea|Radiologist report',
        ], ['icon' => 'scan', 'prefix' => 'IMG-', 'contact' => 'Patient', 'amount' => 'Fee', 'date' => 'Scan date', 'assignee' => true, 'list' => ['modality', 'body_part', 'referring_doctor']]],
    ]],

    'blood-bank' => ['Blood bank', 'droplet', 'Donors, blood units in stock and issues to patients.', [
        'donors' => ['Donor', 'Donor name', 'eligible,deferred,inactive', [
            'blood_group:select=a_pos,a_neg,b_pos,b_neg,ab_pos,ab_neg,o_pos,o_neg|Blood group*',
            'phone:phone',
            'last_donation:date|Last donation',
        ], ['icon' => 'heart-handshake', 'prefix' => 'DNR-', 'list' => ['blood_group', 'last_donation']]],
        'units' => ['Blood unit', 'Unit number', 'quarantine,available,reserved,issued,expired,discarded', [
            'donor:record=donors|Donor',
            'blood_group:select=a_pos,a_neg,b_pos,b_neg,ab_pos,ab_neg,o_pos,o_neg|Blood group*',
            'component:select=whole_blood,red_cells,plasma,platelets*',
            'screening:select=pending,negative,reactive',
            'issued_to|Issued to (patient / ward)',
        ], ['icon' => 'droplet', 'prefix' => 'BU-', 'date' => 'Collected on', 'due' => 'Expiry date', 'list' => ['blood_group', 'component', 'screening']]],
    ]],

    'ambulance-emergency-dispatch' => ['Ambulance & emergency dispatch', 'ambulance', 'Emergency calls, ambulance dispatch and patient care reports.', [
        'incidents' => ['Emergency call', 'Nature of emergency', 'received,dispatched,on_scene,transporting,at_hospital,closed', [
            'priority:select=p1_critical,p2_urgent,p3_routine*',
            'location:textarea|Location*',
            'caller_phone:phone|Caller phone',
            'ambulance|Ambulance',
            'hospital|Taken to',
            'patient_report:textarea|Patient care report',
        ], ['icon' => 'ambulance', 'prefix' => 'EMS-', 'contact' => 'Patient', 'amount' => 'Charge', 'date' => 'Call time', 'assignee' => true, 'list' => ['priority', 'ambulance', 'hospital']]],
    ]],

    'mental-health-counselling-therapy' => ['Mental health / counselling & therapy practice', 'brain', 'Clients, therapy sessions and confidential session notes.', [
        'clients' => ['Client', 'Client name', 'intake,active,on_hold,discharged', [
            'referral_source|Referred by',
            'presenting_issue:textarea|Presenting issue',
            'risk_level:select=low,moderate,high',
            'consent_signed:checkbox|Consent signed',
        ], ['icon' => 'user-round', 'prefix' => 'CLT-', 'contact' => 'Contact', 'date' => 'Intake date', 'assignee' => true, 'list' => ['risk_level', 'consent_signed']]],
        'sessions' => ['Session', 'Session focus', 'booked,attended,missed,cancelled', [
            'client:record=clients|Client*',
            'modality:select=in_person,video,phone,group',
            'notes:textarea|Session notes (confidential)',
            'homework:textarea',
        ], ['icon' => 'brain', 'prefix' => 'SES-', 'amount' => 'Fee', 'date' => 'Date', 'assignee' => true, 'list' => ['client', 'modality']]],
    ]],

    'nutrition-dietitian-plans' => ['Nutrition & dietitian plans', 'salad', 'Client assessments, meal plans and follow-ups.', [
        'clients' => ['Client', 'Client name', 'active,completed', [
            'goal:select=weight_loss,weight_gain,diabetes,heart_health,sports,pregnancy,other',
            'height:number|Height (cm)',
            'start_weight:number|Start weight (kg)',
            'conditions:textarea',
        ], ['icon' => 'user-round', 'prefix' => 'NC-', 'contact' => 'Contact', 'date' => 'Start date', 'assignee' => true, 'list' => ['goal', 'start_weight']]],
        'plans' => ['Meal plan', 'Plan', 'draft,active,ended', [
            'client:record=clients|Client*',
            'calories:number|Daily calories',
            'meals:textarea|Meals*',
        ], ['icon' => 'salad', 'prefix' => 'MP-', 'date' => 'Start date', 'due' => 'Review date', 'list' => ['client', 'calories']]],
        'checkins' => ['Check-in', 'Check-in', 'recorded', [
            'client:record=clients|Client*',
            'weight:number|Weight (kg)',
            'notes:textarea',
        ], ['icon' => 'scale', 'prefix' => 'CI-', 'date' => 'Date', 'list' => ['client', 'weight']]],
    ]],

    'rehabilitation-occupational-therapy' => ['Rehabilitation & occupational therapy', 'accessibility', 'Rehab goals, therapy sessions and progress measures.', [
        'cases' => ['Rehab case', 'Patient name', 'assessment,active,discharged', [
            'condition*',
            'goals:textarea',
            'funder|Funder / insurer',
        ], ['icon' => 'accessibility', 'prefix' => 'RHB-', 'contact' => 'Patient', 'date' => 'Start date', 'assignee' => true, 'list' => ['condition', 'funder']]],
        'sessions' => ['Therapy session', 'Activities', 'attended,missed', [
            'case:record=cases|Rehab case*',
            'progress_score:number|Progress score',
            'notes:textarea',
        ], ['icon' => 'activity', 'prefix' => 'RS-', 'amount' => 'Fee', 'date' => 'Date', 'assignee' => true, 'list' => ['case', 'progress_score']]],
    ]],

    'home-care-elderly-care' => ['Home care / elderly care / hospice visits & carers', 'hand-heart', 'Care clients, care plans and carer visits.', [
        'clients' => ['Care client', 'Client name', 'active,hospital,paused,ended', [
            'address:textarea*',
            'care_needs:textarea|Care needs',
            'medication:textarea',
            'next_of_kin|Next of kin',
            'visits_per_day:number|Visits per day',
        ], ['icon' => 'heart-handshake', 'prefix' => 'HC-', 'contact' => 'Family contact', 'amount' => 'Monthly fee', 'date' => 'Start date', 'list' => ['visits_per_day', 'next_of_kin']]],
        'visits' => ['Carer visit', 'Visit', 'scheduled,in_progress,completed,missed', [
            'client:record=clients|Client*',
            'carer:user|Carer*',
            'start_time:time|Start',
            'tasks_done:textarea|Tasks done',
            'concerns:textarea',
        ], ['icon' => 'hand-heart', 'prefix' => 'CV-', 'date' => 'Date', 'list' => ['client', 'carer', 'start_time']]],
    ]],

    'maternity-antenatal-care' => ['Maternity & antenatal care', 'baby', 'Pregnancies, antenatal visits and deliveries.', [
        'pregnancies' => ['Pregnancy', 'Mother\'s name', 'antenatal,delivered,closed', [
            'gravida:number',
            'para:number',
            'lmp:date|Last menstrual period',
            'risk:select=low,high',
            'blood_group|Blood group',
        ], ['icon' => 'heart-pulse', 'prefix' => 'ANC-', 'contact' => 'Mother', 'date' => 'Booked on', 'due' => 'Expected delivery', 'assignee' => true, 'list' => ['gravida', 'para', 'risk']]],
        'visits' => ['Antenatal visit', 'Visit', 'done,missed', [
            'pregnancy:record=pregnancies|Pregnancy*',
            'weeks:number|Gestation (weeks)',
            'blood_pressure|Blood pressure',
            'weight:number|Weight (kg)',
            'notes:textarea',
        ], ['icon' => 'stethoscope', 'prefix' => 'AV-', 'date' => 'Date', 'assignee' => true, 'list' => ['pregnancy', 'weeks', 'blood_pressure']]],
        'deliveries' => ['Delivery', 'Baby', 'live_birth,stillbirth', [
            'pregnancy:record=pregnancies|Pregnancy*',
            'mode:select=normal,assisted,caesarean*',
            'birth_weight:number|Birth weight (kg)',
            'apgar|Apgar score',
            'sex:select=female,male',
        ], ['icon' => 'baby', 'prefix' => 'DEL-', 'plural' => 'Deliveries', 'date' => 'Delivered on', 'assignee' => true, 'list' => ['pregnancy', 'mode', 'birth_weight']]],
    ]],

    'immunisation-registers-community-health' => ['Immunisation registers & community health workers', 'syringe', 'Child immunisation registers and community health worker visits.', [
        'children' => ['Child', 'Child name', 'up_to_date,due,defaulter', [
            'date_of_birth:date|Date of birth*',
            'caregiver|Caregiver',
            'village',
        ], ['icon' => 'baby', 'prefix' => 'CH-', 'plural' => 'Children', 'contact' => 'Caregiver contact', 'due' => 'Next dose due', 'list' => ['date_of_birth', 'village']]],
        'doses' => ['Dose', 'Vaccine', 'given,missed', [
            'child:record=children|Child*',
            'dose_number:number|Dose number',
            'batch|Batch number',
            'site|Facility / outreach site',
        ], ['icon' => 'syringe', 'prefix' => 'DOS-', 'date' => 'Given on', 'assignee' => true, 'list' => ['child', 'dose_number', 'site']]],
        'chw_visits' => ['CHW visit', 'Household', 'visited,referred,follow_up', [
            'village',
            'reason:select=immunisation,malaria,nutrition,maternal,tb_hiv,general*',
            'referral|Referred to',
            'notes:textarea',
        ], ['icon' => 'house', 'prefix' => 'CHW-', 'plural' => 'CHW visits', 'date' => 'Date', 'assignee' => true, 'list' => ['village', 'reason']]],
    ]],

    'medical-supplies-distribution' => ['Medical supplies distribution', 'briefcase-medical', 'Supply orders from facilities and deliveries made.', [
        'orders' => ['Supply order', 'Facility', 'received,approved,picked,delivered,cancelled', [
            'items:textarea|Items & quantities*',
            'urgency:select=routine,urgent,emergency',
            'cold_chain:checkbox|Needs cold chain',
            'delivery_note|Delivery note',
        ], ['icon' => 'briefcase-medical', 'prefix' => 'MSO-', 'contact' => 'Facility contact', 'amount' => 'Order value', 'date' => 'Ordered on', 'due' => 'Deliver by', 'assignee' => true, 'list' => ['urgency', 'cold_chain']]],
    ]],

    'patient-portal' => ['Patient portal', 'user-round', 'Patient portal accounts and the requests patients send.', [
        'accounts' => ['Portal account', 'Patient name', 'invited,active,disabled', [
            'email:email*',
            'phone:phone',
            'last_login:datetime|Last login',
        ], ['icon' => 'user-round', 'prefix' => 'PPA-', 'contact' => 'Patient', 'list' => ['email', 'last_login']]],
        'requests' => ['Patient request', 'Subject', 'new,in_progress,done', [
            'account:record=accounts|Account*',
            'type:select=appointment,repeat_prescription,results,records_copy,question*',
            'message:textarea*',
            'reply:textarea',
        ], ['icon' => 'inbox', 'prefix' => 'PPR-', 'date' => 'Received on', 'assignee' => true, 'list' => ['account', 'type']]],
    ]],

    'medical-billing-coding-for' => ['Medical billing & coding for insurers', 'file-check', 'Coded claims to medical aids and insurers, with remittances.', [
        'claims' => ['Claim', 'Patient name', 'draft,submitted,accepted,part_paid,paid,rejected,resubmitted', [
            'insurer*',
            'member_number|Member number*',
            'icd10|ICD-10 codes*',
            'procedure_codes|Procedure / tariff codes',
            'paid_amount:money|Amount paid',
            'rejection_reason:textarea|Rejection reason',
        ], ['icon' => 'file-check', 'prefix' => 'MBC-', 'contact' => 'Patient', 'amount' => 'Claimed', 'date' => 'Service date', 'due' => 'Submit by', 'assignee' => true, 'list' => ['insurer', 'icd10', 'paid_amount']]],
    ]],
];
