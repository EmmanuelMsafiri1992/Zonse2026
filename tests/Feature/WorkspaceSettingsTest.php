<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_owner_can_update_workspace_details(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->put('/settings/workspace', [
            'name' => 'Renamed Co', 'type' => 'company', 'country_code' => 'ZA', 'currency_code' => 'ZAR',
            'timezone' => 'Africa/Johannesburg', 'city' => 'Cape Town',
        ])->assertRedirect();

        $workspace->refresh();
        $this->assertSame('Renamed Co', $workspace->name);
        $this->assertSame('ZAR', $workspace->currency_code);
        $this->assertSame('Cape Town', $workspace->city);
    }

    public function test_modules_can_be_enabled_and_disabled(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('business');

        $this->actingAs($owner)->post('/settings/modules/crm/enable')->assertRedirect();
        $this->assertTrue($workspace->fresh()->hasModule('crm'));

        $this->actingAs($owner)->post('/settings/modules/pos/enable')->assertRedirect();
        $this->assertTrue($workspace->fresh()->hasModule('pos'));
        $this->assertTrue($workspace->fresh()->hasModule('inventory'), 'POS pulls in its inventory dependency');

        $this->actingAs($owner)->delete('/settings/modules/crm')->assertRedirect();
        $this->assertFalse($workspace->fresh()->hasModule('crm'));
    }

    public function test_free_plan_cannot_enable_modules_outside_the_plan(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('free');

        $this->actingAs($owner)->post('/settings/modules/invoicing/enable')->assertRedirect();
        $this->assertTrue($workspace->fresh()->hasModule('invoicing'));

        $this->actingAs($owner)->post('/settings/modules/clinic/enable')->assertRedirect();
        $this->assertFalse($workspace->fresh()->hasModule('clinic'));
    }

    public function test_branches_can_be_managed(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->post('/settings/branches', ['name' => 'Bulawayo', 'code' => 'BYO', 'city' => 'Bulawayo'])->assertRedirect();
        $branch = $workspace->branches()->where('code', 'BYO')->firstOrFail();

        $this->actingAs($owner)->put("/settings/branches/{$branch->id}", ['name' => 'Bulawayo CBD', 'code' => 'BYO', 'is_default' => 1, 'is_active' => 1])->assertRedirect();
        $this->assertSame('Bulawayo CBD', $branch->fresh()->name);
        $this->assertTrue($branch->fresh()->is_default);
        $this->assertSame(1, $workspace->branches()->where('is_default', true)->count(), 'only one default branch');

        $other = $workspace->branches()->where('id', '!=', $branch->id)->firstOrFail();
        $this->actingAs($owner)->delete("/settings/branches/{$other->id}")->assertRedirect();
        $this->assertSame(1, $workspace->branches()->count());
    }

    public function test_members_can_be_invited_and_the_invitation_accepted(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->post('/settings/members/invite', ['email' => 'colleague@example.com', 'role' => 'manager'])->assertRedirect();

        $invitation = Invitation::where('email', 'colleague@example.com')->firstOrFail();
        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class);

        $this->get("/invitations/{$invitation->token}")->assertOk()->assertSee($workspace->name);

        $colleague = User::factory()->create(['email' => 'colleague@example.com']);
        $this->actingAs($colleague)->post("/invitations/{$invitation->token}")->assertRedirect(route('dashboard'));

        $this->assertSame('manager', $colleague->fresh()->roleIn($workspace));
        $this->assertSame($workspace->id, $colleague->fresh()->current_workspace_id);
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->get("/invitations/{$invitation->token}")->assertOk()->assertSee('expired');
    }

    public function test_member_roles_can_be_changed_and_members_removed(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $member = $this->memberOf($workspace, 'member');

        $this->actingAs($owner)->patch("/settings/members/{$member->id}", ['role' => 'admin', 'job_title' => 'Ops lead'])->assertRedirect();
        $this->assertSame('admin', $member->fresh()->roleIn($workspace));

        $this->actingAs($owner)->delete("/settings/members/{$member->id}")->assertRedirect();
        $this->assertFalse($member->fresh()->belongsToWorkspace($workspace));
    }

    public function test_billing_plan_can_be_switched_and_cancelled(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('solo');

        $this->actingAs($owner)->post('/settings/billing/subscribe/enterprise', ['billing_cycle' => 'monthly'])->assertRedirect();
        $this->assertSame('enterprise', $workspace->fresh()->plan()?->key);

        $this->actingAs($owner)->post('/settings/billing/cancel')->assertRedirect();
        $this->assertContains($workspace->fresh()->subscriptions()->latest('id')->value('status'), ['cancelled', 'canceled']);
    }
}
