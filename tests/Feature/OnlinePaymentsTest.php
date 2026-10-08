<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\OnlinePayment;
use Modules\Invoicing\Payments\GatewayException;
use Modules\Invoicing\Payments\PaymentGateways;
use Modules\Invoicing\Payments\PaynowGateway;
use Modules\Invoicing\Payments\StripeGateway;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class OnlinePaymentsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected const PAYNOW_KEY = 'paynow-test-integration-key';

    protected const STRIPE_SECRET = 'sk_test_abc123';

    protected const STRIPE_WEBHOOK = 'whsec_test123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Http::preventStrayRequests();
        $this->fakeProviders();
    }

    /** @return array{0: User, 1: Workspace, 2: Invoice} */
    protected function payableInvoice(float $amount = 50.00): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['invoicing'], $owner);
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Tendai Moyo', 'email' => 'tendai@example.com', 'currency_code' => 'USD']);
        $invoice = Invoice::factory()->for($workspace)->withLines([
            ['description' => 'Consultation', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0],
        ])->sent()->create(['contact_id' => $contact->id]);

        $gateways = app(PaymentGateways::class);
        $gateways->configure($workspace, $gateways->find('paynow'), true, ['integration_id' => '12345', 'integration_key' => self::PAYNOW_KEY]);
        $gateways->configure($workspace, $gateways->find('stripe'), true, ['secret_key' => self::STRIPE_SECRET, 'webhook_secret' => self::STRIPE_WEBHOOK]);

        return [$owner, $workspace->fresh(), $invoice->fresh()];
    }

    /** @param  array<string, string>  $fields */
    protected function paynowReply(array $fields): string
    {
        $fields['hash'] = PaynowGateway::hash($fields, self::PAYNOW_KEY);

        return http_build_query($fields);
    }

    /** @var array{initiate: string, poll: string} what the fake Paynow answers; tests change it mid-way */
    protected array $paynow = ['initiate' => '', 'poll' => ''];

    /** @var array<string, mixed> the fake Stripe checkout session */
    protected array $stripeSession = [];

    /** Fake both providers once; later calls just change what they answer (Http::fake stubs stack, first match wins). */
    protected function fakeProviders(): void
    {
        Http::fake([
            PaynowGateway::INITIATE_URL => fn () => Http::response($this->paynow['initiate']),
            'https://www.paynow.co.zw/Interface/CheckPayment/*' => fn () => Http::response($this->paynow['poll']),
            StripeGateway::API.'/checkout/sessions*' => fn () => Http::response($this->stripeSession),
        ]);
    }

    protected function fakePaynow(string $pollStatus = 'Paid', string $amount = '50.00', ?string $initiate = null): void
    {
        $this->paynow = [
            'initiate' => $initiate ?? $this->paynowReply([
                'status' => 'Ok',
                'browserurl' => 'https://www.paynow.co.zw/Payment/ConfirmPayment/9001',
                'pollurl' => 'https://www.paynow.co.zw/Interface/CheckPayment/?guid=abc-123',
            ]),
            'poll' => $this->paynowReply([
                'reference' => 'INV-0001-1', 'paynowreference' => '778899', 'amount' => $amount,
                'status' => $pollStatus, 'pollurl' => 'https://www.paynow.co.zw/Interface/CheckPayment/?guid=abc-123',
            ]),
        ];
    }

    /** @param  array<string, mixed>  $session */
    protected function fakeStripe(array $session = []): void
    {
        $this->stripeSession = array_merge([
            'id' => 'cs_test_session1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_session1',
            'payment_status' => 'unpaid', 'status' => 'open', 'amount_total' => 5000, 'currency' => 'usd', 'payment_intent' => null,
        ], $session);
    }

    protected function stripeSignature(string $payload, string $secret = self::STRIPE_WEBHOOK, ?int $time = null): string
    {
        $time ??= time();

        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret);
    }

    public function test_admin_saves_gateway_credentials_encrypted_and_blank_secrets_keep_their_value(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['invoicing'], $owner);

        $this->actingAs($owner)->put(route('settings.invoicing.gateways.update'), [
            'paynow' => ['enabled' => 1, 'integration_id' => '12345', 'integration_key' => 'secret-key-9876'],
            'stripe' => ['enabled' => 0, 'secret_key' => '', 'webhook_secret' => ''],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $workspace->refresh();
        $stored = $workspace->setting('payments.paynow.integration_key');
        $this->assertNotSame('secret-key-9876', $stored);
        $this->assertSame('secret-key-9876', Crypt::decryptString($stored));
        $this->assertSame('12345', $workspace->setting('payments.paynow.integration_id'));

        $this->actingAs($owner)->put(route('settings.invoicing.gateways.update'), [
            'paynow' => ['enabled' => 1, 'integration_id' => '12345', 'integration_key' => ''],
        ])->assertSessionHasNoErrors();

        $gateways = app(PaymentGateways::class);
        $this->assertSame('secret-key-9876', $gateways->credentials($workspace->fresh(), $gateways->find('paynow'))['integration_key']);
        $this->assertTrue($gateways->isEnabled($workspace->fresh(), $gateways->find('paynow')));

        $this->actingAs($owner)->get(route('settings.invoicing.edit'))->assertOk()
            ->assertSee('Online payments')->assertSee('Live on invoices')->assertSee('••••9876')->assertDontSee('secret-key-9876');
    }

    public function test_invalid_stripe_keys_are_rejected_and_members_cannot_change_gateways(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['invoicing'], $owner);

        $this->actingAs($owner)->put(route('settings.invoicing.gateways.update'), [
            'stripe' => ['enabled' => 1, 'secret_key' => 'pk_live_publishable'],
        ])->assertSessionHasErrors('stripe.secret_key');

        $member = $this->memberOf($workspace);
        $this->actingAs($member)->put(route('settings.invoicing.gateways.update'), [
            'paynow' => ['enabled' => 1, 'integration_id' => '1', 'integration_key' => 'x'],
        ])->assertForbidden();
    }

    public function test_pay_buttons_show_only_for_open_invoices_with_an_enabled_gateway(): void
    {
        [, $workspace, $invoice] = $this->payableInvoice();

        $this->get(route('invoices.public', $invoice->uuid))->assertOk()
            ->assertSee('Pay $50.00 online', false)->assertSee('Pay with Paynow')->assertSee('Pay with Stripe');

        $gateways = app(PaymentGateways::class);
        $gateways->configure($workspace, $gateways->find('stripe'), false, []);
        $this->get(route('invoices.public', $invoice->uuid))->assertOk()->assertSee('Pay with Paynow')->assertDontSee('Pay with Stripe');

        $draft = Invoice::factory()->for($workspace)->withLines()->create(['contact_id' => $invoice->contact_id]);
        $this->get(route('invoices.public', $draft->uuid))->assertOk()->assertDontSee('Pay with Paynow');
        $this->post(route('invoices.public.pay', [$draft->uuid, 'paynow']))->assertRedirect(route('invoices.public', $draft->uuid));
        $this->post(route('invoices.public.pay', [$invoice->uuid, 'stripe']))->assertNotFound();
        $this->post(route('invoices.public.pay', [$invoice->uuid, 'bitcoin']))->assertNotFound();
        $this->assertSame(0, OnlinePayment::allWorkspaces()->count());
    }

    public function test_paynow_payment_is_recorded_once_after_the_poll_confirms_it(): void
    {
        [, , $invoice] = $this->payableInvoice();
        $this->fakePaynow();

        $this->post(route('invoices.public.pay', [$invoice->uuid, 'paynow']))
            ->assertRedirect('https://www.paynow.co.zw/Payment/ConfirmPayment/9001');

        $attempt = OnlinePayment::allWorkspaces()->sole();
        $this->assertSame('pending', $attempt->status);
        $this->assertSame(50.0, $attempt->amount);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === PaynowGateway::INITIATE_URL
            && $request['id'] === '12345' && $request['amount'] === '50.00' && $request['authemail'] === 'tendai@example.com'
            && $request['hash'] === PaynowGateway::hash(array_diff_key($request->data(), ['hash' => 1]), self::PAYNOW_KEY));

        $this->get(route('online-payments.return', $attempt->uuid))
            ->assertRedirect(route('invoices.public', $invoice->uuid))->assertSessionHas('flash.type', 'success');

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $payment = $invoice->payments()->sole();
        $this->assertSame('online', $payment->method);
        $this->assertSame('Paynow 778899', $payment->reference);
        $this->assertSame('paid', $attempt->fresh()->status);

        // Paynow's own notification arriving after the customer came back changes nothing.
        $this->post(route('online-payments.webhook', ['gateway' => 'paynow', 'workspace' => $invoice->workspace->slug, 'attempt' => $attempt->uuid]), [
            'reference' => 'INV-0001-1', 'status' => 'Paid',
        ])->assertOk();
        $this->assertSame(1, $invoice->payments()->count());

        $this->get(route('invoices.public', $invoice->uuid))->assertDontSee('Pay with Paynow');
    }

    public function test_paynow_result_notification_settles_the_payment_without_the_customer_returning(): void
    {
        [, , $invoice] = $this->payableInvoice();
        $this->fakePaynow();
        $this->post(route('invoices.public.pay', [$invoice->uuid, 'paynow']));
        $attempt = OnlinePayment::allWorkspaces()->sole();

        // A forged "Paid" body is not trusted: the status always comes from Paynow's poll URL.
        $this->post(route('online-payments.webhook', ['gateway' => 'paynow', 'workspace' => $invoice->workspace->slug, 'attempt' => $attempt->uuid]), [
            'status' => 'Paid', 'hash' => 'forged',
        ])->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
        Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://www.paynow.co.zw/Interface/CheckPayment/'));
    }

    public function test_paynow_short_payment_cancellation_and_tampered_replies_do_not_record_money(): void
    {
        [, , $invoice] = $this->payableInvoice();
        $this->fakePaynow('Paid', '5.00');
        $this->post(route('invoices.public.pay', [$invoice->uuid, 'paynow']));
        $attempt = OnlinePayment::allWorkspaces()->sole();
        $this->get(route('online-payments.return', $attempt->uuid))->assertSessionHas('flash.type', 'danger');
        $this->assertSame('failed', $attempt->fresh()->status);

        $this->fakePaynow(initiate: 'status=Ok&browserurl=https%3A%2F%2Fevil.example&pollurl=x&hash=WRONG');
        $this->post(route('invoices.public.pay', [$invoice->uuid, 'paynow']))
            ->assertRedirect(route('invoices.public', $invoice->uuid))->assertSessionHas('flash.type', 'danger');
        $this->assertSame('failed', OnlinePayment::allWorkspaces()->latest('id')->first()->status);

        $this->fakePaynow('Cancelled');
        $this->post(route('invoices.public.pay', [$invoice->uuid, 'paynow']));
        $this->get(route('online-payments.return', OnlinePayment::allWorkspaces()->latest('id')->first()->uuid))->assertSessionHas('flash.message', 'Payment cancelled. Nothing was charged.');

        $this->assertSame(0, $invoice->payments()->count());
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_stripe_checkout_records_the_payment_when_the_session_is_paid(): void
    {
        [, , $invoice] = $this->payableInvoice();
        $this->fakeStripe();

        $this->post(route('invoices.public.pay', [$invoice->uuid, 'stripe']))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_session1');

        $attempt = OnlinePayment::allWorkspaces()->sole();
        $this->assertSame('cs_test_session1', $attempt->gateway_reference);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === StripeGateway::API.'/checkout/sessions'
            && $request->hasHeader('Authorization', 'Bearer '.self::STRIPE_SECRET)
            && $request['line_items'][0]['price_data']['unit_amount'] == 5000
            && $request['line_items'][0]['price_data']['currency'] === 'usd'
            && $request['client_reference_id'] === $attempt->uuid);

        // Still unpaid on Stripe's side: nothing is recorded yet.
        $this->get(route('online-payments.return', $attempt->uuid))->assertSessionHas('flash.type', 'info');
        $this->assertSame('pending', $attempt->fresh()->status);

        $this->fakeStripe(['payment_status' => 'paid', 'status' => 'complete', 'payment_intent' => 'pi_123']);
        $this->get(route('online-payments.return', $attempt->uuid))->assertSessionHas('flash.type', 'success');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('Stripe pi_123', $invoice->payments()->sole()->reference);
    }

    public function test_stripe_amount_mismatch_and_cancelled_checkout_record_nothing(): void
    {
        [, , $invoice] = $this->payableInvoice();
        $this->fakeStripe(['payment_status' => 'paid', 'amount_total' => 100]);
        $this->post(route('invoices.public.pay', [$invoice->uuid, 'stripe']));
        $this->get(route('online-payments.return', OnlinePayment::allWorkspaces()->sole()->uuid));
        $this->assertSame('failed', OnlinePayment::allWorkspaces()->sole()->status);

        $this->fakeStripe(['id' => 'cs_test_session2']);
        $this->post(route('invoices.public.pay', [$invoice->uuid, 'stripe']));
        $second = OnlinePayment::allWorkspaces()->latest('id')->first();
        $this->get(route('online-payments.return', ['attempt' => $second->uuid, 'cancelled' => 1]))
            ->assertSessionHas('flash.message', 'Payment cancelled. Nothing was charged.');
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_stripe_webhook_is_signature_checked_and_scoped_to_its_workspace(): void
    {
        [, $workspace, $invoice] = $this->payableInvoice();
        $this->fakeStripe();
        $this->post(route('invoices.public.pay', [$invoice->uuid, 'stripe']));
        $attempt = OnlinePayment::allWorkspaces()->sole();
        $this->fakeStripe(['payment_status' => 'paid', 'status' => 'complete', 'payment_intent' => 'pi_999']);

        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_session1', 'client_reference_id' => $attempt->uuid]]]);
        $url = route('online-payments.webhook', ['gateway' => 'stripe', 'workspace' => $workspace->slug]);

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_wrong')], $payload)->assertStatus(400);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, self::STRIPE_WEBHOOK, time() - 3600)], $payload)->assertStatus(400);
        $this->assertSame('pending', $attempt->fresh()->status);

        // Another workspace's endpoint cannot settle this workspace's attempt, even with its own valid signature.
        [, $other] = $this->payableInvoice();
        $otherUrl = route('online-payments.webhook', ['gateway' => 'stripe', 'workspace' => $other->slug]);
        $this->call('POST', $otherUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload)], $payload)->assertOk();
        $this->assertSame('pending', $attempt->fresh()->status);

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload)], $payload)->assertOk();
        $this->assertSame('paid', $attempt->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload)], $payload)->assertOk();
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_signature_and_paynow_helpers(): void
    {
        $this->assertSame(5000, StripeGateway::minorUnits(50, 'usd'));
        $this->assertSame(5000, StripeGateway::minorUnits(5000, 'JPY'));
        $this->assertTrue(PaynowGateway::isPaynowUrl('https://www.paynow.co.zw/Interface/CheckPayment/?guid=1'));
        $this->assertFalse(PaynowGateway::isPaynowUrl('https://paynow.co.zw.evil.example/x'));
        $this->assertFalse(PaynowGateway::isPaynowUrl('http://www.paynow.co.zw/x'));

        $this->expectException(GatewayException::class);
        StripeGateway::verifySignature('{}', 'garbage', 'whsec_x');
    }
}
