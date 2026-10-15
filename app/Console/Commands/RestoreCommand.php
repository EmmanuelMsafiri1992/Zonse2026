<?php

namespace App\Console\Commands;

use App\Support\Health;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PharData;
use RuntimeException;
use Throwable;

/**
 * Puts a zonseo:backup archive back: the database is replaced by the dump and the uploaded files are
 * copied back in (newer uploads are kept). A fresh backup of what is there now is taken first.
 */
class RestoreCommand extends Command
{
    protected $signature = 'zonseo:restore
        {archive? : The .tar.gz to restore (defaults to the newest backup)}
        {--connection= : Database connection to restore into (defaults to the default connection)}
        {--no-uploads : Leave uploaded files as they are}
        {--no-safety-backup : Skip backing up the current data first}
        {--force : Do not ask for confirmation}';

    protected $description = 'Restore the database and uploaded files from a zonseo:backup archive';

    public function handle(): int
    {
        $archive = $this->argument('archive') ?: Health::latestBackup();
        if (! $archive || ! is_file($archive)) {
            $this->error($archive ? 'No backup at '.$archive.'.' : 'No backups found in '.config('zonseo.backup.path').'.');

            return self::FAILURE;
        }

        $connection = (string) ($this->option('connection') ?: config('database.default'));
        $this->warn('This replaces everything in the "'.$connection.'" database with '.basename($archive).'.');
        if (! $this->option('force') && ! $this->confirm('Restore it?')) {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        if (! $this->option('no-safety-backup')) {
            if (Artisan::call('zonseo:backup', ['--connection' => $connection]) !== self::SUCCESS) {
                $this->error('Could not back up the current data first, so nothing was restored. Use --no-safety-backup to go ahead anyway.');

                return self::FAILURE;
            }
            $this->line('Backed up the current data first: '.basename((string) Health::latestBackup()));
        }

        $work = storage_path('app/restore-'.Str::random(8));
        File::ensureDirectoryExists($work);

        try {
            // Unpacking straight from the .tar.gz is unreliable, so it is unzipped to a plain .tar first.
            $tar = $work.DIRECTORY_SEPARATOR.'backup.tar';
            if (! copy('compress.zlib://'.$archive, $tar)) {
                throw new RuntimeException('Could not unzip '.basename($archive).'.');
            }
            (new PharData($tar))->extractTo($work, null, true);
            unset($tar);
            $this->restoreDatabase($connection, $work);
            $this->line('Database restored.');

            if (! $this->option('no-uploads') && is_dir($work.'/uploads')) {
                $this->line('Uploads: '.$this->restoreUploads($work.'/uploads').' files');
            }
        } catch (Throwable $e) {
            report($e);
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($work);
        }

        $this->info('Restored '.basename($archive).'. Run "php artisan migrate --force" if the backup is from an older version.');

        return self::SUCCESS;
    }

    protected function restoreDatabase(string $connection, string $work): void
    {
        $config = config('database.connections.'.$connection);
        if (! is_array($config)) {
            throw new RuntimeException('Unknown database connection "'.$connection.'".');
        }

        if ($config['driver'] === 'sqlite') {
            $dump = $work.'/database.sqlite';
            if (! is_file($dump) || $config['database'] === ':memory:') {
                throw new RuntimeException('This backup has no SQLite database to restore into "'.$config['database'].'".');
            }
            DB::purge($connection);
            File::copy($dump, $config['database']);

            return;
        }

        $dump = $work.'/database.sql';
        if (! is_file($dump)) {
            throw new RuntimeException('This backup has no database.sql.');
        }

        if (in_array($config['driver'], ['mysql', 'mariadb'], true)) {
            $command = [config('zonseo.backup.mysql'), '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], $config['database']];
            $environment = ['MYSQL_PWD' => (string) $config['password']];
        } elseif ($config['driver'] === 'pgsql') {
            $command = [config('zonseo.backup.psql'), '--quiet', '--set=ON_ERROR_STOP=1', '--host='.$config['host'], '--port='.$config['port'],
                '--username='.$config['username'], '--dbname='.$config['database']];
            $environment = ['PGPASSWORD' => (string) $config['password']];
        } else {
            throw new RuntimeException('Restores do not support the "'.$config['driver'].'" driver.');
        }

        $input = fopen($dump, 'rb');
        try {
            $result = Process::env($environment)->timeout(3600)->input($input)->run($command);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
        DB::purge($connection);

        if ($result->failed()) {
            throw new RuntimeException('Loading the database failed: '.Str::limit(trim($result->errorOutput()) ?: 'no output', 300));
        }
    }

    /** Copy the backed-up files over the uploads folder, keeping anything added since. */
    protected function restoreUploads(string $from): int
    {
        $to = rtrim((string) config('zonseo.backup.uploads'), '/\\');
        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $target = $to.DIRECTORY_SEPARATOR.Str::after($file->getPathname(), $from.DIRECTORY_SEPARATOR);
                File::ensureDirectoryExists(dirname($target));
                File::copy($file->getPathname(), $target);
                $count++;
            }
        }

        return $count;
    }
}
