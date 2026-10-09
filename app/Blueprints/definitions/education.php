<?php

use App\Blueprints\Logic\DaycareLogic;
use App\Blueprints\Logic\DrivingSchoolLogic;
use App\Blueprints\Logic\ExamsLogic;
use App\Blueprints\Logic\LibraryLogic;
use App\Blueprints\Logic\LmsLogic;
use App\Blueprints\Logic\SchoolLogic;
use App\Blueprints\Logic\TimetableLogic;

/*
 * Education apps. Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'school' => ['School management', 'school', 'Learners, classes, guardians and fee accounts for primary and secondary schools.', [
        'students' => ['Student', 'Full name', 'enrolled,suspended,graduated,left', [
            'admission_number|Admission number',
            'class:record=classes|Class',
            'date_of_birth:date|Date of birth',
            'sex:select=female,male',
            'guardian_name|Guardian name',
            'guardian_phone:phone|Guardian phone',
            'medical_notes:textarea|Medical notes',
        ], ['icon' => 'user-round', 'prefix' => 'STU-', 'contact' => 'Fee payer', 'date' => 'Admitted on', 'list' => ['admission_number', 'class', 'guardian_phone']]],
        'classes' => ['Class', 'Class name', 'active,archived', [
            'grade|Grade / form*',
            'room',
            'capacity:number',
            'class_teacher:user|Class teacher',
        ], ['icon' => 'presentation', 'prefix' => 'CLS-', 'plural' => 'Classes', 'list' => ['grade', 'room', 'class_teacher']]],
        'fees' => ['Fee account', 'Description', 'unpaid,part_paid,paid,waived', [
            'student:record=students|Student*',
            'term:select=term_1,term_2,term_3,annual',
            'paid_to_date:money|Paid to date',
        ], ['icon' => 'wallet', 'prefix' => 'FEE-', 'bill' => ['via' => 'student', 'sent' => 'unpaid', 'overdue' => 'unpaid', 'partial' => 'part_paid', 'paid' => 'paid'], 'amount' => 'Amount due', 'due' => 'Due date', 'list' => ['student', 'term', 'paid_to_date']]],
    ], ['depends' => ['contacts', 'invoicing'], 'logic' => SchoolLogic::class]],

    'library' => ['Library', 'library', 'Book catalogue, members and loans.', [
        'books' => ['Book', 'Title', 'available,on_loan,lost,withdrawn', [
            'author*',
            'isbn|ISBN',
            'category',
            'shelf|Shelf / location',
            'copies:number',
        ], ['icon' => 'book', 'prefix' => 'BK-', 'list' => ['author', 'category', 'shelf']]],
        'loans' => ['Loan', 'Borrower', 'on_loan,returned,overdue,lost', [
            'book:record=books|Book*',
            'returned_on:date|Returned on',
            'fine:money',
        ], ['icon' => 'book-open', 'prefix' => 'LN-', 'contact' => 'Borrower contact', 'date' => 'Borrowed on', 'due' => 'Due back', 'list' => ['book', 'returned_on']]],
    ], ['logic' => LibraryLogic::class]],

    'daycare' => ['Nursery & daycare', 'baby', 'Children, guardians, daily attendance and incidents.', [
        'children' => ['Child', 'Full name', 'enrolled,waitlisted,left', [
            'date_of_birth:date|Date of birth*',
            'room:select=babies,toddlers,preschool',
            'guardian_name|Guardian name',
            'guardian_phone:phone|Guardian phone',
            'authorised_pickup:textarea|Authorised to collect',
            'allergies:textarea',
        ], ['icon' => 'baby', 'prefix' => 'CH-', 'plural' => 'Children', 'contact' => 'Fee payer', 'list' => ['room', 'guardian_phone']]],
        'incidents' => ['Incident', 'What happened', 'open,parent_informed,closed', [
            'child:record=children|Child*',
            'details:textarea*',
            'action_taken:textarea|Action taken',
        ], ['icon' => 'alert-triangle', 'prefix' => 'INC-', 'date' => 'Date', 'assignee' => true, 'list' => ['child']]],
    ], ['logic' => DaycareLogic::class]],

    'driving-school' => ['Driving school', 'car-front', 'Learners, lessons, vehicles and test bookings.', [
        'learners' => ['Learner', 'Full name', 'active,test_booked,licensed,dropped', [
            'licence_code:select=code_1,code_8,code_10,code_14|Licence code',
            'learner_licence|Learner licence number',
            'phone:phone',
            'lessons_bought:number|Lessons bought',
        ], ['icon' => 'user-round', 'prefix' => 'DL-', 'contact' => 'Contact', 'amount' => 'Package price', 'list' => ['licence_code', 'lessons_bought']]],
        'lessons' => ['Lesson', 'Lesson topic', 'booked,completed,missed,cancelled', [
            'learner:record=learners|Learner*',
            'start_time:time|Start time',
            'vehicle|Vehicle',
            'feedback:textarea',
        ], ['icon' => 'car-front', 'prefix' => 'LS-', 'date' => 'Lesson date', 'assignee' => true, 'list' => ['learner', 'start_time', 'vehicle']]],
    ], ['logic' => DrivingSchoolLogic::class]],

    'lms' => ['Learning management (LMS)', 'monitor-play', 'Online courses, lessons and learner enrolments.', [
        'courses' => ['Course', 'Course title', 'draft,published,archived', [
            'category',
            'level:select=beginner,intermediate,advanced',
            'price:money',
            'summary:textarea',
        ], ['icon' => 'monitor-play', 'prefix' => 'CRS-', 'assignee' => true, 'list' => ['category', 'level', 'price']]],
        'lessons' => ['Lesson', 'Lesson title', 'draft,published', [
            'course:record=courses|Course*',
            'order:number|Order',
            'video_url:url|Video link',
            'content:textarea',
        ], ['icon' => 'play', 'prefix' => 'LSN-', 'list' => ['course', 'order']]],
        'enrolments' => ['Enrolment', 'Learner name', 'enrolled,in_progress,completed,dropped', [
            'course:record=courses|Course*',
            'progress:number|Progress %',
            'email:email',
        ], ['icon' => 'user-plus', 'prefix' => 'ENR-', 'contact' => 'Learner', 'amount' => 'Paid', 'date' => 'Enrolled on', 'list' => ['course', 'progress']]],
    ], ['logic' => LmsLogic::class]],

    'exams' => ['Exams & report cards', 'file-badge', 'Exams, marks and report cards.', [
        'exams' => ['Exam', 'Exam name', 'scheduled,marking,published', [
            'term',
            'class_name|Class / grade',
            'subject*',
            'max_mark:number|Maximum mark',
        ], ['icon' => 'file-pen', 'prefix' => 'EXM-', 'date' => 'Exam date', 'assignee' => true, 'list' => ['class_name', 'subject', 'term']]],
        'marks' => ['Mark', 'Student name', 'entered,moderated', [
            'exam:record=exams|Exam*',
            'mark:number|Mark*',
            'grade',
            'comment',
        ], ['icon' => 'check-check', 'prefix' => 'MRK-', 'list' => ['exam', 'mark', 'grade']]],
        'reports' => ['Report card', 'Student name', 'draft,issued', [
            'term*',
            'class_name|Class / grade',
            'position:number|Position in class',
            'average:number|Average %',
            'teacher_comment:textarea|Teacher\'s comment',
            'head_comment:textarea|Head\'s comment',
        ], ['icon' => 'file-badge', 'prefix' => 'RPT-', 'contact' => 'Parent', 'date' => 'Issued on', 'list' => ['term', 'class_name', 'average']]],
    ], ['logic' => ExamsLogic::class]],

    'timetable' => ['Timetabling', 'calendar-range', 'Lesson periods by class, teacher and room.', [
        'periods' => ['Period', 'Subject', 'active,cancelled', [
            'class_name|Class / grade*',
            'day:select=monday,tuesday,wednesday,thursday,friday,saturday*',
            'start_time:time|Start*',
            'end_time:time|End*',
            'teacher:user|Teacher',
            'room',
        ], ['icon' => 'calendar-range', 'prefix' => 'PER-', 'list' => ['class_name', 'day', 'start_time', 'teacher']]],
    ], ['logic' => TimetableLogic::class]],

    'tutoring' => ['Tutoring & lessons', 'presentation', 'Students, lesson bookings and lesson packages.', [
        'students' => ['Student', 'Student name', 'active,paused,finished', [
            'subjects',
            'level|Grade / level',
            'parent|Parent / guardian',
            'phone:phone',
        ], ['icon' => 'user-round', 'prefix' => 'STU-', 'contact' => 'Payer', 'list' => ['subjects', 'level']]],
        'lessons' => ['Lesson', 'Topic', 'booked,done,cancelled,no_show', [
            'student:record=students|Student*',
            'start_time:time|Time*',
            'duration:number|Duration (minutes)',
            'mode:select=in_person,online',
            'notes:textarea',
        ], ['icon' => 'presentation', 'prefix' => 'LES-', 'amount' => 'Fee', 'date' => 'Date', 'assignee' => true, 'list' => ['student', 'start_time', 'mode']]],
    ]],

    'parent-portal' => ['Parent & student portal', 'users-round', 'Portal accounts for parents and students, and the messages they send.', [
        'accounts' => ['Portal account', 'Name', 'invited,active,disabled', [
            'role:select=parent,student*',
            'children|Children / student number',
            'email:email',
            'phone:phone',
        ], ['icon' => 'user-round', 'prefix' => 'PA-', 'contact' => 'Contact', 'list' => ['role', 'children']]],
        'messages' => ['Message', 'Subject', 'new,replied,closed', [
            'account:record=accounts|Account*',
            'type:select=absence_note,fee_query,meeting_request,general*',
            'message:textarea*',
            'reply:textarea',
        ], ['icon' => 'mail', 'prefix' => 'PM-', 'date' => 'Received on', 'assignee' => true, 'list' => ['account', 'type']]],
    ]],

    'certificates-verification' => ['Certificates & verification', 'award', 'Issued certificates with a verification code anyone can check.', [
        'certificates' => ['Certificate', 'Holder name', 'issued,revoked', [
            'programme|Course / programme*',
            'verification_code|Verification code*',
            'grade|Grade / classification',
            'file_url:url|Certificate PDF',
        ], ['icon' => 'award', 'prefix' => 'CERT-', 'contact' => 'Holder', 'date' => 'Issued on', 'due' => 'Valid until', 'list' => ['programme', 'verification_code']]],
        'checks' => ['Verification request', 'Requested by', 'verified,not_found,mismatch', [
            'certificate:record=certificates|Certificate',
            'code_checked|Code checked*',
            'organisation',
        ], ['icon' => 'shield-check', 'prefix' => 'VER-', 'date' => 'Checked on', 'list' => ['code_checked', 'organisation']]],
    ]],

    'university' => ['University & college', 'school', 'Faculties, programmes, semester registration, transcripts and alumni.', [
        'programmes' => ['Programme', 'Programme name', 'active,closed', [
            'faculty*',
            'level:select=certificate,diploma,bachelors,honours,masters,doctorate*',
            'duration:number|Duration (years)',
        ], ['icon' => 'school', 'prefix' => 'PRG-', 'amount' => 'Annual tuition', 'list' => ['faculty', 'level', 'duration']]],
        'registrations' => ['Registration', 'Student name', 'pending,registered,deferred,withdrawn,graduated', [
            'programme:record=programmes|Programme*',
            'student_number|Student number*',
            'semester|Year & semester',
            'courses:textarea|Courses registered',
            'gpa:number|GPA',
        ], ['icon' => 'id-card', 'prefix' => 'UREG-', 'contact' => 'Student', 'amount' => 'Fees billed', 'date' => 'Registered on', 'list' => ['programme', 'student_number', 'semester']]],
        'alumni' => ['Alumnus', 'Name', 'active,lost_contact', [
            'programme:record=programmes|Programme',
            'graduation_year:number|Graduation year',
            'employer',
            'email:email',
        ], ['icon' => 'graduation-cap', 'prefix' => 'ALM-', 'plural' => 'Alumni', 'list' => ['programme', 'graduation_year', 'employer']]],
    ]],

    'hostel' => ['Hostel & boarding', 'bed', 'Hostel rooms, bed allocations and exeat (leave-out) passes.', [
        'rooms' => ['Room', 'Room / dorm', 'available,full,closed', [
            'hostel_name|Hostel / house*',
            'gender:select=female,male,mixed',
            'beds:number*',
            'occupied:number',
        ], ['icon' => 'door-closed', 'prefix' => 'RM-', 'list' => ['hostel_name', 'beds', 'occupied']]],
        'allocations' => ['Allocation', 'Student name', 'active,ended', [
            'room:record=rooms|Room*',
            'bed|Bed number',
        ], ['icon' => 'bed', 'prefix' => 'ALC-', 'contact' => 'Parent', 'amount' => 'Boarding fee', 'date' => 'From', 'due' => 'Until', 'list' => ['room', 'bed']]],
        'exeats' => ['Exeat', 'Student name', 'requested,approved,out,returned,declined', [
            'destination',
            'collected_by|Collected by',
        ], ['icon' => 'log-out', 'prefix' => 'EXT-', 'date' => 'Leaving on', 'due' => 'Returning on', 'assignee' => true, 'list' => ['destination', 'collected_by']]],
    ]],

    'school-transport' => ['School transport', 'bus', 'School bus routes, pupils on each route and trip logs.', [
        'routes' => ['Bus route', 'Route name', 'active,suspended', [
            'bus|Bus / registration',
            'driver',
            'stops:textarea',
            'morning_departure:time|Morning departure',
        ], ['icon' => 'route', 'prefix' => 'BR-', 'list' => ['bus', 'driver']]],
        'riders' => ['Rider', 'Pupil name', 'active,stopped', [
            'route:record=routes|Route*',
            'stop|Pick-up stop',
        ], ['icon' => 'backpack', 'prefix' => 'RDR-', 'contact' => 'Parent', 'amount' => 'Transport fee', 'list' => ['route', 'stop']]],
        'trips' => ['Trip', 'Trip', 'completed,delayed,cancelled', [
            'route:record=routes|Route*',
            'direction:select=morning,afternoon*',
            'pupils:number|Pupils carried',
            'notes:textarea',
        ], ['icon' => 'bus', 'prefix' => 'TRP-', 'date' => 'Date', 'list' => ['route', 'direction', 'pupils']]],
    ]],

    'canteen' => ['Canteen', 'utensils', 'Tuck-shop sales and prepaid cashless cards for students.', [
        'cards' => ['Cashless card', 'Student name', 'active,blocked,lost', [
            'card_number|Card number*',
            'balance:money',
            'daily_limit:money|Daily limit',
            'allergies',
        ], ['icon' => 'credit-card', 'prefix' => 'CC-', 'contact' => 'Parent', 'list' => ['card_number', 'balance', 'daily_limit']]],
        'sales' => ['Canteen sale', 'Items', 'completed,refunded', [
            'card:record=cards|Card',
            'payment:select=card,cash*',
        ], ['icon' => 'shopping-basket', 'prefix' => 'CNS-', 'amount' => 'Total', 'date' => 'Date', 'list' => ['card', 'payment']]],
        'topups' => ['Top-up', 'Reference', 'received,reversed', [
            'card:record=cards|Card*',
            'method:select=cash,mobile_money,bank',
        ], ['icon' => 'plus-circle', 'prefix' => 'TOP-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['card', 'method']]],
    ]],

    'scholarships' => ['Scholarships', 'hand-coins', 'Scholarship, bursary and student loan applications and awards.', [
        'schemes' => ['Scheme', 'Scheme name', 'open,closed', [
            'type:select=scholarship,bursary,student_loan*',
            'sponsor',
            'criteria:textarea',
            'slots:number',
        ], ['icon' => 'hand-coins', 'prefix' => 'SCH-', 'amount' => 'Fund size', 'due' => 'Application deadline', 'list' => ['type', 'sponsor', 'slots']]],
        'applications' => ['Application', 'Applicant name', 'submitted,shortlisted,awarded,declined,disbursed', [
            'scheme:record=schemes|Scheme*',
            'institution',
            'household_income:money|Household income',
            'motivation:textarea',
        ], ['icon' => 'file-text', 'prefix' => 'SAP-', 'contact' => 'Applicant', 'amount' => 'Award amount', 'date' => 'Applied on', 'assignee' => true, 'list' => ['scheme', 'institution']]],
    ]],

    'training-centres-vocational-colleges' => ['Training centres & vocational colleges (short courses, certifications)', 'wrench', 'Short-course intakes, trainees and assessments.', [
        'intakes' => ['Course intake', 'Course', 'open,running,completed', [
            'trade|Trade / field',
            'capacity:number',
            'trainer:user|Trainer',
        ], ['icon' => 'presentation', 'prefix' => 'INT-', 'amount' => 'Fee', 'date' => 'Start date', 'due' => 'End date', 'list' => ['trade', 'capacity', 'trainer']]],
        'trainees' => ['Trainee', 'Trainee name', 'enrolled,completed,competent,not_yet_competent,dropped', [
            'intake:record=intakes|Intake*',
            'id_number|ID number',
            'attendance:number|Attendance %',
            'assessment_result|Assessment result',
        ], ['icon' => 'hard-hat', 'prefix' => 'TRN-', 'contact' => 'Trainee', 'amount' => 'Paid', 'list' => ['intake', 'attendance', 'assessment_result']]],
    ]],

    'discipline-behaviour-tracking' => ['Discipline & behaviour tracking', 'shield-alert', 'Merits, demerits and disciplinary incidents for students.', [
        'entries' => ['Behaviour entry', 'Student name', 'recorded,parent_informed,resolved', [
            'type:select=merit,demerit,detention,suspension,commendation*',
            'points:number',
            'class_name|Class',
            'description:textarea*',
        ], ['icon' => 'shield-alert', 'prefix' => 'BEH-', 'plural' => 'Behaviour log', 'contact' => 'Parent', 'date' => 'Date', 'assignee' => true, 'list' => ['type', 'points', 'class_name']]],
    ]],

    'sports-academies-coaching' => ['Sports academies & coaching', 'trophy', 'Athletes, training squads, sessions and assessments.', [
        'squads' => ['Squad', 'Squad name', 'active,inactive', [
            'sport*',
            'age_group|Age group',
            'coach:user|Coach',
        ], ['icon' => 'users', 'prefix' => 'SQD-', 'amount' => 'Monthly fee', 'list' => ['sport', 'age_group', 'coach']]],
        'athletes' => ['Athlete', 'Athlete name', 'active,injured,left', [
            'squad:record=squads|Squad*',
            'date_of_birth:date|Date of birth',
            'position',
            'guardian|Parent / guardian',
        ], ['icon' => 'user-round', 'prefix' => 'ATH-', 'contact' => 'Payer', 'list' => ['squad', 'position']]],
        'sessions' => ['Training session', 'Focus', 'planned,held,cancelled', [
            'squad:record=squads|Squad*',
            'start_time:time|Start',
            'attendance:number',
            'notes:textarea',
        ], ['icon' => 'dumbbell', 'prefix' => 'TS-', 'date' => 'Date', 'assignee' => true, 'list' => ['squad', 'start_time', 'attendance']]],
    ]],
];
