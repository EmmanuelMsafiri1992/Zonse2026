<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;
use ZipArchive;

/** The workspace audit log (sign-ins, settings, record changes) and the full data export. */
class AuditLogTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_admins_see_the_audit_log_and_members_do_not(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        Audit::log('settings', 'test', 'Renamed the shop', workspace: $workspace);

        $this->actingAs($owner)->get(route('settings.audit.index'))
            ->assertOk()->assertSee('Renamed the shop')->assertSee('Settings &amp; team', false);

        $member = $this->memberOf($workspace);
        $this->actingAs($member)->get(route('settings.audit.index'))->assertForbidden();
        $this->actingAs($member)->get(route('settings.audit.export'))->assertForbidden();
    }

    public function test_the_audit_log_filters_by_text_category_person_and_date(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        Audit::log('settings', 'test', 'Changed the tax number', workspace: $workspace);
        Audit::log('data', 'test', 'Downloaded a backup', workspace: $workspace);

        $this->actingAs($owner)->get(route('settings.audit.index', ['q' => 'tax']))
            ->assertSee('Changed the tax number')->assertDontSee('Downloaded a backup');
        $this->actingAs($owner)->get(route('settings.audit.index', ['category' => 'data']))
            ->assertSee('Downloaded a backup')->assertDontSee('Changed the tax number');
        $this->actingAs($owner)->get(route('settings.audit.index', ['from' => now()->addDay()->toDateString()]))
            ->assertDontSee('Downloaded a backup')->assertSee('Nothing logged yet');
        $this->actingAs($owner)->get(route('settings.audit.index', ['category' => 'nonsense']))->assertSessionHasErrors('category');
    }

    public function test_entries_from_other_workspaces_are_never_shown(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        [, $other] = $this->ownerWithWorkspace();
        Audit::log('settings', 'test', 'Secret change elsewhere', workspace: $other);

        $this->actingAs($owner)->get(route('settings.audit.index'))->assertOk()->assertDontSee('Secret change elsewhere');
        $this->actingAs($owner)->get(route('settings.audit.export'))->assertOk()->assertDontSee('Secret change elsewhere');
    }

    public function test_the_audit_log_exports_as_csv_and_records_the_export(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        Audit::log('settings', 'test', 'Changed the currency', workspace: $workspace);

        $response = $this->actingAs($owner)->get(route('settings.audit.export'));
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('When,Category,Event,Description,By', $csv);
        $this->assertStringContainsString('Changed the currency', $csv);

        $this->assertDatabaseHas('activity_log', ['workspace_id' => $workspace->id, 'log_name' => 'data', 'event' => 'audit-exported', 'causer_id' => $owner->id]);
    }

    public function test_sign_ins_and_failed_attempts_are_logged_to_the_workspace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->post('/login', ['email' => $owner->email, 'password' => 'wrong-password']);
        $this->post('/login', ['email' => $owner->email, 'password' => 'password'])->assertRedirect();

        $this->assertDatabaseHas('activity_log', ['workspace_id' => $workspace->id, 'log_name' => 'access', 'event' => 'login-failed', 'causer_id' => null]);
        $this->assertDatabaseHas('activity_log', ['workspace_id' => $workspace->id, 'log_name' => 'access', 'event' => 'login', 'causer_id' => $owner->id]);
    }

    public function test_settings_and_team_changes_are_logged(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $module = Module::where('key', 'helpdesk')->firstOrFail();
        $member = $this->memberOf($workspace);

        $this->actingAs($owner)->post(route('settings.modules.enable', $module));
        $this->actingAs($owner)->patch(route('settings.members.update', $member), ['role' => 'admin']);
        $this->actingAs($owner)->post(route('settings.branches.store'), ['name' => 'Bulawayo']);

        $events = Activity::where('workspace_id', $workspace->id)->where('log_name', 'settings')->pluck('event');
        $this->assertContains('module-enabled', $events);
        $this->assertContains('member-updated', $events);
        $this->assertContains('branch-created', $events);
        $this->actingAs($owner)->get(route('settings.audit.index'))->assertSee('Turned on the '.$module->name.' app');
    }

    public function test_record_changes_carry_the_workspace_column(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        app(WorkspaceContext::class)->set($workspace);
        $this->actingAs($owner);

        Contact::factory()->for($workspace)->create(['name' => 'Chipo Logged']);

        $this->assertSame(Activity::count(), Activity::whereNotNull('workspace_id')->count());
    }

    public function test_the_owner_downloads_every_record_without_secrets(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->forceFill(['settings' => ['sms' => ['africastalking' => ['api_key' => 'super-secret-key']]]])->save();
        Contact::factory()->for($workspace)->create(['name' => 'Farai Exported']);
        [, $other] = $this->ownerWithWorkspace();
        Contact::factory()->for($other)->create(['name' => 'Someone Elsewhere']);

        $this->actingAs($owner)->get(route('settings.data-export.index'))->assertOk()->assertSee('Download ZIP')->assertSee('contacts.csv');

        $response = $this->actingAs($owner)->post(route('settings.data-export.store'));
        $response->assertOk()->assertDownload();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $contacts = $zip->getFromName('contacts.csv');
        $this->assertStringContainsString('Farai Exported', $contacts);
        $this->assertStringNotContainsString('Someone Elsewhere', $contacts);
        $this->assertStringContainsString($owner->email, $zip->getFromName('members.csv'));
        $this->assertNotFalse($zip->getFromName('audit_log.csv'));
        $this->assertFalse($zip->getFromName('settings.csv'));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content = $zip->getFromIndex($i);
            $this->assertStringNotContainsString('super-secret-key', $content);
            $this->assertStringNotContainsString($owner->password, $content);
        }
        $zip->close();

        $this->assertDatabaseHas('activity_log', ['workspace_id' => $workspace->id, 'event' => 'workspace-exported', 'causer_id' => $owner->id]);
    }

    public function test_only_the_owner_can_export_all_data(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $admin = $this->memberOf($workspace, 'admin');

        $this->actingAs($admin)->get(route('settings.data-export.index'))->assertOk()->assertSee('Only the workspace owner');
        $this->actingAs($admin)->post(route('settings.data-export.store'))->assertForbidden();
    }
}
