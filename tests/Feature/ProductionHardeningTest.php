<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use App\Models\User;
use App\Support\Health;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
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
