<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Workspace;
use App\Support\ModuleBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class ModuleMarketplaceTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_every_selectable_app_has_a_price_and_core_apps_are_free(): void
    {
        $this->assertSame(0, Module::where('is_core', false)->where('price_monthly', '<=', 0)->count());
        $this->assertSame(0, Module::where('is_core', true)->where('price_monthly', '>', 0)->count());

        $clinic = Module::where('key', 'clinic')->firstOrFail();
        $this->assertEquals($clinic->price_monthly * 10, $clinic->price_yearly);
    }

    public function test_reseeding_keeps_an_edited_price(): void
    {
        Module::where('key', 'crm')->update(['price_monthly' => 11, 'price_yearly' => 99]);

        $this->seedCatalogue();

        $this->assertEquals(11, Module::where('key', 'crm')->value('price_monthly'));
    }

    public function test_free_plan_apps_outside_the_plan_need_confirmation_and_are_then_billed_as_add_ons(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('free');
        $clinic = Module::where('key', 'clinic')->firstOrFail();

        $this->actingAs($owner)->post('/settings/modules/clinic/enable')
            ->assertRedirect()->assertSessionHas('addon_quote');
        $this->assertFalse($workspace->fresh()->hasModule('clinic'));

        $this->actingAs($owner)->post('/settings/modules/clinic/enable', ['confirm_addon' => 'all'])->assertRedirect();

        $workspace = $workspace->fresh();
        $this->assertTrue($workspace->hasModule('clinic'));
        $billing = app(ModuleBilling::class);
        $this->assertContains('clinic', $billing->addons($workspace)->pluck('key')->all());
        $this->assertNotContains('invoicing', $billing->addons($workspace)->pluck('key')->all(), 'Invoicing is in the Free plan');

        $subscription = $workspace->subscription;
        $this->assertEquals((float) $clinic->price_monthly, (float) $subscription->amount);
        $this->assertArrayHasKey('clinic', $subscription->meta['addons']);
    }

    public function test_a_card_confirmation_does_not_cover_extra_paid_dependencies(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('free');

        // POS pulls in Inventory, which the Free plan doesn't cover either.
        $this->actingAs($owner)->post('/settings/modules/pos/enable', ['confirm_addon' => 1])
            ->assertSessionHas('addon_quote', fn ($quote) => count($quote['apps']) === 2);
        $this->assertFalse($workspace->fresh()->hasModule('pos'));

        $this->actingAs($owner)->post('/settings/modules/pos/enable', ['confirm_addon' => 'all']);
        $this->assertSame(['inventory', 'pos'], app(ModuleBilling::class)->addons($workspace->fresh())->pluck('key')->sort()->values()->all());
    }

    public function test_solo_plan_covers_six_apps_then_charges_add_ons(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('solo');
        $workspace->enableModules(['invoicing', 'quotes', 'tasks', 'appointments', 'helpdesk', 'crm'], $owner);
        $billing = app(ModuleBilling::class);
        $billing->reconcile($workspace);

        $this->assertSame(['used' => 6, 'allowance' => 6], $billing->usage($workspace));
        $this->assertCount(0, $billing->addons($workspace));

        $this->actingAs($owner)->post('/settings/modules/expenses/enable')->assertSessionHas('addon_quote');
        $this->actingAs($owner)->post('/settings/modules/expenses/enable', ['confirm_addon' => 1]);

        $workspace = $workspace->fresh();
        $this->assertSame(['expenses'], $billing->addons($workspace)->pluck('key')->all());
        $expenses = Module::findByKey('expenses');
        $this->assertEquals(9 + (float) $expenses->price_monthly, (float) $workspace->subscription->amount);
    }

    public function test_turning_an_included_app_off_promotes_an_add_on_into_the_freed_slot(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('solo');
        $billing = app(ModuleBilling::class);
        $workspace->enableModules(['invoicing', 'quotes', 'tasks', 'appointments', 'helpdesk', 'crm'], $owner);
        $billing->reconcile($workspace);
        $this->actingAs($owner)->post('/settings/modules/expenses/enable', ['confirm_addon' => 1]);

        $this->actingAs($owner)->delete('/settings/modules/crm')->assertRedirect();

        $workspace = $workspace->fresh();
        $this->assertCount(0, $billing->addons($workspace));
        $this->assertEquals(9, (float) $workspace->subscription->amount);
    }

    public function test_add_on_price_is_locked_when_the_catalogue_price_changes(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('free');
        $this->actingAs($owner)->post('/settings/modules/crm/enable', ['confirm_addon' => 1]);
        $original = (float) Module::findByKey('crm')->price_monthly;

        Module::where('key', 'crm')->update(['price_monthly' => 50, 'price_yearly' => 500]);
        $this->resetModuleMemo(); // a query-builder update fires no model events
        $billing = app(ModuleBilling::class);
        $billing->reconcile($workspace->fresh());

        $this->assertEquals($original, $billing->addonTotal($workspace->fresh(), 'monthly'));
    }

    public function test_downgrading_asks_before_charging_for_apps_the_new_plan_does_not_cover(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('business');
        $workspace->enableModules(['invoicing', 'clinic'], $owner);

        $this->actingAs($owner)->post('/settings/billing/subscribe/free', ['billing_cycle' => 'monthly'])
            ->assertSessionHas('flash', fn ($flash) => $flash['type'] === 'warning' && str_contains($flash['message'], 'Clinic'));
        $this->assertSame('business', $workspace->fresh()->plan()->key);

        $this->actingAs($owner)->post('/settings/billing/subscribe/free', ['billing_cycle' => 'monthly', 'keep_addons' => 1]);

        $workspace = $workspace->fresh();
        $this->assertSame('free', $workspace->plan()->key);
        $this->assertContains('clinic', app(ModuleBilling::class)->addons($workspace)->pluck('key')->all());
        $this->assertGreaterThan(0, (float) $workspace->subscription->amount);
    }

    public function test_upgrading_folds_add_ons_into_the_plan(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('free');
        $this->actingAs($owner)->post('/settings/modules/crm/enable', ['confirm_addon' => 1]);

        $this->actingAs($owner)->post('/settings/billing/subscribe/business', ['billing_cycle' => 'yearly'])->assertRedirect();

        $workspace = $workspace->fresh();
        $this->assertCount(0, app(ModuleBilling::class)->addons($workspace));
        $this->assertEquals(290, (float) $workspace->subscription->amount);
    }

    public function test_marketplace_and_billing_pages_show_prices_and_add_ons(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('free');
        $this->actingAs($owner)->post('/settings/modules/crm/enable', ['confirm_addon' => 1]);

        $this->actingAs($owner)->get('/settings/modules?q=clinic')->assertOk()->assertSee('add-on');
        $this->actingAs($owner)->get('/settings/billing')->assertOk()
            ->assertSee('Apps & add-ons', false)
            ->assertSee(Module::findByKey('crm')->name);
    }

    public function test_onboarding_plan_choice_bills_apps_the_plan_does_not_cover(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('none');
        $workspace->forceFill(['onboarding_step' => 4, 'onboarded_at' => null])->save();
        $workspace->enableModules(['invoicing', 'clinic'], $owner);

        $this->actingAs($owner)->get('/onboarding/4')->assertOk()->assertSee('paid add-ons');
        $this->actingAs($owner)->post('/onboarding/4', ['plan' => 'free', 'billing_cycle' => 'monthly'])->assertRedirect(route('dashboard'));

        $this->assertContains('clinic', app(ModuleBilling::class)->addons(Workspace::find($workspace->id))->pluck('key')->all());
    }

    protected function resetModuleMemo(): void
    {
        (fn () => static::$byKeyMemo = null)->bindTo(null, Module::class)();
    }
}
