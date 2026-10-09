<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Profession;
use App\Support\BundleAdvisor;
use App\Support\ModuleBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class StarterBundlesTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_every_profession_has_a_bundle_of_real_apps_and_keywords(): void
    {
        foreach (Profession::all() as $profession) {
            $apps = $profession->recommendedModules()->reject(fn (Module $m) => $m->is_core);
            $this->assertGreaterThanOrEqual(2, $apps->count(), "{$profession->name} bundle is too small");
            $this->assertCount(count($profession->module_keys), Module::whereIn('key', $profession->module_keys)->get(), "{$profession->name} has a stale app key");
            $this->assertNotEmpty($profession->keywords, "{$profession->name} has no keywords");
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function descriptions(): array
    {
        return [
            'salon' => ['I do braids and nails from my hair salon', 'salon-spa-barber'],
            'plural' => ['We run two pharmacies in Harare', 'pharmacy'],
            'farm' => ['Poultry and maize farming', 'farmer-cooperative'],
            'shop' => ['A small tuckshop selling groceries', 'retail-shop-supermarket'],
            'it' => ['IT support for small offices', 'software-it-company'],
            'accents' => ['Café & bakery', 'restaurant-cafe-bar'],
        ];
    }

    #[DataProvider('descriptions')]
    public function test_a_description_matches_the_right_profession(string $description, string $expected): void
    {
        $this->assertSame($expected, app(BundleAdvisor::class)->match($description)->first()?->key);
    }

    public function test_unrelated_text_and_generic_words_do_not_match(): void
    {
        $advisor = app(BundleAdvisor::class);

        $this->assertCount(0, $advisor->match(''));
        $this->assertCount(0, $advisor->match('We are a company and it is a business'));
    }

    public function test_the_profession_step_suggests_matches_for_a_description(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('none');
        $workspace->forceFill(['onboarding_step' => 2, 'onboarded_at' => null])->save();

        $this->actingAs($owner)->get('/onboarding/2?describe='.urlencode('barber shop'))
            ->assertOk()
            ->assertSee('Best matches')
            ->assertSee('Salon / spa / barber');
    }

    public function test_changing_the_profession_swaps_the_preselected_bundle(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('none');
        $workspace->forceFill(['onboarding_step' => 2, 'onboarded_at' => null])->save();
        $lawyer = Profession::where('key', 'lawyer')->firstOrFail();
        $salon = Profession::where('key', 'salon-spa-barber')->firstOrFail();

        $this->actingAs($owner)->post('/onboarding/2', ['profession_id' => $lawyer->id]);
        $this->actingAs($owner)->post('/onboarding/3', ['modules' => ['legal', 'invoicing']]);
        $this->assertTrue($workspace->fresh()->hasModule('legal'));

        // Going back and re-saving the same answer keeps the picked apps…
        $this->actingAs($owner)->post('/onboarding/2', ['profession_id' => $lawyer->id]);
        $this->assertTrue($workspace->fresh()->hasModule('legal'));

        // …but a different answer starts again from the new bundle.
        $this->actingAs($owner)->post('/onboarding/2', ['profession_id' => $salon->id]);
        $this->assertFalse($workspace->fresh()->hasModule('legal'));
        $this->actingAs($owner)->get('/onboarding/3')->assertOk()->assertSee('Recommended bundle');
    }

    public function test_the_plan_step_recommends_the_cheapest_plan_for_the_chosen_apps(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('none');
        $workspace->forceFill(['onboarding_step' => 4, 'onboarded_at' => null])->save();
        $advisor = app(BundleAdvisor::class);
        $plans = Plan::active()->get();

        $workspace->enableModules(['invoicing', 'tasks'], $owner);
        $this->assertSame('free', $advisor->recommendPlan($workspace->fresh(), $plans)['plan']->key);

        $workspace->enableModules(['clinic', 'crm', 'helpdesk'], $owner);
        $this->assertSame('solo', $advisor->recommendPlan($workspace->fresh(), $plans)['plan']->key);

        $hospital = Profession::where('key', 'hospital')->firstOrFail();
        $workspace->enableModules($hospital->module_keys, $owner);
        $recommendation = $advisor->recommendPlan($workspace->fresh(), $plans);
        $this->assertSame('business', $recommendation['plan']->key);
        $this->assertEquals(29, $recommendation['totals'][$recommendation['plan']->id]);

        $this->actingAs($owner)->get('/onboarding/4')->assertOk()->assertSee('Best value for your apps');
    }

    public function test_a_starter_bundle_can_be_applied_from_settings(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('business');

        $this->actingAs($owner)->post('/settings/modules/bundle', ['profession' => 'salon-spa-barber'])->assertRedirect();

        $workspace = $workspace->fresh();
        foreach (['salon', 'appointments', 'pos', 'loyalty', 'sms-marketing'] as $key) {
            $this->assertTrue($workspace->hasModule($key), "{$key} should be on");
        }

        $this->actingAs($owner)->post('/settings/modules/bundle', ['profession' => 'salon-spa-barber'])
            ->assertSessionHas('flash', fn ($flash) => str_contains($flash['message'], 'already on'));
    }

    public function test_a_bundle_on_a_small_plan_asks_before_adding_paid_apps(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('free');

        $this->actingAs($owner)->post('/settings/modules/bundle', ['profession' => 'salon-spa-barber'])
            ->assertSessionHas('addon_quote', fn ($quote) => $quote['fields'] === ['profession' => 'salon-spa-barber']);
        $this->assertFalse($workspace->fresh()->hasModule('salon'));

        $this->actingAs($owner)->get('/settings/modules')->assertOk()->assertSee('Starter bundles');

        $this->actingAs($owner)->post('/settings/modules/bundle', ['profession' => 'salon-spa-barber', 'confirm_addon' => 'all']);

        $workspace = $workspace->fresh();
        $this->assertTrue($workspace->hasModule('salon'));
        $this->assertContains('salon', app(ModuleBilling::class)->addons($workspace)->pluck('key')->all());
        $this->assertGreaterThan(0, (float) $workspace->subscription->amount);
    }
}
