<?php

namespace App\Console\Commands;

use App\Support\Health;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MonitorCommand extends Command
{
    protected $signature = 'zonseo:monitor';

    protected $description = 'Check queue, scheduler, failed jobs, backups and disk space; log and email the alert address about problems';

    public function handle(Health $health): int
    {
        $checks = $health->all();
        $problems = collect($checks)->reject(fn (array $check) => $check['ok']);

        foreach ($checks as $name => $check) {
            $this->line(($check['ok'] ? '<info>OK</info>   ' : '<error>FAIL</error> ').str_pad($name, 14).$check['message']);
        }

        if ($problems->isEmpty()) {
            Cache::forget('zonseo:monitor:alerted');

            return self::SUCCESS;
        }

        Log::critical('Zonseo health check failed', $problems->all());

        // Email once per distinct set of problems, then again only every few hours while they persist.
        $signature = md5($problems->keys()->implode(','));
        $address = config('zonseo.monitor.alert_email');
        if ($address && Cache::get('zonseo:monitor:alerted') !== $signature) {
            $body = 'These checks are failing on '.config('app.url').":\n\n"
                .$problems->map(fn (array $check, string $name) => '- '.$name.': '.$check['message'])->implode("\n")
                ."\n\nRun \"php artisan zonseo:monitor\" on the server for the full picture.";
            try {
                Mail::raw($body, fn ($message) => $message->to($address)->subject('['.config('app.name').'] '.$problems->count().' health check(s) failing'));
                Cache::put('zonseo:monitor:alerted', $signature, now()->addHours((int) config('zonseo.monitor.repeat_alert_hours')));
            } catch (Throwable $e) {
                report($e);
                $this->error('Could not send the alert email: '.$e->getMessage());
            }
        }

        return self::FAILURE;
    }
}
