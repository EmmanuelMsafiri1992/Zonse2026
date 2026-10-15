<?php

namespace Tests\Feature;

use App\Assistant\Providers\AnthropicProvider;
use App\Models\AiChat;
use App\Models\AiWallet;
use App\Models\Checkout;
use App\Payments\PaynowGateway;
use App\Payments\PlatformCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class ZonseobAiTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected const PAYNOW_KEY = 'platform-paynow-key';

    /** @var list<array<string, mixed>> */
    protected array $claudeReplies = [];

    /** @var array{initiate: string, poll: string} */
    protected array $paynow = ['initiate' => '', 'poll' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Http::preventStrayRequests();
        Http::fake([
            AnthropicProvider::ENDPOINT => fn () => Http::response(array_shift($this->claudeReplies) ?? ['error' => ['message' => 'no reply queued']], $this->claudeReplies === [] && $this->claudeFails ? 500 : 200),
            PaynowGateway::INITIATE_URL => fn () => Http::response($this->paynow['initiate']),
            'https://www.paynow.co.zw/Interface/CheckPayment/*' => fn () => Http::response($this->paynow['poll']),
        ]);

        config()->set('zonseo.assistant', ['api_key' => 'sk-ant-platform', 'landing_model' => 'claude-haiku-5-5', 'model' => 'claude-sonnet-5-5']);
        config()->set('zonseo.ai', ['welcome_tokens' => 20000, 'packs' => 'starter=250000=5,standard=1000000=15,large=5000000=60', 'output_weight' => 4, 'min_balance' => 1000]);
        config()->set('zonseo.checkout', [
            'paynow' => ['integration_id' => '', 'integration_key' => '', 'currency' => 'USD'],
            'stripe' => ['secret_key' => '', 'webhook_secret' => ''],
            'rates' => 'ZWG=26.5',
            'manual_instructions' => null,
            'grace_days' => 7,
            'enforce_from' => null,
        ]);
    }

    protected bool $claudeFails = false;

    protected function claudeSays(string $text, int $input = 100, int $output = 50): void
    {
        $this->claudeReplies[] = ['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => $input, 'output_tokens' => $output]];
    }

    /** @param  array<string, string>  $fields */
    protected function paynowReply(array $fields): string
    {
        $fields['hash'] = PaynowGateway::hash($fields, self::PAYNOW_KEY);

        return http_build_query($fields);
    }

    public function test_the_page_needs_the_platform_key(): void
    {
        [$owner] = $this->ownerWithWorkspace();

        config()->set('zonseo.assistant.api_key', '');
        $this->actingAs($owner)->get(route('ai.index'))->assertOk()
            ->assertSee('Zonseob AI is not switched on here yet')
            ->assertDontSee('Buy tokens');
        $this->actingAs($owner)->post(route('ai.store'), ['question' => 'Hello'])->assertRedirect(route('ai.index'));
        $this->assertSame(0, AiChat::count());

        config()->set('zonseo.assistant.api_key', 'sk-ant-platform');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Zonseob AI');
        $this->actingAs($owner)->get(route('ai.index'))->assertOk()
            ->assertSee('data-ai-balance="20000"', false)
            ->assertSee('20,000')
            ->assertSee('Try asking')
            ->assertSee('claude-sonnet-5-5');
    }

    public function test_a_question_opens_a_chat_and_spends_welcome_tokens(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $this->claudeSays("Here is a **draft**:\n\nDear customer, your invoice is overdue.", 120, 80);

        $response = $this->actingAs($owner)->post(route('ai.store'), ['question' => 'Write a reminder for an overdue invoice']);

        $chat = AiChat::query()->where('user_id', $owner->id)->firstOrFail();
        $response->assertRedirect(route('ai.show', $chat));
        $this->assertSame('Write a reminder for an overdue invoice', $chat->title);
        $this->assertSame(['user', 'assistant'], $chat->messages->pluck('role')->all());
        $this->assertSame(120 + 4 * 80, $chat->messages->last()->cost_tokens);

        $wallet = AiWallet::for($owner);
        $this->assertSame(20000 - 440, $wallet->balance_tokens);
        $this->assertSame(440, $wallet->used_tokens);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === AnthropicProvider::ENDPOINT
            && $request->hasHeader('x-api-key', 'sk-ant-platform')
            && $request['model'] === 'claude-sonnet-5-5'
            && str_contains($request['system'], 'Zonseob AI')
            && str_contains($request['system'], $owner->name)
            && count($request['messages']) === 1
            && $request['messages'][0]['content'][0]['text'] === 'Write a reminder for an overdue invoice');

        $this->actingAs($owner)->get(route('ai.show', $chat))->assertOk()
            ->assertSee('<strong>draft</strong>', false)
            ->assertSee('440 tokens')
            ->assertSee('data-ai-balance="19560"', false);
    }

    public function test_a_follow_up_sends_the_chat_so_far(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $this->claudeSays('Four.');
        $this->claudeSays('Eight.');

        $this->actingAs($owner)->post(route('ai.store'), ['question' => 'What is 2 + 2?']);
        $chat = AiChat::query()->firstOrFail();
        $this->actingAs($owner)->post(route('ai.ask', $chat), ['question' => 'And doubled?'])->assertRedirect(route('ai.show', $chat));

        Http::assertSent(fn (HttpRequest $request) => $request->url() === AnthropicProvider::ENDPOINT
            && count($request['messages']) === 3
            && $request['messages'][1]['role'] === 'assistant'
            && $request['messages'][1]['content'][0]['text'] === 'Four.'
            && $request['messages'][2]['content'][0]['text'] === 'And doubled?');
        $this->assertCount(4, $chat->messages);
        $this->assertSame(20000 - 2 * 300, AiWallet::for($owner)->balance_tokens);
    }

    public function test_without_enough_tokens_the_question_is_refused(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        AiWallet::for($owner)->forceFill(['balance_tokens' => 999])->save();

        $this->actingAs($owner)->get(route('ai.index'))->assertOk()->assertSee('You have run out of tokens')->assertSee('Buy tokens to ask');
        $this->actingAs($owner)->post(route('ai.store'), ['question' => 'Hello'])
            ->assertRedirect(route('ai.index'))
            ->assertSessionHas('flash', fn (array $flash) => str_contains($flash['message'], 'run out of tokens'));

        Http::assertNothingSent();
        $this->assertSame(0, AiChat::count());
    }

    public function test_a_long_answer_never_takes_the_balance_below_zero(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        AiWallet::for($owner)->forceFill(['balance_tokens' => 1200])->save();
        $this->claudeSays(str_repeat('word ', 500), 1000, 900);

        $this->actingAs($owner)->post(route('ai.store'), ['question' => 'Write a long essay']);

        $wallet = AiWallet::for($owner);
        $this->assertSame(0, $wallet->balance_tokens);
        $this->assertSame(1000 + 4 * 900, $wallet->used_tokens);
        $this->assertSame(4600, AiChat::query()->firstOrFail()->messages->last()->cost_tokens);
    }

    public function test_nothing_is_kept_or_charged_when_claude_fails(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $this->claudeFails = true;

        $this->actingAs($owner)->post(route('ai.store'), ['question' => 'Hello'])
            ->assertRedirect(route('ai.index'))
            ->assertSessionHas('flash', fn (array $flash) => $flash['type'] === 'danger' && str_contains($flash['message'], 'nothing was charged'));

        $this->assertSame(0, AiChat::count());
        $this->assertSame(20000, AiWallet::for($owner)->balance_tokens);
    }

    public function test_chats_are_private_to_the_person(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        [$other] = $this->ownerWithWorkspace();
        $this->claudeSays('Hi.');
        $this->actingAs($owner)->post(route('ai.store'), ['question' => 'Hello there']);
        $chat = AiChat::query()->firstOrFail();

        $this->actingAs($other)->get(route('ai.show', $chat))->assertNotFound();
        $this->actingAs($other)->post(route('ai.ask', $chat), ['question' => 'Hi'])->assertNotFound();
        $this->actingAs($other)->delete(route('ai.destroy', $chat))->assertNotFound();
        $this->actingAs($other)->get(route('ai.index'))->assertOk()->assertDontSee('Hello there');

        $this->actingAs($owner)->delete(route('ai.destroy', $chat))->assertRedirect(route('ai.index'));
        $this->assertSame(0, AiChat::count());
    }

    public function test_packs_are_offered_in_dollars_and_local_currency(): void
    {
        config()->set('zonseo.checkout.manual_instructions', "EcoCash merchant 123456\nReference: your name");
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('ai.index'))->assertOk()
            ->assertSee('Buy tokens')
            ->assertSee('Starter')->assertSee('250,000 tokens')->assertSee('$5', false)
            ->assertSee('Standard')->assertSee('1,000,000 tokens')->assertSee('$15', false)
            ->assertSee('ZiG397.50', false)
            ->assertSee('Pay by bank transfer or mobile money')
            ->assertSee('EcoCash merchant 123456');
    }

    public function test_a_bank_transfer_credits_the_wallet_once_an_admin_confirms_it(): void
    {
        config()->set('zonseo.checkout.manual_instructions', 'EcoCash merchant 123456');
        [$owner, $workspace] = $this->ownerWithWorkspace();
        [$admin] = $this->ownerWithWorkspace('free');
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($owner)->post(route('ai.tokens'), ['pack' => 'standard', 'gateway' => 'manual', 'note' => 'EcoCash ref 777'])
            ->assertRedirect(route('ai.index'))
            ->assertSessionHas('flash', fn (array $flash) => str_contains($flash['message'], 'confirms transfers'));

        $checkout = Checkout::query()->firstOrFail();
        $this->assertSame('tokens', $checkout->purpose);
        $this->assertSame('pending', $checkout->status);
        $this->assertSame(15.0, $checkout->base_amount);
        $this->assertSame(['pack' => 'standard', 'tokens' => 1000000], $checkout->subject);
        $this->assertSame('1,000,000 Zonseob AI tokens', $checkout->description());
        $this->assertSame(20000, AiWallet::for($owner)->balance_tokens);
        $this->actingAs($owner)->get(route('ai.index'))->assertOk()->assertSee('Token purchases')->assertSee('EcoCash ref 777');

        $this->actingAs($admin->fresh())->post(route('admin.payments.confirm', $checkout), ['reference' => 'BANK-1'])->assertRedirect();

        $wallet = AiWallet::for($owner);
        $this->assertSame(1020000, $wallet->balance_tokens);
        $this->assertSame(1000000, $wallet->purchased_tokens);
        $this->assertNotNull($checkout->fresh()->fulfilled_at);

        // Confirming again adds nothing.
        app(PlatformCheckout::class)->confirmManually($checkout->fresh(), $admin, 'BANK-1');
        $this->assertSame(1020000, AiWallet::for($owner)->balance_tokens);
    }

    public function test_paying_with_paynow_credits_the_wallet_and_returns_to_zonseob_ai(): void
    {
        config()->set('zonseo.checkout.paynow', ['integration_id' => '12345', 'integration_key' => self::PAYNOW_KEY, 'currency' => 'ZWG']);
        $this->paynow = [
            'initiate' => $this->paynowReply(['status' => 'Ok', 'browserurl' => 'https://www.paynow.co.zw/Payment/ConfirmPayment/7001', 'pollurl' => 'https://www.paynow.co.zw/Interface/CheckPayment/?guid=ai-1']),
            'poll' => $this->paynowReply(['reference' => 'ZB-TEST', 'paynowreference' => '556677', 'amount' => '132.50', 'status' => 'Paid', 'pollurl' => 'https://www.paynow.co.zw/Interface/CheckPayment/?guid=ai-1']),
        ];
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('ai.index'))->assertOk()->assertSee('Pay with Paynow')->assertSee('charged in ZWG');
        $this->actingAs($owner)->post(route('ai.tokens'), ['pack' => 'starter', 'gateway' => 'paynow'])
            ->assertRedirect('https://www.paynow.co.zw/Payment/ConfirmPayment/7001');

        $checkout = Checkout::query()->firstOrFail();
        $this->assertSame('ZWG', $checkout->currency_code);
        $this->assertSame(132.5, $checkout->amount);

        $this->actingAs($owner)->get(route('checkout.return', $checkout->uuid))
            ->assertRedirect(route('ai.index'))
            ->assertSessionHas('flash', fn (array $flash) => $flash['type'] === 'success' && str_contains($flash['message'], '250,000 Zonseob AI tokens have been added'));

        $this->assertSame('paid', $checkout->fresh()->status);
        $this->assertSame(270000, AiWallet::for($owner)->balance_tokens);
    }

    public function test_the_public_pages_sell_zonseob_ai_only_when_the_key_is_set(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Zonseob AI, powered by Claude')
            ->assertSee('20,000 free tokens')
            ->assertSee('$15', false)
            ->assertSee('ZiG397.50', false);
        $this->get('/pricing')->assertOk()->assertSee('Zonseob AI, powered by Claude');

        config()->set('zonseo.assistant.api_key', '');
        $this->get('/')->assertOk()->assertDontSee('Zonseob AI, powered by Claude');
    }

    public function test_a_paused_workspace_can_still_use_zonseob_ai(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $member = $this->memberOf($workspace);
        $workspace->subscription->forceFill(['trial_ends_at' => now()->subDays(10)])->save();
        $this->claudeSays('Still here.');

        $this->actingAs($member)->get(route('dashboard'))->assertStatus(402);
        $this->actingAs($member)->get(route('ai.index'))->assertOk()->assertSee('Try asking');
        $this->actingAs($member)->post(route('ai.store'), ['question' => 'Are you there?'])->assertRedirect();
        $this->assertSame(1, AiChat::query()->where('user_id', $member->id)->count());
    }
}
