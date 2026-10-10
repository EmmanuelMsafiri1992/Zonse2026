<?php

use App\Blueprints\Logic\AdsLogic;
use App\Blueprints\Logic\AgencyLogic;
use App\Blueprints\Logic\BrandAssetLogic;
use App\Blueprints\Logic\EmailMarketingLogic;
use App\Blueprints\Logic\MembershipLogic;
use App\Blueprints\Logic\PodcastLogic;
use App\Blueprints\Logic\PromoCodeLogic;
use App\Blueprints\Logic\SeoLogic;
use App\Blueprints\Logic\SignageLogic;
use App\Blueprints\Logic\SmsMarketingLogic;
use App\Blueprints\Logic\SocialMediaLogic;
use App\Blueprints\Logic\WebsiteBuilderLogic;

/*
 * Marketing apps: campaigns, channels, content and media.
 * Format: see App\Blueprints\Blueprint and App\Blueprints\Entity.
 */

return [
    'email-marketing' => ['Email marketing', 'mail', 'Mailing lists, newsletters and automated email sequences.', [
        'lists' => ['Mailing list', 'List name', 'active,archived', [
            'subscribers:number',
            'source|How people join',
            'double_opt_in:checkbox|Double opt-in',
        ], ['icon' => 'list', 'prefix' => 'ML-', 'list' => ['subscribers', 'source']]],
        'campaigns' => ['Campaign', 'Subject line', 'draft,scheduled,sent', [
            'list:record=lists|Mailing list*',
            'from_name|From name',
            'body:textarea|Email content*',
            'opens:number',
            'clicks:number',
        ], ['icon' => 'mail', 'prefix' => 'EM-', 'date' => 'Send at', 'assignee' => true, 'list' => ['list', 'opens', 'clicks']]],
        'sequences' => ['Automation', 'Sequence name', 'active,paused', [
            'trigger:select=signed_up,purchased,birthday,inactive_30_days,tag_added*',
            'steps:textarea|Emails & delays*',
        ], ['icon' => 'workflow', 'prefix' => 'SEQ-', 'list' => ['trigger']]],
    ], ['logic' => EmailMarketingLogic::class]],

    'sms-marketing' => ['SMS & WhatsApp campaigns', 'message-square', 'Bulk SMS and WhatsApp messages, templates and delivery results.', [
        'templates' => ['Template', 'Template name', 'draft,approved,rejected', [
            'channel:select=sms,whatsapp*',
            'body:textarea|Message*',
            'language',
        ], ['icon' => 'copy', 'prefix' => 'TPL-', 'list' => ['channel', 'language']]],
        'campaigns' => ['Campaign', 'Campaign name', 'draft,scheduled,sending,sent', [
            'channel:select=sms,whatsapp*',
            'template:record=templates|Template',
            'audience|Audience*',
            'recipients:number',
            'delivered:number',
            'cost:money',
        ], ['icon' => 'send', 'prefix' => 'SMS-', 'date' => 'Send at', 'assignee' => true, 'list' => ['channel', 'recipients', 'delivered']]],
    ], ['logic' => SmsMarketingLogic::class]],

    'social-media-scheduling-inbox' => ['Social media scheduling & inbox', 'share-2', 'A content calendar for posts and an inbox for comments and DMs.', [
        'posts' => ['Post', 'Post caption', 'idea,draft,scheduled,published', [
            'networks|Networks (Facebook, Instagram, X, LinkedIn, TikTok)*',
            'media_url:url|Image / video link',
            'reach:number',
            'engagement:number',
        ], ['icon' => 'calendar-days', 'prefix' => 'POST-', 'date' => 'Publish at', 'assignee' => true, 'list' => ['networks', 'reach']]],
        'messages' => ['Inbox message', 'Message', 'new,replied,closed', [
            'network:select=facebook,instagram,x,linkedin,tiktok,whatsapp*',
            'from_handle|From',
            'type:select=comment,direct_message,mention,review',
            'reply:textarea',
        ], ['icon' => 'inbox', 'prefix' => 'MSG-', 'date' => 'Received at', 'assignee' => true, 'list' => ['network', 'from_handle', 'type']]],
    ], ['logic' => SocialMediaLogic::class]],

    'website-builder' => ['Website & landing pages', 'layout', 'Website pages, landing pages and blog posts, plus the leads they capture.', [
        'pages' => ['Page', 'Page title', 'draft,published,unpublished', [
            'slug|URL slug*',
            'type:select=page,landing_page,blog_post*',
            'content:textarea*',
            'seo_description|SEO description',
            'views:number',
        ], ['icon' => 'layout', 'prefix' => 'PG-', 'date' => 'Published on', 'assignee' => true, 'list' => ['slug', 'type', 'views']]],
        'leads' => ['Form submission', 'Name', 'new,contacted,converted,spam', [
            'page:record=pages|Page',
            'email:email',
            'phone:phone',
            'message:textarea',
        ], ['icon' => 'inbox', 'prefix' => 'SUB-', 'contact' => 'Contact', 'date' => 'Submitted at', 'assignee' => true, 'list' => ['page', 'email', 'phone']]],
    ], ['logic' => WebsiteBuilderLogic::class]],

    'event-marketing-promo-codes' => ['Event marketing & promo codes', 'badge-percent', 'Promotions and discount codes, with usage tracking.', [
        'promotions' => ['Promotion', 'Promotion name', 'planned,live,ended', [
            'channel|Channels',
            'budget:money',
            'goal|Goal',
        ], ['icon' => 'megaphone', 'prefix' => 'PRM-', 'amount' => 'Revenue generated', 'date' => 'Starts on', 'due' => 'Ends on', 'assignee' => true, 'list' => ['channel', 'budget']]],
        'codes' => ['Promo code', 'Code', 'active,exhausted,expired', [
            'promotion:record=promotions|Promotion',
            'discount_type:select=percent,fixed_amount,free_item*',
            'discount_value:number|Discount value*',
            'max_uses:number|Max uses',
            'times_used:number|Times used',
        ], ['icon' => 'badge-percent', 'prefix' => 'PC-', 'due' => 'Expires on', 'list' => ['promotion', 'discount_type', 'times_used']]],
    ], ['logic' => PromoCodeLogic::class]],

    'seo-analytics-dashboard' => ['SEO & analytics dashboard', 'line-chart', 'Keyword rankings, short links and QR codes with click counts.', [
        'keywords' => ['Keyword', 'Keyword', 'tracking,paused', [
            'target_url:url|Target page',
            'position:number|Current position',
            'previous_position:number|Previous position',
            'monthly_searches:number|Monthly searches',
        ], ['icon' => 'search', 'prefix' => 'KW-', 'date' => 'Checked on', 'list' => ['position', 'previous_position', 'monthly_searches']]],
        'links' => ['Short link / QR code', 'Label', 'active,disabled', [
            'destination:url|Destination URL*',
            'short_code|Short code*',
            'qr_code:checkbox|Print as QR code',
            'clicks:number',
        ], ['icon' => 'qr-code', 'prefix' => 'LNK-', 'plural' => 'Short links & QR codes', 'list' => ['short_code', 'clicks']]],
    ], ['logic' => SeoLogic::class]],

    'ads-manager-meta-google' => ['Ads manager (Meta/Google reporting)', 'bar-chart-3', 'Ad campaigns across Meta and Google with spend and results.', [
        'campaigns' => ['Ad campaign', 'Campaign name', 'draft,active,paused,ended', [
            'platform:select=meta,google,tiktok,linkedin,x*',
            'objective:select=awareness,traffic,leads,sales,app_installs',
            'impressions:number',
            'clicks:number',
            'conversions:number',
            'spend:money',
        ], ['icon' => 'bar-chart-3', 'prefix' => 'AD-', 'amount' => 'Budget', 'date' => 'Start date', 'due' => 'End date', 'assignee' => true, 'list' => ['platform', 'clicks', 'spend']]],
    ], ['logic' => AdsLogic::class]],

    'digital-signage-tv-display' => ['Digital signage / TV display (menus, queues, adverts)', 'tv', 'Screens and the playlists of menus, queues and adverts shown on them.', [
        'screens' => ['Screen', 'Screen name', 'online,offline', [
            'location*',
            'orientation:select=landscape,portrait',
            'playlist:record=playlists|Playlist',
        ], ['icon' => 'tv', 'prefix' => 'SCR-', 'list' => ['location', 'playlist']]],
        'playlists' => ['Playlist', 'Playlist name', 'active,draft', [
            'slides:textarea|Slides (image URLs or text, one per line)*',
            'seconds_per_slide:number|Seconds per slide',
        ], ['icon' => 'list-video', 'prefix' => 'PLY-', 'list' => ['seconds_per_slide']]],
    ], ['logic' => SignageLogic::class]],

    'membership-site-paywall' => ['Membership site & paywall', 'lock', 'Members-only content, digital downloads and membership tiers.', [
        'tiers' => ['Membership tier', 'Tier name', 'active,retired', [
            'interval:select=monthly,yearly,lifetime*',
            'price:money*',
            'benefits:textarea',
        ], ['icon' => 'layers', 'prefix' => 'TR-', 'list' => ['interval', 'price']]],
        'members' => ['Member', 'Member name', 'active,expired,cancelled', [
            'tier:record=tiers|Tier*',
            'email:email',
        ], ['icon' => 'user-round', 'prefix' => 'MB-', 'contact' => 'Contact', 'date' => 'Joined on', 'due' => 'Renews on', 'list' => ['tier', 'email']]],
        'content' => ['Content item', 'Title', 'draft,published', [
            'type:select=article,video,download,ebook,course*',
            'min_tier:record=tiers|Minimum tier',
            'url:url|File / page link',
            'price:money|One-off price',
        ], ['icon' => 'file-lock', 'prefix' => 'CNT-', 'plural' => 'Content', 'date' => 'Published on', 'list' => ['type', 'min_tier']]],
    ], ['logic' => MembershipLogic::class]],

    'podcast-media-hosting' => ['Podcast / media hosting', 'mic', 'Shows, episodes and download statistics.', [
        'shows' => ['Show', 'Show name', 'active,on_hold,ended', [
            'host',
            'category',
            'feed_url:url|RSS feed',
        ], ['icon' => 'radio', 'prefix' => 'SHW-', 'list' => ['host', 'category']]],
        'episodes' => ['Episode', 'Episode title', 'planned,recorded,edited,published', [
            'show:record=shows|Show*',
            'episode_number:number|Episode number',
            'guest',
            'audio_url:url|Audio file',
            'duration:number|Duration (minutes)',
            'downloads:number',
        ], ['icon' => 'mic', 'prefix' => 'EP-', 'date' => 'Release date', 'assignee' => true, 'list' => ['show', 'episode_number', 'downloads']]],
    ], ['logic' => PodcastLogic::class]],

    'advertising-agency-job-bags' => ['Advertising agency job bags & media booking', 'briefcase', 'Client job bags and the media space booked for them.', [
        'jobs' => ['Job bag', 'Job title', 'brief,in_progress,client_review,approved,delivered,billed', [
            'brief:textarea*',
            'deliverables:textarea',
        ], ['icon' => 'briefcase', 'prefix' => 'JB-', 'contact' => 'Client', 'amount' => 'Job value', 'date' => 'Opened on', 'due' => 'Deadline', 'assignee' => true]],
        'bookings' => ['Media booking', 'Placement', 'requested,confirmed,run,invoiced', [
            'job:record=jobs|Job bag*',
            'media:select=tv,radio,print,billboard,digital,cinema*',
            'vendor|Media owner',
            'insertions:number',
        ], ['icon' => 'radio-tower', 'prefix' => 'MB-', 'amount' => 'Cost', 'date' => 'Run date', 'list' => ['job', 'media', 'vendor']]],
    ], ['logic' => AgencyLogic::class]],

    'brand-asset-library' => ['Brand asset library', 'palette', 'Logos, brand guidelines, templates and approved imagery.', [
        'assets' => ['Brand asset', 'Asset name', 'approved,draft,retired', [
            'type:select=logo,colour_palette,font,template,photo,video,guideline*',
            'file_url:url|File link*',
            'usage_notes:textarea|Usage notes',
        ], ['icon' => 'palette', 'prefix' => 'BA-', 'list' => ['type', 'file_url']]],
    ], ['logic' => BrandAssetLogic::class]],
];
