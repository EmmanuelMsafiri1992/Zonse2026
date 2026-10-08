<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Health;
use Illuminate\Console\Command;
use Throwable;

class ProductionCheck extends Command
{
    /** Accounts created by the demo seeder; they have the password "password" and must never exist live. */
    public const DEMO_EMAILS = ['admin@zonseo.test', 'demo@zonseo.test'];

    protected $signature = 'zonseo:production-check';

    protected $description = 'List settings and leftovers that are unsafe for a live site (debug mode, demo logins, sync queue...)';

    public function handle(Health $health): int
    {
        $checks = $this->configuration() + collect($health->all())
            ->mapWithKeys(fn (array $check, string $name) => ['health: '.$name => $check])->all();

        $failures = 0;
        foreach ($checks as $name => $check) {
            $this->line(($check['ok'] ? '<info>OK</info>   ' : '<error>FAIL</error> ').str_pad($name, 26).$check['message']);
            $failures += $check['ok'] ? 0 : 1;
        }

        $this->newLine();
        if ($failures) {
            $this->error($failures.' '.str('problem')->plural($failures).' to fix before going live.');

            return self::FAILURE;
        }
        $this->info('Ready for production.');

        return self::SUCCESS;
    }

    /** @return array<string, array{ok: bool, message: string}> */
    protected function configuration(): array
    {
        $url = (string) config('app.url');
        $logLevel = (string) config('logging.channels.single.level', 'debug');
        try {
            $demo = User::query()->whereIn('email', self::DEMO_EMAILS)->pluck('email')->all();
        } catch (Throwable) {
            $demo = [];
        }

        return [
            'APP_ENV' => $this->check(app()->isProduction(), 'APP_ENV is "'.app()->environment().'".', 'Set APP_ENV=production.'),
            'APP_DEBUG' => $this->check(! config('app.debug'), 'Debug mode is off.', 'Set APP_DEBUG=false: debug pages show secrets to visitors.'),
            'APP_KEY' => $this->check(filled(config('app.key')), 'Application key is set.', 'Run "php artisan key:generate" once, then keep the key safe: saved gateway and SMS keys are encrypted with it.'),
            'APP_URL' => $this->check(str_starts_with($url, 'https://'), $url, 'APP_URL must be the public https:// address; invoice links and webhooks use it.'),
            'Secure cookies' => $this->check((bool) config('session.secure'), 'Session cookie is HTTPS-only.', 'Set SESSION_SECURE_COOKIE=true.'),
            'Queue' => $this->check(config('queue.default') !== 'sync', 'Queue driver "'.config('queue.default').'".', 'Set QUEUE_CONNECTION=database (or redis) and run a worker; "sync" makes pages wait on SMS providers.'),
            'Mail' => $this->check(! in_array(config('mail.default'), ['log', 'array'], true), 'Mailer "'.config('mail.default').'".', 'Configure a real mailer (MAIL_MAILER=smtp etc.) so invitations and password resets arrive.'),
            'Log level' => $this->check($logLevel !== 'debug', 'Log level "'.$logLevel.'".', 'Set LOG_LEVEL=warning so debug noise does not fill the disk.'),
            'Demo accounts' => $this->check($demo === [], 'No demo logins.', 'Delete the demo users ('.implode(', ', $demo).'): they use the password "password".'),
            'Alert email' => $this->check(filled(config('zonseo.monitor.alert_email')), 'Alerts go to '.config('zonseo.monitor.alert_email').'.', 'Set ALERT_EMAIL so you hear about stalled queues and failed backups.'),
            'Config cache' => $this->check(app()->configurationIsCached(), 'Configuration is cached.', 'Run "php artisan optimize" after each deploy.'),
            'Storage link' => $this->check(is_link(public_path('storage')) || is_dir(public_path('storage')), 'public/storage is linked.', 'Run "php artisan storage:link" so logos display.'),
        ];
    }

    /** @return array{ok: bool, message: string} */
    protected function check(bool $ok, string $good, string $fix): array
    {
        return ['ok' => $ok, 'message' => $ok ? $good : $fix];
    }
}
