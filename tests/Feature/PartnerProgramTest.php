<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Partners;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class PartnerProgramTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function partner(bool $whiteLabel = false): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->putSetting('partner', ['enabled' => true, 'code' => app(Partners::class)->newCode(), 'white_label' => $whiteLabel]);

        return [$owner, $workspace->fresh()];
    }

    protected function clientOf(Workspace $partner, string $status, float $amount, string $cycle = 'monthly'): Workspace
    {
        [, $client] = $this->ownerWithWorkspace();
        $client->forceFill(['reseller_id' => $partner->id])->save();
        $client->subscription->update(['status' => $status, 'amount' => $amount, 'billing_cycle' => $cycle]);

        return $client->fresh();
    }

    public function test_owner_joins_the_program_and_gets_a_referral_link(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actingAs($owner)->get(route('settings.partners.index'))->assertOk()->assertSee('Join the partner program');
        $this->actingAs($owner)->post(route('settings.partners.enable'))->assertSessionHas('flash.type', 'success');
        $workspace->refresh();

        $this->assertTrue($workspace->isPartner());
        $code = $workspace->setting('partner.code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);

        $this->actingAs($owner)->get(route('settings.partners.index'))->assertOk()
            ->assertSee(route('register', ['partner' => $code]))
            ->assertSee('No clients yet');

        // Joining again keeps the same code so shared links keep working.
        $this->actingAs($owner)->post(route('settings.partners.enable'));
        $this->assertSame($code, $workspace->refresh()->setting('partner.code'));
    }

    public function test_a_referral_link_attributes_the_new_workspace_to_the_partner(): void
    {
        [, $partner] = $this->partner();
        $code = $partner->setting('partner.code');

        $this->get('/register?partner='.strtolower($code))->assertOk()->assertSessionHas('partner_code', $code);

        $user = User::factory()->create();
        $this->actingAs($user)->post('/onboarding/1', [
            'name' => 'Referred Clinic', 'type' => 'company', 'country_code' => 'ZW', 'currency_code' => 'USD', 'timezone' => 'Africa/Harare',
        ])->assertRedirect(route('onboarding.step', 2));

        $this->assertSame($partner->id, $user->fresh()->currentWorkspace->reseller_id);
        $this->assertTrue($partner->clients()->where('name', 'Referred Clinic')->exists());
    }

    public function test_unknown_or_retired_codes_are_ignored(): void
    {
        [$owner, $partner] = $this->partner();
        $code = $partner->setting('partner.code');

        $this->get('/register?partner=NOTACODE')->assertSessionMissing('partner_code');
        $this->get('/register?partner=<script>')->assertSessionMissing('partner_code');

        $this->actingAs($owner)->delete(route('settings.partners.disable'));
        auth()->logout();
        $this->assertFalse($partner->refresh()->isPartner());

        $this->flushSession();
        $this->get('/register?partner='.$code)->assertSessionMissing('partner_code');

        [$user] = $this->ownerWithWorkspace();
        $this->actingAs($user)->withSession(['partner_code' => $code])->post(route('workspaces.store'), ['name' => 'Second Shop']);
        $this->assertNull($user->fresh()->currentWorkspace->reseller_id);
    }

    public function test_additional_workspaces_from_a_referral_are_attributed_too(): void
    {
        [, $partner] = $this->partner();
        [$user] = $this->ownerWithWorkspace();

        $this->actingAs($user)->withSession(['partner_code' => $partner->setting('partner.code')])
            ->post(route('workspaces.store'), ['name' => 'Branch Two'])->assertRedirect(route('onboarding.step', 1));

        $this->assertSame($partner->id, $user->fresh()->currentWorkspace->reseller_id);
    }

    public function test_partner_sets_up_a_client_workspace(): void
    {
        [$owner, $partner] = $this->partner();

        $this->actingAs($owner)->post(route('settings.partners.clients.store'), ['name' => 'Chipo Bakery'])
            ->assertRedirect(route('onboarding.step', 1));

        $client = Workspace::where('name', 'Chipo Bakery')->firstOrFail();
        $this->assertSame($partner->id, $client->reseller_id);
        $this->assertSame($owner->id, $client->owner_id);
        $this->assertSame($partner->currency_code, $client->currency_code);
        $this->assertTrue($owner->fresh()->belongsToWorkspace($client));
        $this->assertSame($client->id, $owner->fresh()->current_workspace_id);
        $this->assertSame(1, Branch::forWorkspace($client)->count());
    }

    public function test_commission_is_a_share_of_what_paying_clients_pay_each_month(): void
    {
        config(['zonseo.partners.commission_percent' => 20]);
        [$owner, $partner] = $this->partner();
        $this->clientOf($partner, 'active', 50);
        $this->clientOf($partner, 'active', 600, 'yearly');
        $this->clientOf($partner, 'trialing', 50);
        $this->clientOf($partner, 'active', 0);

        $response = $this->actingAs($owner)->get(route('settings.partners.index'))->assertOk();
        $totals = $response->viewData('totals');

        $this->assertSame(4, $totals['clients']);
        $this->assertSame(2, $totals['paying']);
        $this->assertEqualsWithDelta(100.0, $totals['monthly'], 0.001);
        $this->assertEqualsWithDelta(20.0, $totals['commission'], 0.001);
        $this->assertEqualsWithDelta(10.0, $response->viewData('rows')->firstWhere('status', 'paying')['commission'], 0.001);
    }

    public function test_client_workspaces_cannot_become_partners(): void
    {
        [, $partner] = $this->partner();
        $client = $this->clientOf($partner, 'active', 50);
        $owner = $client->owner;

        $this->actingAs($owner)->get(route('settings.partners.index'))->assertOk()->assertDontSee('Join the partner program');
        $this->actingAs($owner)->post(route('settings.partners.enable'))->assertForbidden();
        $this->assertFalse($client->refresh()->isPartner());
    }

    public function test_white_label_partners_brand_their_clients_workspaces(): void
    {
        [$owner, $partner] = $this->partner();
        $partner->putSetting('branding', ['name' => 'Acme Cloud', 'color' => '#7A1FA2']);
        $client = $this->clientOf($partner, 'active', 50);

        // Not white-label yet: the client sees the platform's own branding.
        $this->actingAs($client->owner)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Acme Cloud')->assertSee('One platform, every profession.');

        $this->actingAs($owner)->put(route('settings.partners.update'), ['white_label' => 1]);
        $this->assertTrue($partner->refresh()->isWhiteLabelPartner());

        $this->actingAs($client->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Dashboard · Acme Cloud', false)
            ->assertSee('--bs-primary:#7A1FA2', false)
            ->assertDontSee('One platform, every profession.');

        // A client's own branding wins over the partner's.
        $client->putSetting('branding.name', 'Chipo Bakery Pro');
        $this->actingAs($client->owner)->get(route('dashboard'))->assertSee('Dashboard · Chipo Bakery Pro', false)->assertDontSee('One platform');
    }

    public function test_a_white_label_partners_referral_link_shows_their_brand_on_sign_up(): void
    {
        [, $partner] = $this->partner(whiteLabel: true);
        $partner->putSetting('branding.name', 'Acme Cloud');

        $this->get('/register?partner='.$partner->setting('partner.code'))->assertOk()->assertSee('Acme Cloud');
        $this->get('/login')->assertOk()->assertSee('Acme Cloud');
    }

    public function test_members_cannot_manage_the_partner_program(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $member = $this->memberOf($workspace);

        $this->actingAs($member)->get(route('settings.partners.index'))->assertForbidden();
        $this->actingAs($member)->post(route('settings.partners.enable'))->assertForbidden();
        $this->assertFalse($workspace->refresh()->isPartner());
    }
}
