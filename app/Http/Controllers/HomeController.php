<?php

namespace App\Http\Controllers;

use App\Assistant\ZonseobAi;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Profession;
use App\Models\Suite;
use App\Payments\PlatformCheckout;
use App\Payments\TokenPayments;
use App\Support\Money;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __construct(protected ZonseobAi $ai, protected TokenPayments $tokens, protected PlatformCheckout $checkout) {}

    public function index(Request $request)
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return view('landing.index', [
            'suites' => Suite::withCount('modules')->orderBy('sort_order')->get(),
            'moduleCount' => Module::count(),
            'professions' => Profession::where('is_featured', true)->orderBy('sort_order')->limit(12)->get(),
            'plans' => Plan::active()->get(),
        ] + $this->aiOffer());
    }

    public function pricing()
    {
        return view('landing.pricing', ['plans' => Plan::active()->with('modules')->get()] + $this->aiOffer());
    }

    /**
     * Zonseob AI packs with their local prices, for the public pages. Empty when the platform has no Claude key.
     *
     * @return array{aiPacks: list<array{name: string, tokens: int, usd: string, local: list<string>}>, aiWelcomeTokens: int}
     */
    protected function aiOffer(): array
    {
        if (! $this->ai->isAvailable()) {
            return ['aiPacks' => [], 'aiWelcomeTokens' => 0];
        }

        $packs = [];
        foreach ($this->tokens->packs() as $pack) {
            $packs[] = [
                'name' => $pack['name'],
                'tokens' => $pack['tokens'],
                'usd' => Money::format($pack['usd'], PlatformCheckout::BASE_CURRENCY, 0),
                'local' => collect($this->checkout->localAmounts($pack['usd']))->map(fn (float $amount, string $currency) => Money::format($amount, $currency))->values()->all(),
            ];
        }

        return ['aiPacks' => $packs, 'aiWelcomeTokens' => (int) config('zonseo.ai.welcome_tokens', 0)];
    }
}
