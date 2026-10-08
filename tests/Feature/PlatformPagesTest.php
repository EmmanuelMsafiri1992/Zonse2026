<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Profession;
use App\Models\Suite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class PlatformPagesTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_catalogue_seeds_every_suite_and_a_large_module_set(): void
    {
        $this->assertSame(21, Suite::count());
        $this->assertGreaterThan(300, Module::count());
        $this->assertGreaterThan(30, Profession::count());

        foreach (['contacts', 'accounting', 'invoicing', 'appointments', 'tasks', 'clinic', 'pos', 'inventory', 'ticketing'] as $key) {
            $this->assertTrue(Module::where('key', $key)->exists(), "module [$key] missing");
        }

        $this->assertSame(0, Module::whereNull('key')->orWhere('key', '')->count());
    }

    #[DataProvider('publicPages')]
    public function test_public_pages_render(string $uri): void
    {
        $this->get($uri)->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function publicPages(): array
    {
        return [
            'home' => ['/'],
            'pricing' => ['/pricing'],
            'login' => ['/login'],
            'register' => ['/register'],
            'forgot password' => ['/forgot-password'],
        ];
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/settings/workspace')->assertRedirect('/login');
    }

    public function test_signed_in_users_skip_the_landing_page(): void
    {
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get('/')->assertRedirect(route('dashboard'));
    }

    #[DataProvider('workspacePages')]
    public function test_workspace_pages_render_for_the_owner(string $uri): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['invoicing', 'appointments', 'crm'], $owner);

        $this->actingAs($owner)->get($uri)->assertOk()->assertSee($workspace->name);
    }

    /** @return array<string, array{string}> */
    public static function workspacePages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'profile' => ['/profile'],
            'workspace settings' => ['/settings/workspace'],
            'members' => ['/settings/members'],
            'branches' => ['/settings/branches'],
            'modules' => ['/settings/modules'],
            'modules filtered' => ['/settings/modules?q=invoice&filter=available'],
            'billing' => ['/settings/billing'],
        ];
    }

    public function test_plain_members_cannot_open_settings(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $member = $this->memberOf($workspace, 'member');

        $this->actingAs($member)->get('/dashboard')->assertOk();
        $this->actingAs($member)->get('/settings/workspace')->assertForbidden();
        $this->actingAs($member)->get('/settings/billing')->assertForbidden();
    }

    public function test_admins_can_open_settings(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $admin = $this->memberOf($workspace, 'admin');

        $this->actingAs($admin)->get('/settings/members')->assertOk();
    }
}
