<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class AdminClientsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    protected function superAdmin(): User
    {
        [$admin] = $this->ownerWithWorkspace('free');
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin->fresh();
    }

    public function test_super_admin_sees_everyone_who_signed_up_with_their_plan(): void
    {
        $admin = $this->superAdmin();
        [$trialOwner, $trialWorkspace] = $this->ownerWithWorkspace();
        $trialOwner->forceFill(['name' => 'Tendai Moyo'])->save();
        [$payingOwner, $payingWorkspace] = $this->ownerWithWorkspace();
        $payingOwner->forceFill(['name' => 'Rudo Banda', 'created_at' => now()->subMonth()])->save();
        $payingWorkspace->subscription->forceFill(['status' => 'active', 'trial_ends_at' => null, 'current_period_end' => now()->addMonth()])->save();
        $justRegistered = User::factory()->create(['name' => 'Chipo Phiri']);
        $inWizard = User::factory()->create(['name' => 'Farai Dube']);
        Workspace::factory()->onboarding(2)->create(['owner_id' => $inWizard->id, 'name' => 'Dube Hardware']);

        $this->actingAs($admin)->get(route('admin.clients.index'))->assertOk()
            ->assertSee('Tendai Moyo')->assertSee($trialWorkspace->name)->assertSee('Trial, 14 days left')
            ->assertSee('Rudo Banda')->assertSee('Paid to')
            ->assertSee('Chipo Phiri')->assertSee('No workspace yet')
            ->assertSee('Dube Hardware')->assertSee('Setup not finished (step 2)')
            ->assertDontSee('data-client="'.$admin->id.'"', false);

        $this->actingAs($admin)->get(route('admin.clients.index', ['filter' => 'trial']))->assertOk()
            ->assertSee('Tendai Moyo')->assertDontSee('Rudo Banda')->assertDontSee('Chipo Phiri');
        $this->actingAs($admin)->get(route('admin.clients.index', ['filter' => 'paying']))->assertOk()
            ->assertSee('Rudo Banda')->assertDontSee('Tendai Moyo');
        $this->actingAs($admin)->get(route('admin.clients.index', ['filter' => 'new']))->assertOk()
            ->assertSee('Tendai Moyo')->assertDontSee('Rudo Banda');
        $this->actingAs($admin)->get(route('admin.clients.index', ['filter' => 'unfinished']))->assertOk()
            ->assertSee('Chipo Phiri')->assertSee('Farai Dube')->assertDontSee('Tendai Moyo');
        $this->actingAs($admin)->get(route('admin.clients.index', ['q' => 'Dube Hard']))->assertOk()
            ->assertSee('Farai Dube')->assertDontSee('Chipo Phiri');

        $this->actingAs($admin)->post(route('workspaces.switch', $trialWorkspace))->assertRedirect();
        $this->assertSame($trialWorkspace->id, $admin->fresh()->current_workspace_id);
    }

    public function test_only_super_admins_can_see_clients_and_the_menu_shows_it_to_them(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $this->actingAs($owner)->get(route('admin.clients.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee(route('admin.clients.index'));

        $this->actingAs($this->superAdmin())->get(route('dashboard'))->assertOk()->assertSee(route('admin.clients.index'));
    }
}
