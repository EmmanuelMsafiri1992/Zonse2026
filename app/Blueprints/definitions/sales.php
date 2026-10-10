<?php

use App\Blueprints\Logic\AffiliateLogic;
use App\Blueprints\Logic\ContractLogic;
use App\Blueprints\Logic\FeedbackLogic;
use App\Blueprints\Logic\LiveChatLogic;
use App\Blueprints\Logic\LoyaltyLogic;
use App\Blueprints\Logic\ProposalLogic;
use App\Blueprints\Logic\SalesCommissionLogic;

/*
 * Sales & customer apps: chat, loyalty, feedback, contracts, commissions and after-sales.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'live-chat' => ['Live chat & chatbot', 'message-circle', 'Conversations from your website, WhatsApp and Messenger, with saved bot replies.', [
        'conversations' => ['Conversation', 'Subject', 'open,waiting,resolved', [
            'channel:select=website,whatsapp,messenger,instagram,telegram,sms*',
            'visitor_name|Visitor name',
            'visitor_phone:phone|Visitor phone',
            'transcript:textarea',
        ], ['icon' => 'message-circle', 'prefix' => 'CHAT-', 'contact' => 'Customer', 'date' => 'Started at', 'assignee' => true, 'list' => ['channel', 'visitor_name']]],
        'replies' => ['Bot reply', 'Trigger phrase', 'active,disabled', [
            'keywords|Keywords (comma separated)*',
            'answer:textarea|Reply*',
            'hand_over:checkbox|Hand over to a person afterwards',
        ], ['icon' => 'bot', 'prefix' => 'BOT-', 'plural' => 'Bot replies', 'list' => ['keywords', 'hand_over']]],
    ], ['logic' => LiveChatLogic::class]],

    'loyalty' => ['Loyalty & rewards', 'gift', 'Loyalty members, points earned and rewards redeemed.', [
        'members' => ['Loyalty member', 'Member name', 'active,inactive', [
            'card_number|Card number',
            'tier:select=bronze,silver,gold,platinum',
            'points:number|Points balance',
            'stamps:number|Stamps',
        ], ['icon' => 'id-card', 'prefix' => 'LOY-', 'contact' => 'Customer', 'date' => 'Joined on', 'list' => ['card_number', 'tier', 'points']]],
        'transactions' => ['Points movement', 'Reason', 'posted,reversed', [
            'member:record=members|Member*',
            'type:select=earned,redeemed,expired,adjusted*',
            'points:number*',
            'reward|Reward redeemed',
        ], ['icon' => 'sparkles', 'prefix' => 'PTS-', 'amount' => 'Spend', 'date' => 'Date', 'list' => ['member', 'type', 'points']]],
    ], ['logic' => LoyaltyLogic::class]],

    'feedback' => ['Feedback, surveys & reviews', 'star', 'Surveys, NPS scores and customer reviews in one place.', [
        'surveys' => ['Survey', 'Survey title', 'draft,live,closed', [
            'questions:textarea*',
            'audience|Sent to',
            'responses:number',
        ], ['icon' => 'clipboard-list', 'prefix' => 'SRV-', 'date' => 'Opens on', 'due' => 'Closes on', 'assignee' => true, 'list' => ['audience', 'responses']]],
        'responses' => ['Response', 'Summary', 'new,actioned,closed', [
            'survey:record=surveys|Survey',
            'source:select=survey,google,facebook,tripadvisor,in_store,whatsapp,other*',
            'score:number|Score (0–10)',
            'comment:textarea',
            'reply:textarea|Our reply',
        ], ['icon' => 'star', 'prefix' => 'FB-', 'plural' => 'Responses & reviews', 'contact' => 'Customer', 'date' => 'Received on', 'assignee' => true, 'list' => ['source', 'score']]],
    ], ['logic' => FeedbackLogic::class]],

    'contracts' => ['Contracts & e-signature', 'pen-tool', 'Contracts, signatories and renewal dates.', [
        'contracts' => ['Contract', 'Contract title', 'draft,sent,signed,active,expired,terminated', [
            'type:select=sales,service,supply,employment,lease,nda,partnership,other*',
            'signatory|Signed by (name)',
            'signed_on:date|Signed on',
            'auto_renew:checkbox|Auto-renews',
            'terms:textarea',
        ], ['icon' => 'pen-tool', 'prefix' => 'CTR-', 'contact' => 'Counterparty', 'amount' => 'Contract value', 'date' => 'Start date', 'due' => 'End date', 'assignee' => true, 'list' => ['type', 'signed_on']]],
        'obligations' => ['Obligation', 'Obligation', 'pending,met,missed', [
            'contract:record=contracts|Contract*',
            'owner:select=us,them*',
        ], ['icon' => 'list-checks', 'prefix' => 'OBL-', 'due' => 'Due date', 'assignee' => true, 'list' => ['contract', 'owner']]],
    ], ['logic' => ContractLogic::class]],

    'affiliate-referral-management' => ['Affiliate & referral management', 'share-2', 'Affiliates, referral codes, referred sales and commissions owed.', [
        'affiliates' => ['Affiliate', 'Affiliate name', 'active,paused,terminated', [
            'code|Referral code*',
            'commission_rate:number|Commission %',
            'payout_method:select=bank,mobile_money,store_credit',
        ], ['icon' => 'share-2', 'prefix' => 'AFF-', 'contact' => 'Contact', 'list' => ['code', 'commission_rate']]],
        'referrals' => ['Referral', 'Referred customer', 'pending,converted,paid,rejected', [
            'affiliate:record=affiliates|Affiliate*',
            'sale_value:money|Sale value',
        ], ['icon' => 'user-plus', 'prefix' => 'REF-', 'amount' => 'Commission', 'date' => 'Referred on', 'list' => ['affiliate', 'sale_value']]],
    ], ['logic' => AffiliateLogic::class]],

    'proposals-sales-documents-builder' => ['Proposals & sales documents builder', 'file-text', 'Proposals with sections, pricing and acceptance tracking.', [
        'proposals' => ['Proposal', 'Proposal title', 'draft,sent,viewed,accepted,declined,expired', [
            'introduction:textarea',
            'scope:textarea|Scope of work*',
            'pricing:textarea',
            'terms:textarea',
        ], ['icon' => 'file-text', 'prefix' => 'PRP-', 'contact' => 'Client', 'amount' => 'Proposal value', 'date' => 'Sent on', 'due' => 'Valid until', 'assignee' => true]],
        'templates' => ['Template', 'Template name', 'active,archived', [
            'industry',
            'body:textarea|Template text*',
        ], ['icon' => 'copy', 'prefix' => 'TPL-', 'list' => ['industry']]],
    ], ['logic' => ProposalLogic::class]],

    'sales-commissions-targets' => ['Sales commissions & targets', 'target', 'Sales targets per rep and the commissions they earn.', [
        'targets' => ['Target', 'Period', 'active,achieved,missed', [
            'rep:user|Sales rep*',
            'achieved:money|Achieved to date',
        ], ['icon' => 'target', 'prefix' => 'TGT-', 'amount' => 'Target', 'date' => 'Period start', 'due' => 'Period end', 'list' => ['rep', 'achieved']]],
        'commissions' => ['Commission', 'Deal / invoice', 'earned,approved,paid', [
            'rep:user|Sales rep*',
            'sale_value:money|Sale value*',
            'rate:number|Rate %',
        ], ['icon' => 'hand-coins', 'prefix' => 'COM-', 'amount' => 'Commission', 'date' => 'Date', 'list' => ['rep', 'sale_value', 'rate']]],
    ], ['logic' => SalesCommissionLogic::class]],

    'call-centre' => ['Call centre', 'phone', 'Call logs, call lists for outbound campaigns and dispositions.', [
        'calls' => ['Call', 'Summary', 'answered,missed,voicemail,callback', [
            'direction:select=inbound,outbound*',
            'phone:phone|Phone number*',
            'duration:number|Duration (minutes)',
            'disposition:select=sale,interested,not_interested,complaint,query,wrong_number',
            'recording_url:url|Recording link',
            'callback_at:datetime|Call back at',
        ], ['icon' => 'phone', 'prefix' => 'CALL-', 'contact' => 'Caller', 'date' => 'Call date', 'assignee' => true, 'list' => ['direction', 'phone', 'disposition']]],
        'campaigns' => ['Call campaign', 'Campaign name', 'planned,running,finished', [
            'script:textarea|Call script',
            'list_size:number|Numbers to call',
            'reached:number',
        ], ['icon' => 'list-ordered', 'prefix' => 'CMP-', 'date' => 'Start date', 'due' => 'End date', 'assignee' => true, 'list' => ['list_size', 'reached']]],
    ]],

    'field-sales-van-sales' => ['Field sales / van sales & route planning', 'route', 'Sales routes, outlet visits and van stock loads.', [
        'routes' => ['Route', 'Route name', 'active,inactive', [
            'rep:user|Sales rep',
            'vehicle|Van / vehicle',
            'days|Visit days',
            'outlets:textarea|Outlets on the route',
        ], ['icon' => 'route', 'prefix' => 'RTE-', 'list' => ['rep', 'vehicle', 'days']]],
        'visits' => ['Outlet visit', 'Outlet', 'planned,visited,missed', [
            'route:record=routes|Route',
            'order_taken:checkbox|Order taken',
            'stock_check:textarea|Stock check notes',
            'gps|GPS location',
        ], ['icon' => 'map-pin', 'prefix' => 'VIS-', 'contact' => 'Customer', 'amount' => 'Sales value', 'date' => 'Visit date', 'assignee' => true, 'list' => ['route', 'order_taken']]],
        'loads' => ['Van load', 'Load', 'loaded,on_route,returned,reconciled', [
            'route:record=routes|Route*',
            'items_loaded:textarea|Items loaded*',
            'items_returned:textarea|Items returned',
            'cash_collected:money|Cash collected',
        ], ['icon' => 'truck', 'prefix' => 'LD-', 'amount' => 'Load value', 'date' => 'Date', 'list' => ['route', 'cash_collected']]],
    ]],

    'customer-portal' => ['Customer portal', 'globe', 'Portal accounts for customers and the requests they send in.', [
        'accounts' => ['Portal account', 'Customer', 'invited,active,disabled', [
            'email:email*',
            'can_see:textarea|Can see (invoices, tickets, documents, bookings)',
            'last_login:datetime|Last login',
        ], ['icon' => 'user-round', 'prefix' => 'CPA-', 'contact' => 'Customer', 'list' => ['email', 'last_login']]],
        'requests' => ['Portal request', 'Subject', 'new,in_progress,done', [
            'account:record=accounts|Account*',
            'type:select=question,document_request,booking,payment_proof,complaint*',
            'message:textarea*',
        ], ['icon' => 'inbox', 'prefix' => 'CPR-', 'date' => 'Received on', 'assignee' => true, 'list' => ['account', 'type']]],
    ]],

    'gift-cards' => ['Gift cards', 'gift', 'Gift cards, vouchers and store credit, with every redemption logged.', [
        'cards' => ['Gift card', 'Card code', 'active,redeemed,expired,cancelled', [
            'type:select=gift_card,voucher,store_credit*',
            'balance:money|Balance',
            'purchaser|Bought by',
        ], ['icon' => 'gift', 'prefix' => 'GC-', 'contact' => 'Holder', 'amount' => 'Face value', 'date' => 'Issued on', 'due' => 'Expires on', 'list' => ['type', 'balance']]],
        'redemptions' => ['Redemption', 'Reference', 'posted,reversed', [
            'card:record=cards|Gift card*',
            'branch_name|Where redeemed',
        ], ['icon' => 'ticket', 'prefix' => 'GCR-', 'amount' => 'Amount', 'date' => 'Date', 'list' => ['card']]],
    ]],

    'warranty-returns-rma-management' => ['Warranty & returns (RMA) management', 'undo-2', 'Warranties on sold items and return authorisations.', [
        'warranties' => ['Warranty', 'Product', 'active,expired,void', [
            'serial_number|Serial number*',
            'invoice_number|Invoice number',
            'months:number|Warranty (months)',
        ], ['icon' => 'shield-check', 'prefix' => 'WAR-', 'contact' => 'Customer', 'date' => 'Sold on', 'due' => 'Expires on', 'list' => ['serial_number', 'months']]],
        'returns' => ['Return (RMA)', 'Fault / reason', 'requested,approved,received,repaired,replaced,refunded,rejected', [
            'warranty:record=warranties|Warranty',
            'reason:select=faulty,damaged,wrong_item,changed_mind,other*',
            'resolution:textarea',
        ], ['icon' => 'undo-2', 'prefix' => 'RMA-', 'plural' => 'Returns (RMA)', 'contact' => 'Customer', 'amount' => 'Refund amount', 'date' => 'Requested on', 'assignee' => true, 'list' => ['warranty', 'reason']]],
    ]],

    'service-contracts-amc-annual' => ['Service contracts & AMC (annual maintenance contracts)', 'file-clock', 'Maintenance contracts, covered equipment and scheduled service visits.', [
        'contracts' => ['Service contract', 'Contract', 'active,expiring,expired,cancelled', [
            'equipment:textarea|Covered equipment*',
            'visits_per_year:number|Visits per year',
            'response_time|Response time (SLA)',
        ], ['icon' => 'file-clock', 'prefix' => 'AMC-', 'contact' => 'Customer', 'amount' => 'Annual fee', 'date' => 'Start date', 'due' => 'Renewal date', 'assignee' => true, 'list' => ['visits_per_year', 'response_time']]],
        'visits' => ['Service visit', 'Work done', 'scheduled,completed,missed', [
            'contract:record=contracts|Contract*',
            'technician:user|Technician',
            'report:textarea',
        ], ['icon' => 'wrench', 'prefix' => 'SV-', 'date' => 'Visit date', 'list' => ['contract', 'technician']]],
    ]],
];
