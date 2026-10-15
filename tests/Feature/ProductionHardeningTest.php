<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use App\Models\User;
use App\Support\Health;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = storage_path('framework/testing/hardening-'.uniqid());
        File::ensureDirectoryExists($this->scratch.'/backups');
        config(['zonseo.backup.path' => $this->scratch.'/backups']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    public function test_pages_send_security_headers(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_missing_pages_use_the_branded_error_page(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Go to dashboard');
    }

    public function test_health_endpoint_is_up_when_database_and_cache_work(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_health_endpoint_fails_in_production_when_the_scheduler_and_queue_are_silent(): void
    {
        $this->app['env'] = 'production';

        $this->get('/up')->assertStatus(500);

        Health::beat('scheduler');
        Health::beat('queue');

        $this->get('/up')->assertOk();
    }

    public function test_stale_heartbeats_count_as_down(): void
    {
        $this->app['env'] = 'production';
        Health::beat('scheduler');
        Health::beat('queue');

        $this->travel(20)->minutes();

        $checks = app(Health::class)->critical();
        $this->assertFalse($checks['scheduler']['ok']);
        $this->assertFalse($checks['queue']['ok']);
    }

    public function test_queue_heartbeat_job_records_a_beat(): void
    {
        $this->assertNull(Health::lastBeat('queue'));

        QueueHeartbeat::dispatch();

        $this->assertNotNull(Health::lastBeat('queue'));
    }

    public function test_backup_archives_the_database_and_prunes_old_archives(): void
    {
        $database = $this->scratch.'/app.sqlite';
        File::put($database, 'sqlite-bytes');
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '']]);

        $old = $this->scratch.'/backups/zonseo-20200101-000000.tar.gz';
        File::put($old, 'old');
        touch($old, now()->subDays(30)->getTimestamp());

        $this->artisan('zonseo:backup', ['--connection' => 'backup_test', '--no-uploads' => true])->assertSuccessful();

        $latest = Health::latestBackup();
        $this->assertNotNull($latest);
        $this->assertNotSame($old, $latest);
        $this->assertFileDoesNotExist($old);
        $this->assertCount(1, File::directories($this->scratch.'/backups') + File::files($this->scratch.'/backups'));

        $archive = new \PharData($latest);
        $this->assertTrue(isset($archive['database.sqlite']));
    }

    public function test_a_backup_restores_the_database_and_uploads_after_taking_a_safety_copy(): void
    {
        $database = $this->scratch.'/app.sqlite';
        $pdo = new \PDO('sqlite:'.$database);
        $pdo->exec("CREATE TABLE notes (body TEXT); INSERT INTO notes VALUES ('as backed up')");
        $pdo = null;
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => ''],
            'zonseo.backup.uploads' => $this->scratch.'/uploads']);
        File::ensureDirectoryExists($this->scratch.'/uploads/logos');
        File::put($this->scratch.'/uploads/logos/shop.png', 'logo');

        $this->artisan('zonseo:backup', ['--connection' => 'backup_test'])->assertSuccessful();
        $backup = Health::latestBackup();

        DB::connection('backup_test')->table('notes')->update(['body' => 'changed later']);
        File::delete($this->scratch.'/uploads/logos/shop.png');
        File::put($this->scratch.'/uploads/added-later.pdf', 'pdf');

        $this->artisan('zonseo:restore', ['archive' => $backup, '--connection' => 'backup_test'])
            ->expectsConfirmation('Restore it?', 'yes')
            ->expectsOutputToContain('Backed up the current data first')
            ->expectsOutputToContain('Uploads: 1 files')
            ->assertSuccessful();

        $this->assertSame(['as backed up'], DB::connection('backup_test')->table('notes')->pluck('body')->all());
        $this->assertSame('logo', File::get($this->scratch.'/uploads/logos/shop.png'));
        $this->assertFileExists($this->scratch.'/uploads/added-later.pdf');
        $this->assertCount(2, File::glob($this->scratch.'/backups/zonseo-*.tar.gz'));
        $this->assertSame([], File::glob(storage_path('app/restore-*')));

        // The safety copy holds what was there before the restore, so the restore can be undone.
        $this->artisan('zonseo:restore', ['--connection' => 'backup_test', '--no-safety-backup' => true, '--force' => true])->assertSuccessful();
        $this->assertSame(['changed later'], DB::connection('backup_test')->table('notes')->pluck('body')->all());
    }

    public function test_a_mysql_restore_feeds_the_dump_to_the_mysql_client(): void
    {
        config(['database.connections.restore_mysql' => ['driver' => 'mysql', 'host' => 'db.internal', 'port' => 3306, 'database' => 'zonseo', 'username' => 'zonseo', 'password' => 'secret']]);
        $tar = $this->scratch.'/backups/zonseo-20260101-000000.tar';
        $archive = new \PharData($tar);
        $archive->addFromString('database.sql', 'CREATE TABLE restored (id INT);');
        $archive->compress(\Phar::GZ);
        unset($archive);
        Process::fake();

        $this->artisan('zonseo:restore', ['archive' => $tar.'.gz', '--connection' => 'restore_mysql', '--no-safety-backup' => true, '--force' => true])->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command[0] === 'mysql' && in_array('--host=db.internal', $process->command, true)
            && $process->environment['MYSQL_PWD'] === 'secret' && ! in_array('secret', $process->command, true));
    }

    public function test_a_restore_changes_nothing_without_a_yes_or_a_backup(): void
    {
        $this->artisan('zonseo:restore', ['--force' => true])->expectsOutputToContain('No backups found')->assertFailed();

        $database = $this->scratch.'/app.sqlite';
        File::put($database, '');
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '']]);
        $this->artisan('zonseo:backup', ['--connection' => 'backup_test', '--no-uploads' => true])->assertSuccessful();

        $this->artisan('zonseo:restore', ['--connection' => 'backup_test'])
            ->expectsConfirmation('Restore it?', 'no')
            ->expectsOutput('Nothing was changed.')
            ->assertFailed();
        $this->assertCount(1, File::glob($this->scratch.'/backups/zonseo-*.tar.gz'));
    }

    public function test_backup_fails_cleanly_for_a_missing_database(): void
    {
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $this->scratch.'/missing.sqlite', 'prefix' => '']]);

        $this->artisan('zonseo:backup', ['--connection' => 'backup_test'])
            ->expectsOutputToContain('Backup failed')
            ->assertFailed();

        $this->assertNull(Health::latestBackup());
    }

    public function test_monitor_emails_the_alert_address_once_per_problem(): void
    {
        config(['zonseo.monitor.alert_email' => 'ops@example.com']);

        $this->artisan('zonseo:monitor')->expectsOutputToContain('No backups yet')->assertFailed();
        $this->artisan('zonseo:monitor')->assertFailed();

        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('ops@example.com', $sent->first()->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_disk_is_low_only_when_both_the_percentage_and_gigabytes_are_short(): void
    {
        $gb = 1073741824;
        $health = app(Health::class);

        $this->assertTrue($health->evaluateDisk(17 * $gb, 400 * $gb)['ok'], 'Under 10% but 17 GB free is fine');
        $this->assertTrue($health->evaluateDisk(4 * $gb, 20 * $gb)['ok'], 'Under 5 GB but 20% free is fine');
        $this->assertFalse($health->evaluateDisk(4 * $gb, 400 * $gb)['ok'], 'Under 10% and under 5 GB is low');
        $this->assertSame('4.3% free (17 GB).', $health->evaluateDisk(17 * $gb, 400 * $gb)['message']);
    }

    public function test_production_check_flags_debug_mode_and_demo_logins(): void
    {
        config(['app.debug' => true]);
        User::factory()->create(['email' => 'demo@zonseo.test']);

        $this->artisan('zonseo:production-check')
            ->expectsOutputToContain('Set APP_DEBUG=false')
            ->expectsOutputToContain('Delete the demo users (demo@zonseo.test)')
            ->assertFailed();
    }

    public function test_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        $this->app->make(DemoSeeder::class)->run();
    }
}
