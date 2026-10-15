<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated IPs/CIDRs of the load balancer or CDN in front of the app
    | (or "*" when the app is only reachable through it). Needed so HTTPS and
    | client IPs (used by rate limits) are read correctly behind Cloudflare etc.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | zonseo:backup writes a .tar.gz with a database dump and uploaded files.
    | Copy the folder off the server (rclone, rsync, a storage-box mount) —
    | a backup that only lives on the same disk does not survive losing it.
    |
    */

    'backup' => [
        'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
        'include_uploads' => (bool) env('BACKUP_INCLUDE_UPLOADS', true),
        'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
        'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
        'mysql' => env('BACKUP_MYSQL', 'mysql'),
        'psql' => env('BACKUP_PSQL', 'psql'),
        'uploads' => storage_path('app/public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    |
    | zonseo:monitor runs every 15 minutes and emails alert_email (once per
    | problem every few hours) when the queue or scheduler stalls, jobs fail,
    | backups stop or the disk fills up. /up reports the same checks to an
    | uptime monitor.
    |
    */

    'monitor' => [
        'alert_email' => env('ALERT_EMAIL'),
        'scheduler_stale_minutes' => 5,
        'queue_stale_minutes' => 15,
        'queue_backlog' => 500,
        'backup_stale_hours' => 26,
        'min_free_disk_percent' => 10,
        'min_free_disk_gb' => 5,
        'repeat_alert_hours' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Partner (reseller) program
    |--------------------------------------------------------------------------
    |
    | Partners earn commission_percent of each paying client's monthly plan
    | price. Payouts are made by hand; the partner page shows what is owed.
    | Custom domains point a CNAME at cname_target (defaults to the app host).
    |
    */

    'partners' => [
        'commission_percent' => (float) env('PARTNER_COMMISSION_PERCENT', 20),
        'cname_target' => env('CUSTOM_DOMAIN_TARGET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zonseob's own Claude key
    |--------------------------------------------------------------------------
    |
    | Used by the chat on the public pages (landing_model, a cheap model is
    | plenty) and by Zonseob AI, the Claude access people buy with tokens
    | (model). Workspaces' own assistant keys are separate and set in-app.
    | Without a key the public chat answers from the help centre instead.
    |
    */

    'assistant' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'landing_model' => env('ANTHROPIC_LANDING_MODEL', 'claude-haiku-5-5'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zonseob AI (Claude access paid for with tokens)
    |--------------------------------------------------------------------------
    |
    | Every person gets welcome_tokens to try it. Packs are "key=tokens=usd"
    | pairs and are paid for through the platform checkout, so people without
    | foreign currency can buy them with EcoCash or a local transfer. An answer
    | costs its input tokens plus output_weight times its output tokens, which
    | keeps the price of a pack in step with what Anthropic charges. Nobody can
    | ask with fewer than min_balance tokens left.
    |
    */

    'ai' => [
        'welcome_tokens' => (int) env('ZONSEOB_AI_WELCOME_TOKENS', 20000),
        'packs' => env('ZONSEOB_AI_PACKS', 'starter=250000=5,standard=1000000=15,large=5000000=60'),
        'output_weight' => 4,
        'min_balance' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform checkout (paying Zonseob for plans and AI tokens)
    |--------------------------------------------------------------------------
    |
    | Prices are in USD. Paynow (EcoCash, OneMoney, local cards) charges in
    | PAYNOW_CURRENCY at the rates below; Stripe charges cards in USD. Rates
    | are "how many units per US$1" (CHECKOUT_RATES="ZWG=26.5,ZAR=18") and can
    | be overridden from the Platform payments page. Write
    | CHECKOUT_MANUAL_INSTRUCTIONS to offer bank transfers an admin confirms
    | by hand. A lapsed plan gets grace_days before the workspace is paused;
    | SUBSCRIPTION_ENFORCE_FROM (a date) postpones every pause until then.
    |
    */

    'checkout' => [
        'paynow' => [
            'integration_id' => env('PAYNOW_INTEGRATION_ID'),
            'integration_key' => env('PAYNOW_INTEGRATION_KEY'),
            'currency' => env('PAYNOW_CURRENCY', 'USD'),
        ],
        'stripe' => [
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
        'rates' => env('CHECKOUT_RATES', ''),
        'manual_instructions' => env('CHECKOUT_MANUAL_INSTRUCTIONS'),
        'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 7),
        'enforce_from' => env('SUBSCRIPTION_ENFORCE_FROM'),
    ],

];
