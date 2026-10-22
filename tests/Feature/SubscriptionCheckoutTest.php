<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\Plan;
use App\Models\Profession;
use App\Models\User;
use App\Models\Workspace;
use App\Payments\PaynowGateway;
use App\Payments\PlatformCheckout;
use App\Payments\StripeGateway;
use App\Payments\SubscriptionAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class SubscriptionCheckoutTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected const PAYNOW_KEY = 'platform-paynow-key';

    protected const STRIPE_SECRET = 'sk_test_platform';

    protected const STRIPE_WEBHOOK = 'whsec_platform';

    /** @var array{initiate: string, poll: string} */
    protected array $paynow = ['initiate' => '', 'poll' => ''];

    /** @var array<string, mixed> */
    protected array $stripeSession = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Http::preventStrayRequests();
        Http::fake([
            PaynowGateway::INITIATE_URL => fn () => Http::response($this->paynow['initiate']),
            'https://www.paynow.co.zw/Interface/CheckPayment/*' => fn () => Http::response($this->paynow['poll']),
            StripeGateway::API.'/checkout/sessions*' => fn () => Http::response($this->stripeSession),
        ]);

        config()->set('zonseo.checkout', [
            'paynow' => ['integration_id' => '', 'integration_key' => '', 'currency' => 'USD'],
            'stripe' => ['secret_key' => '', 'webhook_secret' => ''],
            'rates' => 'ZWG=26.5',
            'manual_instructions' => null,
            'grace_days' => 7,
            'enforce_from' => null,
        ]);
    }

    protected function withPaynow(string $currency = 'USD'): void
    {
        config()->set('zonseo.checkout.paynow', ['integration_id' => '12345', 'integration_key' => self::PAYNOW_KEY, 'currency' => $currency]);
    }

    protected function withStripe(): void
    {
        config()->set('zonseo.checkout.stripe', ['secret_key' => self::STRIPE_SECRET, 'webhook_secret' => self::STRIPE_WEBHOOK]);
    }

    protected function withTransfers(): void
    {
        config()->set('zonseo.checkout.manual_instructions', "EcoCash merchant 123456\nReference: your workspace name");
    }

    /** @param  array<string, string>  $fields */
    protected function paynowReply(array $fields): string
    {
        $fields['hash'] = PaynowGateway::hash($fields, self::PAYNOW_KEY);

        return http_build_query($fields);
    }

    protected function fakePaynow(string $pollStatus = 'Paid', string $amount = '29.00'): void
    {
        $this->paynow = [
            'initiate' => $this->paynowReply([
                'status' => 'Ok',
                'browserurl' => 'https://www.paynow.co.zw/Payment/ConfirmPayment/7001',
                'pollurl' => 'https://www.paynow.co.zw/Interface/CheckPayment/?guid=plat-1',
            ]),
            'poll' => $this->paynowReply([
                'reference' => 'ZB-TEST', 'paynowreference' => '556677', 'amount' => $amount,
                'status' => $pollStatus, 'pollurl' => 'https://www.paynow.co.zw/Interface/CheckPayment/?guid=plat-1',
            ]),
        ];
    }

    /** @param  array<string, mixed>  $session */
    protected function fakeStripe(array $session = []): void
    {
        $this->stripeSession = array_merge([
            'id' => 'cs_test_plat1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_plat1',
            'payment_status' => 'unpaid', 'status' => 'open', 'amount_total' => 29000, 'currency' => 'usd', 'payment_intent' => null,
        ], $session);
    }

    protected function stripeSignature(string $payload, string $secret = self::STRIPE_WEBHOOK): string
    {
        $time = time();

        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret);
    }

    protected function superAdmin(): User
    {
        [$admin] = $this->ownerWithWorkspace('free');
        $admin->forceFill(['is_super_admin' => true])->save();

        return $admin->fresh();
    }

    public function test_billing_page_shows_plan_prices_in_dollars_and_local_currency(): void
    {
        $this->withPaynow();
        $this->withTransfers();
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('settings.billing.index'))->assertOk()
            ->assertSee('Pay for your plan')
            ->assertSee('Pay with Paynow')
            ->assertSee('Pay by bank transfer or mobile money')
            ->assertSee('EcoCash merchant 123456')
            ->assertSee('$29.00', false)
            ->assertSee('ZiG768.50', false)
            ->assertSee('ZiG7,685.00', false)
            ->assertDontSee('being connected');
    }

    public function test_nothing_to_pay_with_is_offered_when_no_gateway_is_configured(): void
    {
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('settings.billing.index'))->assertOk()->assertDontSee('Pay for your plan');
        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'business', 'billing_cycle' => 'monthly', 'gateway' => 'paynow'])
            ->assertSessionHasErrors('gateway');
        $this->assertSame(0, Checkout::count());
    }

    public function test_paynow_payment_activates_the_plan_once_the_poll_confirms_it(): void
    {
        $this->withPaynow();
        $this->fakePaynow();
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->assertSame('trialing', $workspace->subscription->status);

        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'business', 'billing_cycle' => 'monthly', 'gateway' => 'paynow'])
            ->assertRedirect('https://www.paynow.co.zw/Payment/ConfirmPayment/7001');

        $checkout = Checkout::sole();
        $this->assertSame('pending', $checkout->status);
        $this->assertSame(29.0, $checkout->amount);
        $this->assertSame('USD', $checkout->currency_code);
        $this->assertSame($workspace->id, $checkout->workspace_id);
        $this->assertSame('https://www.paynow.co.zw/Interface/CheckPayment/?guid=plat-1', $checkout->poll_url);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === PaynowGateway::INITIATE_URL
            && $request['id'] === '12345' && $request['amount'] === '29.00' && $request['authemail'] === $owner->email
            && $request['hash'] === PaynowGateway::hash(array_diff_key($request->data(), ['hash' => 1]), self::PAYNOW_KEY));

        $this->get(route('checkout.return', $checkout->uuid))
            ->assertRedirect(route('settings.billing.index'))->assertSessionHas('flash.type', 'success');

        $checkout->refresh();
        $this->assertSame('paid', $checkout->status);
        $this->assertSame('556677', $checkout->gateway_reference);
        $this->assertNotNull($checkout->fulfilled_at);

        $workspace = $workspace->fresh();
        $subscription = $workspace->subscription;
        $this->assertSame('active', $subscription->status);
        $this->assertSame('business', $subscription->plan->key);
        $this->assertSame('paynow', $subscription->gateway);
        $this->assertNull($subscription->trial_ends_at);
        $this->assertNull($workspace->trial_ends_at);
        $this->assertTrue($subscription->current_period_end->isSameDay(now()->addMonth()));
        $this->assertSame(1, $workspace->subscriptions()->count());
        $this->assertSame('active', SubscriptionAccess::for($workspace)->state);

        // Coming back again, or Paynow's own notification arriving later, changes nothing.
        $this->get(route('checkout.return', $checkout->uuid))->assertSessionHas('flash.type', 'success');
        $this->post(route('checkout.webhook', ['gateway' => 'paynow', 'attempt' => $checkout->uuid]), ['status' => 'Paid'])->assertOk();
        $this->assertSame(1, $workspace->subscriptions()->count());
        $this->assertTrue($workspace->fresh()->subscription->current_period_end->isSameDay(now()->addMonth()));

        $this->actingAs($owner)->get(route('settings.billing.index'))->assertOk()->assertSee('Business plan, one month')->assertSee('556677');
    }

    public function test_paynow_charges_in_local_currency_at_the_platform_rate_and_its_notification_settles_the_payment(): void
    {
        $this->withPaynow('ZWG');
        $this->fakePaynow('Paid', '768.50');
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'business', 'billing_cycle' => 'monthly', 'gateway' => 'paynow'])->assertRedirect();
        $checkout = Checkout::sole();
        $this->assertSame('ZWG', $checkout->currency_code);
        $this->assertSame(768.5, $checkout->amount);
        $this->assertSame(29.0, $checkout->base_amount);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === PaynowGateway::INITIATE_URL && $request['amount'] === '768.50');

        // A forged body is not trusted: the status always comes from Paynow's poll URL.
        $this->post(route('checkout.webhook', ['gateway' => 'paynow', 'attempt' => $checkout->uuid]), ['status' => 'Paid', 'hash' => 'forged'])->assertOk();
        $this->assertSame('paid', $checkout->fresh()->status);
        $this->assertSame('active', $workspace->fresh()->subscription->status);
        Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://www.paynow.co.zw/Interface/CheckPayment/'));
    }

    public function test_short_and_cancelled_paynow_payments_leave_the_plan_as_it_was(): void
    {
        $this->withPaynow();
        $this->fakePaynow('Paid', '5.00');
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'business', 'billing_cycle' => 'monthly', 'gateway' => 'paynow']);
        $this->get(route('checkout.return', Checkout::sole()->uuid))->assertSessionHas('flash.type', 'danger');
        $this->assertSame('failed', Checkout::sole()->status);

        $this->fakePaynow('Cancelled');
        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'business', 'billing_cycle' => 'monthly', 'gateway' => 'paynow']);
        $second = Checkout::latest('id')->first();
        $this->get(route('checkout.return', $second->uuid))->assertSessionHas('flash.message', 'Payment cancelled. Nothing was charged.');
        $this->assertSame('cancelled', $second->fresh()->status);

        $this->assertSame('trialing', $workspace->fresh()->subscription->status);
        $this->assertSame(1, $workspace->subscriptions()->count());
    }

    public function test_stripe_yearly_payment_adds_a_year_and_the_signed_webhook_settles_it(): void
    {
        $this->withStripe();
        $this->fakeStripe();
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'business', 'billing_cycle' => 'yearly', 'gateway' => 'stripe'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_plat1');
        $checkout = Checkout::sole();
        $this->assertSame(290.0, $checkout->amount);
        $this->assertSame('cs_test_plat1', $checkout->gateway_reference);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === StripeGateway::API.'/checkout/sessions'
            && $request->hasHeader('Authorization', 'Bearer '.self::STRIPE_SECRET)
            && $request['line_items'][0]['price_data']['unit_amount'] == 29000
            && $request['client_reference_id'] === $checkout->uuid
            && $request['success_url'] === route('checkout.return', $checkout->uuid));

        // Still unpaid on Stripe's side: nothing changes yet.
        $this->get(route('checkout.return', $checkout->uuid))->assertSessionHas('flash.type', 'info');
        $this->assertSame('pending', $checkout->fresh()->status);

        $this->fakeStripe(['payment_status' => 'paid', 'status' => 'complete', 'payment_intent' => 'pi_plat']);
        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_plat1', 'client_reference_id' => $checkout->uuid]]]);
        $url = route('checkout.webhook', ['gateway' => 'stripe']);
        $headers = fn (string $secret) => ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, $secret)];

        $this->call('POST', $url, [], [], [], $headers('whsec_wrong'), $payload)->assertStatus(400);
        $this->assertSame('pending', $checkout->fresh()->status);

        $this->call('POST', $url, [], [], [], $headers(self::STRIPE_WEBHOOK), $payload)->assertOk();
        $checkout->refresh();
        $this->assertSame('paid', $checkout->status);
        $this->assertSame('pi_plat', $checkout->gateway_reference);

        $subscription = $workspace->fresh()->subscription;
        $this->assertSame('active', $subscription->status);
        $this->assertSame('yearly', $subscription->billing_cycle);
        $this->assertSame('stripe', $subscription->gateway);
        $this->assertTrue($subscription->current_period_end->isSameDay(now()->addYear()));

        $this->call('POST', $url, [], [], [], $headers(self::STRIPE_WEBHOOK), $payload)->assertOk();
        $this->assertSame(1, $workspace->subscriptions()->count());
    }

    public function test_leaving_the_stripe_page_cancels_the_checkout(): void
    {
        $this->withStripe();
        $this->fakeStripe();
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'solo', 'billing_cycle' => 'monthly', 'gateway' => 'stripe']);
        $checkout = Checkout::sole();
        $this->get(route('checkout.return', ['checkout' => $checkout->uuid, 'cancelled' => 1]))
            ->assertSessionHas('flash.message', 'Payment cancelled. Nothing was charged.');
        $this->assertSame('cancelled', $checkout->fresh()->status);
        $this->assertSame('business', $workspace->fresh()->subscription->plan->key);
        $this->assertSame('trialing', $workspace->fresh()->subscription->status);
    }

    public function test_paying_for_a_different_plan_starts_it_today_and_paying_early_adds_time_to_the_period_end(): void
    {
        $this->withPaynow();
        $this->fakePaynow('Paid', '9.00');
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'solo', 'billing_cycle' => 'monthly', 'gateway' => 'paynow']);
        $this->get(route('checkout.return', Checkout::sole()->uuid));

        $workspace = $workspace->fresh();
        $this->assertSame('solo', $workspace->subscription->plan->key);
        $this->assertSame(2, $workspace->subscriptions()->count());
        $this->assertSame('cancelled', $workspace->subscriptions()->where('status', 'cancelled')->sole()->status);
        $firstEnd = $workspace->subscription->current_period_end;

        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'solo', 'billing_cycle' => 'monthly', 'gateway' => 'paynow']);
        $this->get(route('checkout.return', Checkout::latest('id')->first()->uuid));

        $workspace = $workspace->fresh();
        $this->assertSame(2, $workspace->subscriptions()->count());
        $this->assertTrue($workspace->subscription->current_period_end->isSameDay($firstEnd->copy()->addMonth()));
    }

    public function test_bank_transfers_wait_for_a_platform_admin_to_confirm_them(): void
    {
        $this->withTransfers();
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->post(route('settings.billing.checkout'), [
            'plan' => 'business', 'billing_cycle' => 'monthly', 'gateway' => 'manual', 'note' => 'EcoCash from 0771234567',
        ])->assertRedirect(route('settings.billing.index'))->assertSessionHas('flash.type', 'info');

        $checkout = Checkout::sole();
        $this->assertSame('manual', $checkout->gateway);
        $this->assertSame('pending', $checkout->status);
        $this->assertSame('EcoCash from 0771234567', $checkout->note);
        $this->assertSame('trialing', $workspace->fresh()->subscription->status);

        // Only the people who run Zonseob see platform payments.
        $this->actingAs($owner)->get(route('admin.payments.index'))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.payments.confirm', $checkout))->assertForbidden();
        $this->actingAs($owner)->get(route('settings.billing.index'))->assertOk()->assertDontSee('Platform payments');

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get(route('settings.billing.index'))->assertOk()->assertSee('Platform payments');
        $this->actingAs($admin)->get(route('admin.payments.index'))->assertOk()
            ->assertSee('1 bank transfer waiting')->assertSee($workspace->name)->assertSee('EcoCash from 0771234567')->assertSee('Business plan, one month');

        $this->actingAs($admin)->post(route('admin.payments.confirm', $checkout), ['reference' => 'BANK-778'])
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $checkout->refresh();
        $this->assertSame('paid', $checkout->status);
        $this->assertSame('BANK-778', $checkout->gateway_reference);
        $this->assertTrue($checkout->confirmedBy->is($admin));

        $subscription = $workspace->fresh()->subscription;
        $this->assertSame('active', $subscription->status);
        $this->assertSame('manual', $subscription->gateway);
        $this->assertSame($checkout->uuid, $subscription->gateway_ref);

        // Confirming twice adds nothing; a rejected transfer is simply failed.
        $this->actingAs($admin)->post(route('admin.payments.confirm', $checkout))->assertSessionHas('flash.type', 'warning');
        $this->assertSame(1, $workspace->subscriptions()->count());

        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'business', 'billing_cycle' => 'yearly', 'gateway' => 'manual']);
        $second = Checkout::latest('id')->first();
        $this->actingAs($admin)->post(route('admin.payments.reject', $second), ['reason' => 'Nothing arrived.'])->assertRedirect();
        $this->assertSame('failed', $second->fresh()->status);
        $this->assertSame('Nothing arrived.', $second->fresh()->failure_reason);
        $this->assertSame('monthly', $workspace->fresh()->subscription->billing_cycle);
    }

    public function test_platform_admin_sets_rates_and_transfer_instructions_that_change_what_people_see(): void
    {
        $this->withPaynow();
        $admin = $this->superAdmin();
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($admin)->put(route('admin.payments.settings'), [
            'rates' => [['currency' => 'ZWG', 'rate' => '30'], ['currency' => 'ZAR', 'rate' => '18.25'], ['currency' => '', 'rate' => '']],
            'manual_instructions' => 'Pay to Steward Bank 1234567, reference your workspace name.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['ZAR' => 18.25, 'ZWG' => 30.0], app(PlatformCheckout::class)->rates());
        $this->actingAs($owner)->get(route('settings.billing.index'))->assertOk()
            ->assertSee('ZiG870.00', false)->assertSee('R529.25', false)->assertSee('Steward Bank 1234567');

        $this->actingAs($admin)->put(route('admin.payments.settings'), ['rates' => [['currency' => 'XXX', 'rate' => '1']]])->assertSessionHasErrors('rates.0.currency');
        $this->actingAs($admin)->put(route('admin.payments.settings'), ['rates' => [], 'manual_instructions' => ''])->assertSessionHasNoErrors();
        $this->assertSame([], app(PlatformCheckout::class)->rates());
        $this->assertFalse(app(PlatformCheckout::class)->manualEnabled());
    }

    public function test_a_lapsed_plan_gets_a_grace_period_and_then_closes_the_apps(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['invoicing'], $owner);
        $member = $this->memberOf($workspace);

        // Trial ending in three days: a reminder, nothing blocked.
        $workspace->subscription->forceFill(['trial_ends_at' => now()->addDays(3)])->save();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('data-subscription-notice="trial"', false)->assertSee('Your free trial ends in 3 days');
        $this->assertSame('trial', SubscriptionAccess::for($workspace->fresh())->state);

        // Trial over two days ago: still inside the seven-day grace period.
        $workspace->subscription->forceFill(['trial_ends_at' => now()->subDays(2)])->save();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('data-subscription-notice="grace"', false)->assertSee('Your plan has lapsed');
        $this->actingAs($member)->get(route('invoices.index'))->assertOk()->assertSee('unless the workspace owner pays');
        $this->assertSame('grace', SubscriptionAccess::for($workspace->fresh())->state);

        // Grace over: the owner is sent to billing, everyone else sees the paused page, apps are closed.
        $workspace->subscription->forceFill(['trial_ends_at' => now()->subDays(10)])->save();
        $this->assertSame('blocked', SubscriptionAccess::for($workspace->fresh())->state);
        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('settings.billing.index'));
        $this->actingAs($owner)->get(route('invoices.index'))->assertRedirect(route('settings.billing.index'));
        $this->actingAs($owner)->get(route('settings.billing.index'))->assertOk()->assertSee('Your apps are paused');
        $this->actingAs($owner)->get(route('profile.edit'))->assertOk();
        $this->actingAs($owner)->getJson(route('dashboard'))->assertStatus(402);
        $this->actingAs($member)->get(route('dashboard'))->assertStatus(402)->assertSee($workspace->name.' is paused');
        $this->actingAs($member)->get(route('invoices.index'))->assertStatus(402);
        $this->actingAs($member)->get(route('settings.billing.index'))->assertForbidden();

        // A paid period that ran out behaves the same way; a free plan is never blocked.
        $workspace->subscription->forceFill(['status' => 'active', 'trial_ends_at' => null, 'current_period_end' => now()->subDays(30)])->save();
        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('settings.billing.index'));

        [$freeOwner, $freeWorkspace] = $this->ownerWithWorkspace('free');
        $freeWorkspace->subscription->forceFill(['current_period_end' => now()->subYear()])->save();
        $this->actingAs($freeOwner)->get(route('dashboard'))->assertOk()->assertDontSee('data-subscription-notice');
    }

    public function test_enforcement_can_be_postponed_until_a_date_and_paying_reopens_the_apps(): void
    {
        $this->withPaynow();
        $this->fakePaynow();
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->subscription->forceFill(['trial_ends_at' => now()->subDays(30)])->save();

        config()->set('zonseo.checkout.enforce_from', now()->addDays(20)->toDateString());
        $access = SubscriptionAccess::for($workspace->fresh());
        $this->assertSame('grace', $access->state);
        $this->assertTrue($access->until->isSameDay(now()->addDays(20)));
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        config()->set('zonseo.checkout.enforce_from', now()->subDay()->toDateString());
        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('settings.billing.index'));

        $this->actingAs($owner)->post(route('settings.billing.checkout'), ['plan' => 'business', 'billing_cycle' => 'monthly', 'gateway' => 'paynow'])->assertRedirect();
        $this->get(route('checkout.return', Checkout::sole()->uuid));
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('data-subscription-notice');
    }

    public function test_guests_pick_a_plan_on_the_landing_page_and_see_it_recommended_after_signing_up(): void
    {
        $this->get(route('pricing'))->assertOk()
            ->assertSee(route('register', ['plan' => 'solo']), false)
            ->assertDontSee(route('settings.billing.subscribe', Plan::where('key', 'solo')->first()), false);

        $this->get(route('register', ['plan' => 'solo']))->assertOk()->assertSee('name="plan" value="solo"', false);

        $this->post(route('register'), [
            'name' => 'Rudo Chikwanda', 'email' => 'rudo@example.com', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123', 'plan' => 'solo',
        ])->assertRedirect()->assertSessionHas('intended_plan', 'solo');
        $user = User::where('email', 'rudo@example.com')->sole();

        $this->actingAs($user)->post('/onboarding/1', ['name' => 'Rudo Designs', 'type' => 'freelancer', 'country_code' => 'ZW', 'currency_code' => 'USD', 'timezone' => 'Africa/Harare']);
        $this->actingAs($user)->post('/onboarding/2', ['profession_id' => Profession::where('key', 'lawyer')->firstOrFail()->id]);
        $this->actingAs($user)->post('/onboarding/3', ['modules' => ['legal']]);
        $this->actingAs($user)->get('/onboarding/4')->assertOk()->assertViewHas('recommendedPlan', fn (?Plan $plan) => $plan?->key === 'solo');

        // A fresh visitor who tampers with the plan field gets nothing remembered.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post(route('register'), [
            'name' => 'Other Person', 'email' => 'other@example.com', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123', 'plan' => 'not-a-plan',
        ])->assertRedirect()->assertSessionMissing('intended_plan');
    }

    public function test_workspace_access_helper_mirrors_the_subscription(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->assertTrue($workspace->access()->onTrial());
        $this->assertSame(14, $workspace->access()->daysLeft());

        $empty = Workspace::factory()->create();
        $this->assertSame('active', $empty->access()->state);
    }

    public function test_a_super_admins_own_workspace_never_trials_or_lapses(): void
    {
        [$admin, $workspace] = $this->ownerWithWorkspace();
        $admin->forceFill(['is_super_admin' => true])->save();
        $workspace->enableModules(['invoicing'], $admin);

        $workspace->subscription->forceFill(['trial_ends_at' => now()->addDays(3)])->save();
        $this->assertSame('active', SubscriptionAccess::for($workspace->fresh())->state);
        $this->assertFalse($workspace->fresh()->onTrial());
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee('data-subscription-notice', false)
            ->assertDontSee('of trial left')
            ->assertSee('Platform workspace, no billing');

        $workspace->subscription->forceFill(['trial_ends_at' => now()->subDays(30)])->save();
        $this->actingAs($admin)->get(route('invoices.index'))->assertOk();
    }
}
