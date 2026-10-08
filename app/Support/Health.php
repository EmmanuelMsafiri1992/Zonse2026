<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Health checks shared by /up (for uptime monitors), zonseo:monitor (alert emails) and
 * zonseo:production-check. The scheduler and queue prove they are alive by writing heartbeats.
 */
class Health
{
    public const HEARTBEATS = ['scheduler', 'queue'];

    /** Record that a background process is alive. */
    public static function beat(string $process): void
    {
        Cache::forever('zonseo:heartbeat:'.$process, now()->toIso8601String());
    }

    public static function lastBeat(string $process): ?Carbon
    {
        $value = Cache::get('zonseo:heartbeat:'.$process);

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Checks that mean the site is down or about to be: database, cache and, in production,
     * the scheduler and queue worker.
     *
     * @return array<string, array{ok: bool, message: string}>
     */
    public function critical(): array
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
        ];
        if (app()->isProduction()) {
            $checks['scheduler'] = $this->heartbeat('scheduler', (int) config('zonseo.monitor.scheduler_stale_minutes'), 'Run "php artisan schedule:run" every minute from cron.');
            $checks['queue'] = $this->heartbeat('queue', (int) config('zonseo.monitor.queue_stale_minutes'), 'Keep "php artisan queue:work" running under Supervisor or systemd.');
        }

        return $checks;
    }

    /**
     * Everything critical() covers plus slower-burning problems worth an email.
     *
     * @return array<string, array{ok: bool, message: string}>
     */
    public function all(): array
    {
        return $this->critical() + [
            'queue_backlog' => $this->queueBacklog(),
            'failed_jobs' => $this->failedJobs(),
            'backup' => $this->backup(),
            'disk' => $this->disk(),
        ];
    }

    /** @return array{ok: bool, message: string} */
    protected function database(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true, 'message' => 'Connected.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Database unreachable: '.Str::limit($e->getMessage(), 160)];
        }
    }

    /** @return array{ok: bool, message: string} */
    protected function cache(): array
    {
        try {
            $token = Str::random(8);
            Cache::put('zonseo:health', $token, 60);

            return Cache::get('zonseo:health') === $token
                ? ['ok' => true, 'message' => 'Read and write OK.']
                : ['ok' => false, 'message' => 'Cache did not return what was written.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Cache unavailable: '.Str::limit($e->getMessage(), 160)];
        }
    }

    /** @return array{ok: bool, message: string} */
    protected function heartbeat(string $process, int $staleMinutes, string $fix): array
    {
        $last = self::lastBeat($process);
        if (! $last) {
            return ['ok' => false, 'message' => 'No '.$process.' heartbeat yet. '.$fix];
        }

        return $last->gt(now()->subMinutes($staleMinutes))
            ? ['ok' => true, 'message' => 'Last seen '.$last->diffForHumans().'.']
            : ['ok' => false, 'message' => 'Last seen '.$last->diffForHumans().'. '.$fix];
    }

    /** @return array{ok: bool, message: string} */
    protected function queueBacklog(): array
    {
        if (config('queue.default') !== 'database') {
            return ['ok' => true, 'message' => 'Not using the database queue.'];
        }
        $waiting = DB::table(config('queue.connections.database.table', 'jobs'))->count();
        $limit = (int) config('zonseo.monitor.queue_backlog');

        return ['ok' => $waiting < $limit, 'message' => $waiting.' jobs waiting'.($waiting < $limit ? '.' : ' (limit '.$limit.'). Add queue workers or check for a stuck job.')];
    }

    /** @return array{ok: bool, message: string} */
    protected function failedJobs(): array
    {
        $table = config('queue.failed.table', 'failed_jobs');
        $recent = DB::table($table)->where('failed_at', '>=', now()->subHour())->count();

        return $recent === 0
            ? ['ok' => true, 'message' => 'No failed jobs in the last hour.']
            : ['ok' => false, 'message' => $recent.' jobs failed in the last hour. See "php artisan queue:failed".'];
    }

    /** @return array{ok: bool, message: string} */
    protected function backup(): array
    {
        $latest = self::latestBackup();
        if (! $latest) {
            return ['ok' => false, 'message' => 'No backups yet. Schedule runs "zonseo:backup" daily at 01:30.'];
        }
        $at = Carbon::createFromTimestamp(File::lastModified($latest));
        $fresh = $at->gt(now()->subHours((int) config('zonseo.monitor.backup_stale_hours')));

        return ['ok' => $fresh, 'message' => 'Latest backup '.basename($latest).' from '.$at->diffForHumans().($fresh ? '.' : '. Check "php artisan zonseo:backup".')];
    }

    /** @return array{ok: bool, message: string} */
    protected function disk(): array
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());
        if (! $total || $free === false) {
            return ['ok' => true, 'message' => 'Disk space could not be read.'];
        }

        return $this->evaluateDisk($free, $total);
    }

    /**
     * Low only when both the share and the absolute amount are short, so a large
     * drive with plenty of gigabytes left does not alarm at a small percentage.
     *
     * @return array{ok: bool, message: string}
     */
    public function evaluateDisk(float $freeBytes, float $totalBytes): array
    {
        $percent = round($freeBytes / $totalBytes * 100, 1);
        $gigabytes = round($freeBytes / 1073741824, 1);
        $low = $percent < (float) config('zonseo.monitor.min_free_disk_percent')
            && $gigabytes < (float) config('zonseo.monitor.min_free_disk_gb');

        return ['ok' => ! $low, 'message' => $percent.'% free ('.$gigabytes.' GB).'];
    }

    public static function latestBackup(): ?string
    {
        $files = glob(rtrim((string) config('zonseo.backup.path'), '/\\').DIRECTORY_SEPARATOR.'zonseo-*.tar.gz') ?: [];
        usort($files, fn (string $a, string $b) => File::lastModified($b) <=> File::lastModified($a));

        return $files[0] ?? null;
    }
}
