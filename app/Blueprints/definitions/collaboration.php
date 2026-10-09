<?php

/*
 * Projects & collaboration apps: time, files, chat, calendars, knowledge and workflow.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

use App\Blueprints\Logic\CalendarLogic;
use App\Blueprints\Logic\ClientPortalLogic;
use App\Blueprints\Logic\DocumentsLogic;
use App\Blueprints\Logic\FormsApprovalsLogic;
use App\Blueprints\Logic\FreelancerLogic;
use App\Blueprints\Logic\GoalsOkrLogic;
use App\Blueprints\Logic\IssuesLogic;
use App\Blueprints\Logic\MeetingMinutesLogic;
use App\Blueprints\Logic\NotesWhiteboardsLogic;
use App\Blueprints\Logic\TeamChatLogic;
use App\Blueprints\Logic\TimeTrackingLogic;
use App\Blueprints\Logic\VideoMeetingsLogic;
use App\Blueprints\Logic\WikiLogic;

return [
    'time-tracking' => ['Time tracking', 'timer', 'Time entries per client and project, ready to invoice.', [
        'entries' => ['Time entry', 'What you worked on', 'unbilled,billed,non_billable', [
            'project',
            'hours:number|Hours*',
            'rate:money|Hourly rate',
            'start_time:time|Start',
            'end_time:time|End',
        ], ['icon' => 'timer', 'prefix' => 'TE-', 'plural' => 'Time entries', 'contact' => 'Client', 'amount' => 'Value', 'date' => 'Date', 'assignee' => true, 'list' => ['project', 'hours', 'rate']]],
    ], ['logic' => TimeTrackingLogic::class]],

    'documents' => ['Documents & files', 'folder', 'Folders and documents with versions and sharing.', [
        'folders' => ['Folder', 'Folder name', 'active,archived', [
            'parent:record=folders|Parent folder',
            'shared_with|Shared with',
        ], ['icon' => 'folder', 'prefix' => 'FLD-', 'list' => ['parent', 'shared_with']]],
        'documents' => ['Document', 'Document title', 'draft,in_review,approved,obsolete', [
            'folder:record=folders|Folder',
            'file_url:url|File link*',
            'version|Version',
            'tags',
        ], ['icon' => 'file-text', 'prefix' => 'DOC-', 'date' => 'Updated on', 'due' => 'Review date', 'assignee' => true, 'list' => ['folder', 'version']]],
    ], ['logic' => DocumentsLogic::class]],

    'team-chat' => ['Team chat', 'messages-square', 'Channels and messages for internal conversations.', [
        'channels' => ['Channel', 'Channel name', 'active,archived', [
            'purpose',
            'private:checkbox',
            'members:textarea',
        ], ['icon' => 'hash', 'prefix' => 'CH-', 'list' => ['purpose', 'private']]],
        'messages' => ['Message', 'Message', 'posted,pinned', [
            'channel:record=channels|Channel*',
            'body:textarea|Message*',
        ], ['icon' => 'message-square', 'prefix' => 'MSG-', 'date' => 'Posted at', 'assignee' => true, 'list' => ['channel']]],
    ], ['logic' => TeamChatLogic::class]],

    'video-meetings' => ['Video meetings', 'video', 'Scheduled video meetings with links, attendees and recordings.', [
        'meetings' => ['Meeting', 'Meeting title', 'scheduled,live,ended,cancelled', [
            'start_time:time|Start time*',
            'duration:number|Duration (minutes)',
            'link:url|Meeting link*',
            'platform:select=zonseo,google_meet,zoom,teams,jitsi,other',
            'attendees:textarea',
            'recording_url:url|Recording',
        ], ['icon' => 'video', 'prefix' => 'MTG-', 'contact' => 'Guest', 'date' => 'Date', 'assignee' => true, 'list' => ['start_time', 'platform']]],
    ], ['logic' => VideoMeetingsLogic::class]],

    'calendar' => ['Shared calendars & rooms', 'calendar', 'Shared calendar events and bookings of rooms and resources.', [
        'resources' => ['Room / resource', 'Name', 'available,out_of_service', [
            'type:select=meeting_room,vehicle,projector,equipment,desk*',
            'capacity:number',
            'location',
        ], ['icon' => 'door-open', 'prefix' => 'RES-', 'plural' => 'Rooms & resources', 'list' => ['type', 'capacity']]],
        'events' => ['Event', 'Title', 'tentative,confirmed,cancelled', [
            'start_time:time|Start*',
            'end_time:time|End',
            'resource:record=resources|Room / resource',
            'attendees:textarea',
            'all_day:checkbox|All day',
        ], ['icon' => 'calendar', 'prefix' => 'EVT-', 'date' => 'Date', 'assignee' => true, 'list' => ['start_time', 'resource']]],
    ], ['logic' => CalendarLogic::class]],

    'wiki' => ['Knowledge base & wiki', 'book', 'Internal and public help articles organised by category.', [
        'categories' => ['Category', 'Category name', 'active,hidden', [
            'description:textarea',
        ], ['icon' => 'folder-tree', 'prefix' => 'CAT-', 'plural' => 'Categories']],
        'articles' => ['Article', 'Article title', 'draft,published,archived', [
            'category:record=categories|Category',
            'visibility:select=internal,public*',
            'body:textarea|Content*',
            'tags',
        ], ['icon' => 'book-open-text', 'prefix' => 'KB-', 'date' => 'Updated on', 'assignee' => true, 'list' => ['category', 'visibility']]],
    ], ['logic' => WikiLogic::class]],

    'forms-approvals-workflow' => ['Forms & approvals workflow', 'list-checks', 'Form templates and the submissions routed for approval.', [
        'forms' => ['Form', 'Form name', 'active,draft,retired', [
            'questions:textarea|Questions (one per line)*',
            'approvers|Approvers',
        ], ['icon' => 'file-pen', 'prefix' => 'FRM-', 'list' => ['approvers']]],
        'submissions' => ['Submission', 'Summary', 'submitted,pending_approval,approved,rejected', [
            'form:record=forms|Form*',
            'answers:textarea*',
            'approver:user|Approver',
            'comments:textarea',
        ], ['icon' => 'file-check', 'prefix' => 'SBM-', 'amount' => 'Amount (if any)', 'date' => 'Submitted on', 'assignee' => true, 'list' => ['form', 'approver']]],
    ], ['logic' => FormsApprovalsLogic::class]],

    'notes-whiteboards' => ['Notes & whiteboards', 'sticky-note', 'Shared notes and whiteboard snapshots.', [
        'notes' => ['Note', 'Title', 'active,archived', [
            'type:select=note,whiteboard,checklist*',
            'body:textarea*',
            'image_url:url|Whiteboard image',
            'shared:checkbox|Shared with team',
        ], ['icon' => 'sticky-note', 'prefix' => 'NT-', 'date' => 'Date', 'assignee' => true, 'list' => ['type', 'shared']]],
    ], ['logic' => NotesWhiteboardsLogic::class]],

    'goals-okr-tracking' => ['Goals & OKR tracking', 'goal', 'Company and team objectives with measurable key results.', [
        'objectives' => ['Objective', 'Objective', 'on_track,at_risk,off_track,achieved,dropped', [
            'level:select=company,team,personal*',
            'team',
            'period|Quarter / period',
        ], ['icon' => 'goal', 'prefix' => 'OBJ-', 'due' => 'Due date', 'assignee' => true, 'list' => ['level', 'team', 'period']]],
        'key_results' => ['Key result', 'Key result', 'on_track,at_risk,off_track,achieved', [
            'objective:record=objectives|Objective*',
            'start_value:number|Start value',
            'target_value:number|Target value*',
            'current_value:number|Current value',
        ], ['icon' => 'target', 'prefix' => 'KR-', 'assignee' => true, 'list' => ['objective', 'target_value', 'current_value']]],
    ], ['logic' => GoalsOkrLogic::class]],

    'issues' => ['Issue tracking & roadmap', 'bug', 'Bugs, feature requests and a product roadmap.', [
        'issues' => ['Issue', 'Summary', 'open,triaged,in_progress,in_review,done,wont_fix', [
            'type:select=bug,feature,improvement,task*',
            'priority:select=low,medium,high,critical*',
            'component',
            'steps:textarea|Steps to reproduce / details',
            'release:record=releases|Release',
        ], ['icon' => 'bug', 'prefix' => 'ISS-', 'date' => 'Reported on', 'due' => 'Due date', 'assignee' => true, 'list' => ['type', 'priority', 'release']]],
        'releases' => ['Release', 'Version / name', 'planned,in_progress,released', [
            'goals:textarea',
            'release_notes:textarea|Release notes',
        ], ['icon' => 'milestone', 'prefix' => 'REL-', 'due' => 'Target date', 'list' => []]],
    ], ['logic' => IssuesLogic::class]],

    'meeting-minutes-action-items' => ['Meeting minutes & action items', 'notebook-pen', 'Minutes of meetings and the actions agreed.', [
        'meetings' => ['Meeting', 'Meeting', 'scheduled,held,minutes_approved', [
            'chair:user|Chair',
            'attendees:textarea',
            'agenda:textarea',
            'minutes:textarea',
        ], ['icon' => 'notebook-pen', 'prefix' => 'MIN-', 'date' => 'Meeting date', 'assignee' => true, 'list' => ['chair']]],
        'actions' => ['Action item', 'Action', 'open,done,cancelled', [
            'meeting:record=meetings|Meeting*',
            'owner:user|Owner*',
        ], ['icon' => 'list-todo', 'prefix' => 'ACT-', 'due' => 'Due date', 'list' => ['meeting', 'owner']]],
    ], ['logic' => MeetingMinutesLogic::class]],

    'client-portal-for-agencies' => ['Client portal for agencies/consultants', 'presentation', 'Client projects, deliverables for sign-off and shared updates.', [
        'projects' => ['Client project', 'Project', 'active,on_hold,completed', [
            'portal_access|Client portal users',
            'summary:textarea',
        ], ['icon' => 'briefcase', 'prefix' => 'CP-', 'contact' => 'Client', 'amount' => 'Budget', 'date' => 'Start date', 'due' => 'End date', 'assignee' => true, 'list' => ['portal_access']]],
        'deliverables' => ['Deliverable', 'Deliverable', 'in_progress,awaiting_approval,approved,changes_requested', [
            'project:record=projects|Project*',
            'file_url:url|File / preview link',
            'client_feedback:textarea|Client feedback',
        ], ['icon' => 'package-check', 'prefix' => 'DLV-', 'due' => 'Due date', 'assignee' => true, 'list' => ['project']]],
        'updates' => ['Update', 'Headline', 'published', [
            'project:record=projects|Project*',
            'body:textarea*',
        ], ['icon' => 'megaphone', 'prefix' => 'UPD-', 'date' => 'Date', 'list' => ['project']]],
    ], ['logic' => ClientPortalLogic::class]],

    'freelancer' => ['Freelancer workspace', 'laptop', 'Proposals, contracts, time and invoices for solo professionals.', [
        'gigs' => ['Gig', 'Gig / project', 'lead,proposal_sent,contracted,in_progress,delivered,paid', [
            'rate_type:select=fixed,hourly,daily,retainer*',
            'rate:money',
            'proposal:textarea',
            'contract_signed:checkbox|Contract signed',
            'invoice_number|Invoice number',
        ], ['icon' => 'laptop', 'prefix' => 'GIG-', 'contact' => 'Client', 'amount' => 'Value', 'date' => 'Start date', 'due' => 'Deadline', 'list' => ['rate_type', 'rate', 'contract_signed']]],
        'time' => ['Time log', 'Work done', 'unbilled,billed', [
            'gig:record=gigs|Gig*',
            'hours:number*',
        ], ['icon' => 'timer', 'prefix' => 'TL-', 'plural' => 'Time logs', 'date' => 'Date', 'list' => ['gig', 'hours']]],
    ], ['logic' => FreelancerLogic::class]],
];
