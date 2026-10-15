<?php

namespace App\Http\Controllers;

use App\Assistant\AssistantException;
use App\Assistant\ZonseobAi;
use App\Models\AiChat;
use App\Models\AiWallet;
use App\Models\Checkout;
use App\Payments\GatewayException;
use App\Payments\PlatformCheckout;
use App\Payments\TokenPayments;
use App\Support\Audit;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Zonseob AI: chat with Claude and buy the tokens it runs on. Chats and tokens belong to the
 * person, not a workspace, and the page stays open when a workspace's plan has lapsed.
 */
class AiController extends Controller
{
    public function __construct(
        protected ZonseobAi $ai,
        protected TokenPayments $tokens,
        protected PlatformCheckout $checkout,
        protected WorkspaceContext $context,
    ) {}

    public function index(Request $request): View
    {
        return view('ai.index', $this->pageData($request) + ['starters' => ZonseobAi::STARTERS]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:'.ZonseobAi::MAX_QUESTION]]);
        if ($refusal = $this->refusal($request)) {
            return redirect()->route('ai.index')->withInput()->with('flash', $refusal);
        }

        try {
            $chat = $this->ai->start($request->user(), $data['question']);
        } catch (AssistantException $e) {
            return redirect()->route('ai.index')->withInput()->with('flash', $this->failure($e));
        }

        return redirect()->route('ai.show', $chat);
    }

    public function show(Request $request, AiChat $chat): View
    {
        $this->authorizeChat($request, $chat);

        return view('ai.show', $this->pageData($request) + ['chat' => $chat, 'messages' => $chat->messages]);
    }

    public function ask(Request $request, AiChat $chat): RedirectResponse
    {
        $this->authorizeChat($request, $chat);
        $data = $request->validate(['question' => ['required', 'string', 'max:'.ZonseobAi::MAX_QUESTION]]);
        if ($refusal = $this->refusal($request)) {
            return back()->withInput()->with('flash', $refusal);
        }

        try {
            $this->ai->ask($chat, $data['question']);
        } catch (AssistantException $e) {
            return back()->withInput()->with('flash', $this->failure($e));
        }

        return redirect()->route('ai.show', $chat);
    }

    public function destroy(Request $request, AiChat $chat): RedirectResponse
    {
        $this->authorizeChat($request, $chat);
        $chat->delete();

        return redirect()->route('ai.index')->with('flash', ['type' => 'success', 'message' => 'Chat deleted.']);
    }

    /** Buy a pack of tokens through the platform checkout. */
    public function buy(Request $request): RedirectResponse
    {
        if (! $this->ai->isAvailable()) {
            return redirect()->route('ai.index')->with('flash', ['type' => 'warning', 'message' => 'Zonseob AI is not switched on here yet, so tokens cannot be bought.']);
        }
        $gateways = array_keys($this->checkout->available());
        if ($this->checkout->manualEnabled()) {
            $gateways[] = PlatformCheckout::MANUAL;
        }

        $data = $request->validate([
            'pack' => ['required', Rule::in(array_keys($this->tokens->packs()))],
            'gateway' => ['required', Rule::in($gateways)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $pack = $this->tokens->pack($data['pack']);
        $subject = ['pack' => $pack['key'], 'tokens' => $pack['tokens']];

        try {
            $checkout = $this->checkout->begin($request->user(), $this->context->get(), 'tokens', $subject, $pack['usd'], $data['gateway'], $data['note'] ?? null);
            if ($checkout->isManual()) {
                Audit::log('settings', 'payment-sent', 'Said a bank transfer of '.$checkout->baseMoney().' was sent for '.$checkout->description(), $checkout);

                return redirect()->route('ai.index')->with('flash', [
                    'type' => 'info',
                    'message' => 'Thank you. Our team confirms transfers within one working day; your tokens are added as soon as it is confirmed.',
                ]);
            }

            $url = $this->checkout->start($checkout);
        } catch (GatewayException $e) {
            return redirect()->route('ai.index')->with('flash', ['type' => 'danger', 'message' => 'The payment could not be started: '.$e->getMessage().' Try another way to pay.']);
        }

        return redirect()->away($url);
    }

    /** @return array<string, mixed> */
    protected function pageData(Request $request): array
    {
        $user = $request->user();
        $available = $this->ai->isAvailable();
        $packs = [];
        foreach ($this->tokens->packs() as $key => $pack) {
            $packs[$key] = $pack + [
                'usd_label' => Money::format($pack['usd'], PlatformCheckout::BASE_CURRENCY, 0),
                'local' => collect($this->checkout->localAmounts($pack['usd']))->map(fn (float $amount, string $currency) => Money::format($amount, $currency))->all(),
            ];
        }

        return [
            'available' => $available,
            'wallet' => AiWallet::for($user),
            'chats' => AiChat::query()->where('user_id', $user->id)->orderByDesc('last_message_at')->orderByDesc('id')->limit(30)->get(),
            'model' => $this->ai->model(),
            'packs' => $packs,
            'gateways' => $available ? $this->checkout->available() : [],
            'manualInstructions' => $available ? $this->checkout->manualInstructions() : '',
            'paynowCurrency' => $this->checkout->currencyFor('paynow'),
            'purchases' => Checkout::query()->where('user_id', $user->id)->where('purpose', 'tokens')->latest()->limit(20)->get(),
            'outputWeight' => max(1, (int) config('zonseo.ai.output_weight', 4)),
        ];
    }

    /** @return array{type: string, message: string}|null */
    protected function refusal(Request $request): ?array
    {
        if (! $this->ai->isAvailable()) {
            return ['type' => 'warning', 'message' => 'Zonseob AI is not switched on here yet.'];
        }
        if (! AiWallet::for($request->user())->canAsk()) {
            return ['type' => 'warning', 'message' => 'You have run out of tokens. Buy a pack below to keep asking.'];
        }

        return null;
    }

    /** @return array{type: string, message: string} */
    protected function failure(AssistantException $e): array
    {
        return ['type' => 'danger', 'message' => 'Claude could not answer just now, so nothing was charged. '.($e->permanent ? $e->getMessage() : 'Please try again in a moment.')];
    }

    protected function authorizeChat(Request $request, AiChat $chat): void
    {
        abort_unless($chat->user_id === $request->user()->id, 404);
    }
}
