<?php

namespace Tests\Feature;

use App\Models\Profession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class OnboardingWizardTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_a_user_without_a_workspace_is_sent_to_the_wizard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.start'));
        $this->actingAs($user)->get('/onboarding')->assertRedirect(route('onboarding.step', 1));
        $this->actingAs($user)->get('/onboarding/1')->assertOk();
        $this->actingAs($user)->get('/onboarding/3')->assertRedirect(route('onboarding.step', 1));
    }

    public function test_the_full_wizard_creates_an_onboarded_workspace_with_a_trial(): void
    {
        $user = User::factory()->create();
        $lawyer = Profession::where('key', 'lawyer')->firstOrFail();

        $this->actingAs($user)->post('/onboarding/1', [
            'name' => 'Moyo & Partners',
            'type' => 'company',
            'country_code' => 'ZW',
            'currency_code' => 'USD',
            'timezone' => 'Africa/Harare',
        ])->assertRedirect(route('onboarding.step', 2));

        $workspace = $user->fresh()->currentWorkspace;
        $this->assertNotNull($workspace);
        $this->assertSame('moyo-partners', $workspace->slug);
        $this->assertTrue($user->fresh()->isOwnerOf($workspace));
        $this->assertSame(1, $workspace->branches()->count());

        $this->actingAs($user)->post('/onboarding/2', ['profession_id' => $lawyer->id])
            ->assertRedirect(route('onboarding.step', 3));

        $this->actingAs($user)->get('/onboarding/3')
            ->assertOk()
            ->assertSee('Recommended')
            ->assertSee('Legal practice');

        $this->actingAs($user)->post('/onboarding/3', ['modules' => ['legal', 'invoicing']])
            ->assertRedirect(route('onboarding.step', 4));

        $this->actingAs($user)->get('/onboarding/4')->assertOk()->assertSee('Business');

        $this->actingAs($user)->post('/onboarding/4', ['plan' => 'business', 'billing_cycle' => 'yearly'])
            ->assertRedirect(route('dashboard'));

        $workspace->refresh();
        $this->assertTrue($workspace->isOnboarded());
        $this->assertTrue($workspace->hasModule('legal'));
        $this->assertTrue($workspace->hasModule('invoicing'));
        $this->assertTrue($workspace->hasModule('time-tracking'), 'dependencies are enabled too');
        $this->assertTrue($workspace->onTrial());

        $subscription = $workspace->subscriptions()->latest('id')->first();
        $this->assertSame('trialing', $subscription->status);
        $this->assertSame('yearly', $subscription->billing_cycle);
        $this->assertSame(14, $subscription->daysLeftInTrial());

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Moyo & Partners');
        $this->actingAs($user)->get('/onboarding/1')->assertRedirect(route('dashboard'));
    }

    public function test_the_free_plan_activates_without_a_trial(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/onboarding/1', [
            'name' => 'Solo Shop', 'type' => 'individual', 'country_code' => 'ZW', 'currency_code' => 'USD', 'timezone' => 'Africa/Harare',
        ]);
        $this->actingAs($user)->post('/onboarding/2', ['profession_id' => null]);
        $this->actingAs($user)->post('/onboarding/3', ['modules' => ['invoicing']]);
        $this->actingAs($user)->post('/onboarding/4', ['plan' => 'free', 'billing_cycle' => 'monthly'])
            ->assertRedirect(route('dashboard'));

        $workspace = $user->fresh()->currentWorkspace;
        $this->assertFalse($workspace->onTrial());
        $this->assertSame('active', $workspace->subscriptions()->latest('id')->value('status'));
    }

    public function test_an_unfinished_workspace_is_pulled_back_into_the_wizard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/onboarding/1', [
            'name' => 'Half Done', 'type' => 'company', 'country_code' => 'ZW', 'currency_code' => 'USD', 'timezone' => 'Africa/Harare',
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.step', 2));
        $this->actingAs($user)->get('/onboarding/4')->assertRedirect(route('onboarding.step', 2));
    }
}
