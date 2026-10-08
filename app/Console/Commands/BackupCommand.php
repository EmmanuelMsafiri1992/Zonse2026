<?php

namespace App\Console\Commands;

use App\Support\Health;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PharData;
use RuntimeException;
use Throwable;

/**
 * Writes zonseo-YYYYmmdd-HHiiss.tar.gz holding database.sql (or database.sqlite) and the uploaded
 * files, then deletes archives older than the retention window.
 */
class BackupCommand extends Command
{
    protected $signature = 'zonseo:backup
        {--connection= : Database connection to dump (defaults to the default connection)}
        {--no-uploads : Leave uploaded files out}';

    protected $description = 'Back up the database and uploaded files to a dated archive, keeping the last few days';

    public function handle(): int
    {
        $directory = rtrim((string) config('zonseo.backup.path'), '/\\');
        File::ensureDirectoryExists($directory);
        $name = 'zonseo-'.now()->format('Ymd-His');
        $work = $directory.DIRECTORY_SEPARATOR.$name;
        File::ensureDirectoryExists($work);

        try {
            $connection = $this->option('connection') ?: config('database.default');
            $dump = $this->dumpDatabase((string) $connection, $work);
            $this->line('Database: '.basename($dump).' ('.$this->size(filesize($dump)).')');

            $tarPath = $directory.DIRECTORY_SEPARATOR.$name.'.tar';
            $archive = new PharData($tarPath);
            $archive->addFile($dump, basename($dump));
            $uploads = storage_path('app/public');
            if (! $this->option('no-uploads') && config('zonseo.backup.include_uploads') && is_dir($uploads)) {
                $count = 0;
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile()) {
                        $archive->addFile($file->getPathname(), 'uploads/'.str_replace('\\', '/', Str::after($file->getPathname(), $uploads.DIRECTORY_SEPARATOR)));
                        $count++;
                    }
                }
                $this->line('Uploads: '.$count.' files');
            }
            $archive->compress(\Phar::GZ);
            unset($archive);
            File::delete($tarPath);
        } catch (Throwable $e) {
            report($e);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($work);
        }

        $final = $tarPath.'.gz';
        $this->info('Backup written: '.$final.' ('.$this->size(filesize($final)).')');
        $this->prune($directory);

        return self::SUCCESS;
    }

    /** Dump the database into $work and return the file path. */
    protected function dumpDatabase(string $connection, string $work): string
    {
        $config = config('database.connections.'.$connection);
        if (! is_array($config)) {
            throw new RuntimeException('Unknown database connection "'.$connection.'".');
        }

        if ($config['driver'] === 'sqlite') {
            $source = $config['database'];
            if ($source === ':memory:' || ! is_file($source)) {
                throw new RuntimeException('The SQLite database file "'.$source.'" does not exist.');
            }
            $target = $work.DIRECTORY_SEPARATOR.'database.sqlite';
            File::copy($source, $target);

            return $target;
        }

        $target = $work.DIRECTORY_SEPARATOR.'database.sql';
        if (in_array($config['driver'], ['mysql', 'mariadb'], true)) {
            $command = [config('zonseo.backup.mysqldump'), '--single-transaction', '--quick', '--routines', '--no-tablespaces',
                '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], '--result-file='.$target, $config['database']];
            $environment = ['MYSQL_PWD' => (string) $config['password']];
        } elseif ($config['driver'] === 'pgsql') {
            $command = [config('zonseo.backup.pg_dump'), '--no-owner', '--host='.$config['host'], '--port='.$config['port'],
                '--username='.$config['username'], '--file='.$target, $config['database']];
            $environment = ['PGPASSWORD' => (string) $config['password']];
        } else {
            throw new RuntimeException('Backups do not support the "'.$config['driver'].'" driver.');
        }

        $result = Process::env($environment)->timeout(3600)->run($command);
        if ($result->failed() || ! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('Database dump failed: '.Str::limit(trim($result->errorOutput()) ?: 'no output', 300));
        }

        return $target;
    }

    /** Delete archives older than the retention window, always keeping the newest one. */
    protected function prune(string $directory): void
    {
        $latest = Health::latestBackup();
        $cutoff = now()->subDays((int) config('zonseo.backup.keep_days'))->getTimestamp();
        foreach (glob($directory.DIRECTORY_SEPARATOR.'zonseo-*.tar.gz') ?: [] as $file) {
            if ($file !== $latest && File::lastModified($file) < $cutoff) {
                File::delete($file);
                $this->line('Removed old backup '.basename($file));
            }
        }
    }

    protected function size(int|false $bytes): string
    {
        $bytes = (int) $bytes;

        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024, 1).' KB';
    }
}
