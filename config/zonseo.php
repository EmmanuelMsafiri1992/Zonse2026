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

];
