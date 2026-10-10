<?php

use App\Blueprints\Logic\CareerLogic;
use App\Blueprints\Logic\FitnessTrackerLogic;
use App\Blueprints\Logic\FreelanceGigsLogic;
use App\Blueprints\Logic\HouseholdLogic;
use App\Blueprints\Logic\MyEventPlannerLogic;
use App\Blueprints\Logic\PersonalMoneyLogic;
use App\Blueprints\Logic\PersonalTasksLogic;
use App\Blueprints\Logic\SmallLandlordLogic;

/*
 * Personal & individual apps: money, tasks, household, career and health.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'personal-finance' => ['Personal finance', 'piggy-bank', 'Personal income, spending, savings goals and debts.', [
        'transactions' => ['Transaction', 'Description', 'cleared,pending', [
            'type:select=income,expense,transfer*',
            'category:select=salary,side_income,groceries,rent,transport,utilities,school_fees,medical,entertainment,church_giving,family_support,other*',
            'account|Account / wallet',
        ], ['icon' => 'arrow-left-right', 'prefix' => 'TX-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['type', 'category', 'account']]],
        'goals' => ['Savings goal', 'Goal', 'saving,reached,paused', [
            'target:money|Target*',
            'saved:money|Saved so far',
        ], ['icon' => 'piggy-bank', 'prefix' => 'GOAL-', 'due' => 'Target date', 'list' => ['target', 'saved']]],
        'debts' => ['Debt', 'Owed to', 'active,paid_off', [
            'balance:money*',
            'monthly_payment:money|Monthly payment',
            'interest_rate:number|Interest rate %',
        ], ['icon' => 'credit-card', 'prefix' => 'DBT-', 'due' => 'Next payment', 'list' => ['balance', 'monthly_payment', 'interest_rate']]],
    ], ['logic' => PersonalMoneyLogic::class]],

    'personal-tasks' => ['Personal tasks & habits', 'list-todo', 'To-dos, lists and daily habits.', [
        'todos' => ['To-do', 'Task', 'to_do,doing,done', [
            'list:select=personal,work,shopping,errands,home,ideas',
            'priority:select=low,normal,high',
            'notes:textarea',
        ], ['icon' => 'list-todo', 'prefix' => 'TD-', 'plural' => 'To-dos', 'due' => 'Due date', 'list' => ['list', 'priority']]],
        'habits' => ['Habit', 'Habit', 'active,paused', [
            'frequency:select=daily,weekdays,weekly*',
            'streak:number|Current streak',
            'best_streak:number|Best streak',
        ], ['icon' => 'repeat', 'prefix' => 'HAB-', 'date' => 'Started on', 'list' => ['frequency', 'streak']]],
    ], ['logic' => PersonalTasksLogic::class]],

    'family-household-management-chores' => ['Family & household', 'house', 'Chores, family calendar and household shopping.', [
        'chores' => ['Chore', 'Chore', 'to_do,done,skipped', [
            'family_member|Assigned to*',
            'frequency:select=once,daily,weekly,monthly',
            'reward:money|Pocket-money reward',
        ], ['icon' => 'brush-cleaning', 'prefix' => 'CH-', 'due' => 'Due', 'list' => ['family_member', 'frequency']]],
        'events' => ['Family event', 'Event', 'upcoming,done', [
            'who|Who',
            'time:time',
            'place',
        ], ['icon' => 'calendar-heart', 'prefix' => 'FE-', 'date' => 'Date', 'list' => ['who', 'time', 'place']]],
        'shopping' => ['Shopping item', 'Item', 'needed,bought', [
            'quantity',
            'shop',
        ], ['icon' => 'shopping-basket', 'prefix' => 'SHP-', 'plural' => 'Shopping list', 'amount' => 'Price', 'list' => ['quantity', 'shop']]],
    ], ['logic' => HouseholdLogic::class]],

    'freelancer-toolkit' => ['Freelancer toolkit', 'laptop', 'Gigs, hours and money owed, for one-person businesses.', [
        'gigs' => ['Gig', 'Gig / project', 'pitched,booked,in_progress,delivered,paid', [
            'rate_type:select=fixed,hourly,daily',
            'rate:money',
            'hours:number|Hours logged',
            'platform:select=direct,upwork,fiverr,referral,other',
            'invoice_sent:checkbox|Invoice sent',
        ], ['icon' => 'laptop', 'prefix' => 'GIG-', 'contact' => 'Client', 'amount' => 'Total', 'date' => 'Start date', 'due' => 'Deadline', 'list' => ['rate', 'hours', 'invoice_sent']]],
        'expenses' => ['Business expense', 'Description', 'logged,claimed', [
            'category:select=software,equipment,internet,data,travel,training,other*',
            'receipt_url:url|Receipt',
        ], ['icon' => 'receipt', 'prefix' => 'EXP-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['category']]],
    ], ['logic' => FreelanceGigsLogic::class]],

    'personal-cv-portfolio-site' => ['CV & portfolio', 'file-user', 'Your experience, portfolio pieces and job applications.', [
        'experience' => ['Experience', 'Role / qualification', 'current,past', [
            'type:select=job,education,certification,volunteer,award*',
            'organisation*',
            'achievements:textarea',
        ], ['icon' => 'briefcase', 'prefix' => 'XP-', 'plural' => 'Experience', 'date' => 'From', 'due' => 'To', 'list' => ['type', 'organisation']]],
        'portfolio' => ['Portfolio piece', 'Title', 'draft,published,hidden', [
            'link:url|Link',
            'image_url:url|Image',
            'description:textarea',
            'skills',
        ], ['icon' => 'image', 'prefix' => 'PF-', 'plural' => 'Portfolio', 'date' => 'Date', 'list' => ['skills', 'link']]],
        'applications' => ['Job application', 'Company & role', 'applied,interview,offer,rejected,accepted', [
            'source|Where found',
            'contact_person|Contact person',
            'salary:money|Salary offered',
        ], ['icon' => 'send', 'prefix' => 'JA-', 'date' => 'Applied on', 'due' => 'Follow up on', 'list' => ['source', 'contact_person']]],
    ], ['logic' => CareerLogic::class]],

    'wedding-event-planner' => ['My wedding / event planner', 'heart', 'Plan your own wedding or party: guests, budget and to-dos.', [
        'guests' => ['Guest', 'Guest name', 'invited,attending,declined,no_reply', [
            'side:select=bride,groom,family,friends,work',
            'party_size:number|Party size',
            'phone:phone',
            'table',
            'dietary',
        ], ['icon' => 'users', 'prefix' => 'GST-', 'list' => ['side', 'party_size', 'table']]],
        'budget' => ['Budget line', 'Item', 'planned,booked,paid', [
            'category:select=venue,catering,attire,photography,decor,music,cake,rings,transport,other*',
            'vendor',
            'estimated:money|Estimated',
            'deposit:money',
        ], ['icon' => 'wallet', 'prefix' => 'BUD-', 'plural' => 'Budget', 'amount' => 'Actual cost', 'due' => 'Pay by', 'list' => ['category', 'vendor', 'estimated']]],
        'todos' => ['To-do', 'Task', 'to_do,done', [
            'who|Who is doing it',
        ], ['icon' => 'list-checks', 'prefix' => 'WT-', 'plural' => 'To-dos', 'due' => 'Due date', 'list' => ['who']]],
    ], ['logic' => MyEventPlannerLogic::class]],

    'health-fitness-tracker' => ['Health & fitness tracker', 'heart-pulse', 'Workouts, body measurements and medication reminders.', [
        'workouts' => ['Workout', 'Workout', 'planned,done,skipped', [
            'type:select=run,walk,gym,cycle,swim,sport,yoga,home_workout*',
            'minutes:number|Duration (min)',
            'distance:number|Distance (km)',
            'notes:textarea',
        ], ['icon' => 'dumbbell', 'prefix' => 'WO-', 'date' => 'Date', 'list' => ['type', 'minutes', 'distance']]],
        'measurements' => ['Measurement', 'Check-in', 'logged', [
            'weight:number|Weight (kg)',
            'blood_pressure|Blood pressure',
            'blood_sugar:number|Blood sugar (mmol/L)',
            'resting_heart_rate:number|Resting heart rate',
        ], ['icon' => 'heart-pulse', 'prefix' => 'MS-', 'date' => 'Date', 'list' => ['weight', 'blood_pressure', 'blood_sugar']]],
        'medications' => ['Medication', 'Medicine', 'taking,stopped', [
            'dose*',
            'times|Times of day',
            'refill_date:date|Refill date',
        ], ['icon' => 'pill', 'prefix' => 'MED-', 'list' => ['dose', 'times', 'refill_date']]],
    ], ['logic' => FitnessTrackerLogic::class]],

    'landlord-with-one-or' => ['Small landlord (one or a few units)', 'key-round', 'Your rental units, tenants, rent received and repairs.', [
        'units' => ['Rental unit', 'Unit / address', 'let,vacant,being_repaired', [
            'tenant|Current tenant',
            'tenant_phone:phone|Tenant phone',
            'rent:money|Monthly rent*',
            'deposit_held:money|Deposit held',
            'lease_end:date|Lease ends',
        ], ['icon' => 'house', 'prefix' => 'UNIT-', 'list' => ['tenant', 'rent', 'lease_end']]],
        'payments' => ['Rent received', 'Month', 'paid,part_paid,late,missed', [
            'unit:record=units|Unit*',
            'method:select=bank,cash,mobile_money',
        ], ['icon' => 'coins', 'prefix' => 'RENT-', 'plural' => 'Rent received', 'amount' => 'Amount', 'date' => 'Received on', 'list' => ['unit', 'method']]],
        'repairs' => ['Repair', 'Problem', 'reported,booked,fixed', [
            'unit:record=units|Unit*',
            'contractor',
        ], ['icon' => 'wrench', 'prefix' => 'FIX-', 'amount' => 'Cost', 'date' => 'Reported on', 'list' => ['unit', 'contractor']]],
    ], ['logic' => SmallLandlordLogic::class]],
];
