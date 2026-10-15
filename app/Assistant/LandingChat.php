<?php

namespace App\Assistant;

use App\Assistant\Providers\AnthropicProvider;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Suite;
use App\Payments\PlatformCheckout;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCentre;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The chat on the public pages: answers visitors' questions about Zonseob (plans, apps, paying,
 * getting started) from the help centre and the live catalogue. With Zonseob's own Anthropic key
 * Claude writes the answers; without one, or when Claude cannot be reached, the best-matching help
 * article answers instead. Nothing is stored beyond the visitor's own session.
 */
class LandingChat
{
    public const MAX_TURNS = 12;

    public const SESSION_KEY = 'landing_chat';

    public const SUGGESTIONS = [
        'What does Zonseob cost?',
        'Can I pay with EcoCash or mobile money?',
        'Which apps are there for a clinic?',
        'How do I get started?',
    ];

    public function __construct(
        protected HelpCentre $help,
        protected PlatformCheckout $checkout,
        protected AnthropicProvider $claude,
    ) {}

    /** True when Zonseob's own Claude key is set, so answers come from the model rather than the help centre. */
    public function usesClaude(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Answer a visitor's question, remembering the exchange in their session for follow-ups.
     *
     * @return array{text: string, html: string, source: string}
     */
    public function answer(string $question): array
    {
        $question = trim($question);
        $history = $this->history();

        [$text, $source] = $this->usesClaude() ? $this->askClaude($history, $question) : [null, 'help'];
        if ($text === null) {
            $text = $this->answerFromHelp($question);
            $source = 'help';
        }

        $this->remember($history, $question, $text);

        return ['text' => $text, 'html' => $this->help->render($text), 'source' => $source];
    }

    /** @return list<array{role: string, content: string}> */
    public function history(): array
    {
        return array_values(array_filter((array) session(self::SESSION_KEY, []), fn ($turn) => is_array($turn) && isset($turn['role'], $turn['content'])));
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{0: ?string, 1: string}
     */
    protected function askClaude(array $history, string $question): array
    {
        $messages = [...$history, ['role' => 'user', 'content' => $question]];

        try {
            $reply = $this->claude->reply($this->systemPrompt(), $messages, [], [
                'api_key' => $this->apiKey(),
                'model' => (string) config('zonseo.assistant.landing_model', ''),
            ]);
        } catch (AssistantException $e) {
            Log::warning('Landing chat fell back to the help centre: '.$e->getMessage());

            return [null, 'help'];
        }

        return [filled($reply->text) ? trim((string) $reply->text) : null, 'claude'];
    }

    /** Everything Claude may say about Zonseob, written fresh from the live plans, catalogue and help articles. */
    public function systemPrompt(): string
    {
        $lines = [
            'You are the help chat on the public website of '.config('app.name').', a subscription platform of business apps (accounting, invoicing, appointments, patients, students, stock, point of sale, HR, projects, tenants, farms, churches and more) for businesses and organisations in Africa.',
            'You answer questions from visitors who have not signed up yet. Be warm, brief and plain: a short paragraph or a few bullet points, no headings. Answer only from the facts below. If the facts do not cover a question, say so and suggest they start a free account or email support; never invent prices, features, apps or policies.',
            'Prices are in US dollars. Mention the free trial and that no card is needed to start. When it helps, link to these pages and no others: '.route('pricing').' (pricing), '.route('register').' (create a free account), '.route('login').' (sign in). Write links as Markdown.',
            'Do not help with anything unrelated to '.config('app.name').'; politely steer back. Never claim to be a human.',
            '',
            '## Plans',
            $this->plansFacts(),
            '',
            '## Paying',
            $this->paymentFacts(),
            '',
            '## Apps (suites and the apps in each)',
            $this->catalogueFacts(),
            '',
            '## Help articles',
        ];
        foreach ($this->help->articles() as $article) {
            $lines[] = '### '.$article->title;
            $lines[] = $this->plainText($article->summary."\n\n".$article->body);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    protected function plansFacts(): string
    {
        $plans = Plan::active()->withCount('modules')->get();
        if ($plans->isEmpty()) {
            return 'Plans are shown on the pricing page.';
        }

        return $plans->map(function (Plan $plan) {
            $limits = $plan->limits ?? [];
            $parts = [
                $plan->isFree() ? 'free' : '$'.number_format($plan->price_monthly, 0).' per month or $'.number_format($plan->price_yearly, 0).' per year',
                ...$plan->appsSummary(),
            ];
            if (isset($limits['users'])) {
                $parts[] = $limits['users'] === -1 ? 'unlimited team members' : $limits['users'].' '.Str::plural('team member', (int) $limits['users']);
            }
            if (isset($limits['branches'])) {
                $parts[] = $limits['branches'] === -1 ? 'unlimited branches' : $limits['branches'].' '.Str::plural('branch', (int) $limits['branches']);
            }
            if (isset($limits['storage_gb'])) {
                $parts[] = $limits['storage_gb'].' GB file storage';
            }
            if (! $plan->isFree() && $plan->trial_days) {
                $parts[] = $plan->trial_days.'-day free trial, no card needed';
            }

            return '- '.$plan->name.($plan->tagline ? ' ('.$plan->tagline.')' : '').': '.implode('; ', $parts).'.';
        })->implode("\n");
    }

    protected function paymentFacts(): string
    {
        $ways = [];
        foreach ($this->checkout->available() as $gateway) {
            $currency = $this->checkout->currencyFor($gateway->key());
            $ways[] = $gateway->label().' ('.$gateway->methods().')'.($currency !== PlatformCheckout::BASE_CURRENCY ? ', charged in '.$currency.' at the day\'s rate' : '');
        }
        if ($this->checkout->manualEnabled()) {
            $ways[] = 'bank transfer or mobile money to Zonseob\'s own account, confirmed by our team within one working day';
        }
        $rates = $this->checkout->rates();

        $lines = [
            'Plans are paid from Settings, Plans & billing, after signing up. A paid plan starts with a free trial; nothing is charged until the trial ends and the person chooses to pay.',
            $ways ? 'Ways to pay: '.implode('; ', $ways).'.' : 'Online payment is being set up; until then our team confirms payments by hand after you get in touch from the billing page.',
            $rates ? 'Local-currency equivalents shown on the billing page use these rates per US$1: '.collect($rates)->map(fn ($rate, $code) => $code.' '.$rate)->implode(', ').'.' : null,
            'Workspaces on the Free plan are never paused. When a trial or paid period runs out there is a grace period before the workspace is paused, and nothing is deleted while paused.',
        ];

        return implode("\n", array_filter($lines));
    }

    protected function catalogueFacts(): string
    {
        $modules = Module::query()->orderBy('sort_order')->get(['suite_id', 'name', 'is_core']);
        $bySuite = $modules->where('is_core', false)->groupBy('suite_id');
        $lines = ['- Included in every workspace: shared contacts, dashboard, calendar, documents and files, notifications, automations, approvals, custom fields, data import and export, customer and staff portals, help centre.'];
        foreach (Suite::query()->orderBy('sort_order')->get() as $suite) {
            /** @var Collection<int, Module> $apps */
            $apps = $bySuite->get($suite->id, collect());
            if ($apps->isEmpty()) {
                continue;
            }
            $lines[] = '- '.$suite->name.': '.$apps->map(fn (Module $app) => $this->shortName($app->name))->implode(', ').'.';
        }
        $lines[] = 'Apps are switched on and off from Settings, Apps; a plan covers a number of apps and the rest are paid add-ons. Every app shares the same contacts, documents, calendar and ledger.';

        return implode("\n", $lines);
    }

    /** Answer from the help centre and catalogue when Claude is not available. */
    protected function answerFromHelp(string $question): string
    {
        $lower = Str::lower($question);
        if (preg_match('/\b(price|prices|pricing|cost|costs|how much|plan|plans|subscription|free|trial|cheap|expensive)\b/', $lower)) {
            return $this->plansAnswer();
        }
        if (preg_match('/\b(pay|paying|payment|ecocash|onemoney|innbucks|mobile money|card|visa|mastercard|stripe|paynow|bank|transfer|currency|dollar|usd|zig|zwg|rand)\b/', $lower)) {
            return $this->paymentFacts()."\n\nSee [pricing](".route('pricing').') or [create a free account]('.route('register').') to get started.';
        }
        if ($apps = $this->matchingApps($question)) {
            return 'Apps that match what you asked: '.$apps->implode(', ').'. Every plan starts free, so you can [create an account]('.route('register').') and switch them on to try them.';
        }

        $article = $this->help->search($this->keywords($question))->first();
        if ($article instanceof HelpArticle) {
            return '**'.$article->title.'**: '.$article->summary."\n\n".$this->excerpt($article)."\n\nThis is explained in full in the help centre once you have [created a free account](".route('register').').';
        }

        return 'I could not find that in our help pages. '.config('app.name').' is one subscription for every business app you need, and every plan starts free with no card. '
            .'See [pricing]('.route('pricing').') or [create a free account]('.route('register').'), and our team can answer anything else once you are in.';
    }

    protected function plansAnswer(): string
    {
        return "Here are the plans, in US dollars:\n\n".$this->plansFacts()."\n\nEvery paid plan starts with a free trial and no card is needed. [See pricing](".route('pricing').') or [start free]('.route('register').').';
    }

    /**
     * Apps whose names share a word with a question that asks about apps ("which apps are there for a clinic?").
     *
     * @return Collection<int, string>|null
     */
    protected function matchingApps(string $question): ?Collection
    {
        $lower = Str::lower(Str::ascii($question));
        if (! preg_match('/\b(app|apps|module|modules|software|system|tool|tools|manage|run|for (a|an|my|our))\b/', $lower)) {
            return null;
        }
        $stop = ['which', 'what', 'app', 'apps', 'have', 'there', 'does', 'with', 'your', 'this', 'that', 'module', 'modules', 'zonseob', 'software', 'system', 'tool', 'tools', 'manage', 'from', 'about', 'need', 'want', 'like', 'some', 'thing', 'things'];
        $words = collect(preg_split('/[^\pL\pN]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $word) => Str::singular($word))
            ->filter(fn (string $word) => mb_strlen($word) >= 4 && ! in_array($word, $stop, true));
        if ($words->isEmpty()) {
            return null;
        }
        $matches = Module::query()->where('is_core', false)->orderBy('sort_order')->pluck('name')
            ->filter(function (string $name) use ($words) {
                $nameWords = collect(preg_split('/[^\pL\pN]+/u', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY))->map(fn (string $word) => Str::singular($word));

                return $words->contains(fn (string $word) => $nameWords->contains($word));
            })
            ->map(fn (string $name) => $this->shortName($name))->take(8)->values();

        return $matches->isEmpty() ? null : $matches;
    }

    /** The question without the small words, since the help search wants every word to match. */
    protected function keywords(string $question): string
    {
        $stop = ['how', 'do', 'i', 'can', 'my', 'the', 'a', 'an', 'to', 'is', 'it', 'in', 'on', 'of', 'for', 'and', 'or', 'with', 'what', 'which', 'where', 'when', 'does', 'are', 'you', 'your', 'there', 'this', 'that', 'we', 'our', 'me', 'be', 'get', 'have', 'zonseob'];

        return collect(preg_split('/[^\pL\pN]+/u', Str::lower(Str::ascii($question)), -1, PREG_SPLIT_NO_EMPTY))
            ->reject(fn (string $word) => in_array($word, $stop, true))
            ->map(fn (string $word) => Str::singular($word))
            ->implode(' ');
    }

    /** Module names from the catalogue sometimes carry a bracketed list; the name before it is enough here. */
    protected function shortName(string $name): string
    {
        return trim((string) preg_replace('/\s*\(.*$/s', '', $name));
    }

    protected function excerpt(HelpArticle $article): string
    {
        $paragraphs = preg_split('/\n\s*\n/', $this->plainText($article->body)) ?: [];
        $kept = [];
        foreach ($paragraphs as $paragraph) {
            if (str_starts_with(trim($paragraph), '#')) {
                continue;
            }
            $kept[] = trim($paragraph);
            if (count($kept) === 2) {
                break;
            }
        }

        return implode("\n\n", $kept);
    }

    /** Article Markdown without the in-app links, which lead nowhere for a visitor. */
    protected function plainText(string $markdown): string
    {
        return trim((string) preg_replace('/\[([^\]]+)\]\((?:route|help):[^)]+\)/', '$1', $markdown));
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    protected function remember(array $history, string $question, string $answer): void
    {
        $turns = [...$history, ['role' => 'user', 'content' => $question], ['role' => 'assistant', 'content' => $answer]];
        session([self::SESSION_KEY => array_values(array_slice($turns, -self::MAX_TURNS))]);
    }

    protected function apiKey(): string
    {
        return trim((string) config('zonseo.assistant.api_key', ''));
    }
}
