# Deploying Zonseo

This guide takes a fresh Ubuntu 24.04 server to a live Zonseo site. Paths assume the app lives in
`/var/www/zonseo` and runs as `www-data`; adjust to taste.

> **Never run the demo seeder in production.** `DemoSeeder` creates logins with the password
> `password` and refuses to run when `APP_ENV=production`. `php artisan db:seed --force` is safe: it seeds the
> catalogue, plans and professions, and adds demo data only when `APP_ENV=local`.

## 1. Server requirements

- PHP 8.4 with `bcmath, ctype, curl, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, phar, tokenizer, xml, zip`
- MySQL 8 / MariaDB 10.6+ (or PostgreSQL 15+) and the matching `mysqldump` / `pg_dump` client for backups
- Nginx, Composer 2, Node 20+ (only to build assets), Supervisor, cron
- A TLS certificate (Let's Encrypt via `certbot --nginx`)

## 2. Environment

Copy `.env.example` to `.env`, then set at least:

| Key | Live value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | `php artisan key:generate` **once**. Keep a copy offsite: saved payment-gateway and SMS keys are encrypted with it, and they are unreadable without it. |
| `APP_URL` | `https://your-domain` (invoice links and payment webhooks are built from it) |
| `LOG_LEVEL` | `warning` |
| `DB_*` | Your database credentials |
| `SESSION_SECURE_COOKIE` | `true` |
| `QUEUE_CONNECTION` | `database` (or `redis`) |
| `MAIL_*` | A real mailer (SMTP, Postmark, SES, …) |
| `ALERT_EMAIL` | Where health alerts go |
| `TRUSTED_PROXIES` | Proxy IPs, or `*`, if behind Cloudflare / a load balancer |
| `BACKUP_PATH` | Optional; defaults to `storage/app/backups` |

## 3. First deploy

```bash
cd /var/www/zonseo
git clone https://github.com/EmmanuelMsafiri1992/Zonse2026.git .
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env   # then edit it (section 2)
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan optimize
chown -R www-data:www-data storage bootstrap/cache
```

Create your own super-admin account by registering through the site, then:

```bash
php artisan tinker --execute 'App\Models\User::where("email", "you@example.com")->update(["is_super_admin" => true]);'
```

## 4. Nginx

```nginx
server {
    listen 80;
    server_name your-domain;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain;
    root /var/www/zonseo/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/your-domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain/privkey.pem;

    client_max_body_size 20M;
    server_tokens off;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known) { deny all; }

    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

The app itself sends `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`
and, over HTTPS in production, `Strict-Transport-Security`.

## 5. Queue worker (Supervisor)

SMS messages, emails and the queue heartbeat run on the queue. Without a worker nothing gets sent.

`/etc/supervisor/conf.d/zonseo-worker.conf`:

```ini
[program:zonseo-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/zonseo/artisan queue:work --sleep=3 --tries=3 --max-time=3600
user=www-data
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/zonseo/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start "zonseo-worker:*"
```

## 6. Scheduler (cron)

`sudo crontab -u www-data -e`:

```cron
* * * * * cd /var/www/zonseo && php artisan schedule:run >> /dev/null 2>&1
```

What it runs (`routes/console.php`; check with `php artisan schedule:list`):

| When | Task |
| --- | --- |
| every minute | scheduler heartbeat |
| every 5 minutes | queue heartbeat (proves the worker is alive) |
| every 15 minutes | `zonseo:monitor`: health checks, emails `ALERT_EMAIL` on problems |
| 01:30 | `zonseo:backup`: database + uploads archive, keeps `BACKUP_KEEP_DAYS` days |
| 02:00 | `zonseo:run-app-schedules` |
| 09:00 | `zonseo:send-sms-reminders` |
| daily / weekly | prune failed jobs (30 days), expired password resets, old activity log |

## 7. Pre-flight check

```bash
php artisan zonseo:production-check
```

This lists everything that is unsafe for a live site: debug mode, a non-HTTPS URL, the sync queue, the log mailer,
leftover demo logins (`admin@zonseo.test`, `demo@zonseo.test`), no alert email, uncached config, a missing storage link,
and every health check below. Fix every `FAIL` before you announce the site. The scheduler and queue checks pass
a few minutes after cron and Supervisor start.

## 8. Monitoring

- **Uptime:** point UptimeRobot, Better Stack or similar at `https://your-domain/up`. It returns 200 when the
  database and cache work and the scheduler and queue heartbeats are fresh, and 500 otherwise.
- **Alerts:** `zonseo:monitor` also checks the queue backlog, jobs failed in the last hour, backup age (> 26 h)
  and free disk (under 10 % *and* under 5 GB). It logs at `critical` level and emails `ALERT_EMAIL` once per new problem, then again
  every 6 hours while it persists. Run it by hand to see the current state.
- Thresholds live in `config/zonseo.php` under `monitor`.

## 9. Backups

`php artisan zonseo:backup` writes `zonseo-YYYYmmdd-HHiiss.tar.gz` containing `database.sql` (or
`database.sqlite`) and `uploads/` (logos and other files from `storage/app/public`).

**Copy them off the server.** A backup on the same disk does not survive losing the server. For example, with
[rclone](https://rclone.org) configured for S3, Backblaze B2 or Google Drive:

```cron
15 2 * * * rclone copy /var/www/zonseo/storage/app/backups remote:zonseo-backups --max-age 48h
```

Also store `.env` (above all `APP_KEY`) in a password manager.

**Restore:**

```bash
tar -xzf zonseo-20261008-013000.tar.gz -C /tmp/restore
mysql -u zonseo -p zonseo < /tmp/restore/database.sql
cp -r /tmp/restore/uploads/. /var/www/zonseo/storage/app/public/
```

Test a restore on a spare database at least once.

## 10. Deploying updates

```bash
cd /var/www/zonseo
php artisan down --retry=60
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan up
```

`migrate:fresh`, `db:wipe` and other destructive database commands are blocked in production.

## 11. Security notes

- Logins are throttled to 5 attempts per minute. Public invoice and quote links are limited to 60 requests a
  minute, payment pages to 20, and SMS sending to 10.
- Payment webhooks skip CSRF and are verified against the gateway in the controller.
- Gateway and SMS credentials are stored encrypted with `APP_KEY`.
- Keep `APP_DEBUG=false`: debug pages expose environment variables.
- Run `composer audit` and `npm audit` regularly and apply security updates.
