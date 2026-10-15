{{-- Zonseob AI on the public pages. Expects $aiPacks (empty when the platform has no Claude key) and $aiWelcomeTokens. --}}
@if($aiPacks)
    <section class="z-mk-section is-tinted" id="zonseob-ai">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <div class="z-mk-kicker">Included with every account</div>
                    <h2 class="z-mk-title">Zonseob AI, powered by Claude</h2>
                    <p class="z-mk-lead mb-4">Claude from Anthropic, inside your {{ config('app.name') }} account, for writing, planning, explaining, translating and everyday questions. No Anthropic account and no foreign-currency card: you buy tokens from us in your own currency.</p>
                    <div class="z-price-list mb-4">
                        @foreach($aiPacks as $pack)
                            <div class="z-price">
                                <b>{{ $pack['usd'] }}</b>
                                <div>{{ $pack['name'] }} pack <small>{{ number_format($pack['tokens']) }} tokens</small></div>
                                @if($pack['local'])<div class="z-price-local">{!! implode("<br>", array_map("e", $pack["local"])) !!}</div>@endif
                            </div>
                        @endforeach
                    </div>
                    <p class="fs-7 text-muted mb-4">Every account starts with {{ number_format($aiWelcomeTokens) }} free tokens. A short question and answer uses a few hundred tokens, a long document a few thousand. Tokens never expire. Local amounts follow today's rate.</p>
                    <a href="{{ route('register') }}" class="btn btn-primary">Start free and try it</a>
                </div>
                <div class="col-lg-6">
                    <div class="z-convo" aria-hidden="true">
                        <div class="z-convo-head"><b>Zonseob AI</b><span>Balance 19,560 tokens</span></div>
                        <div class="z-convo-body">
                            <div class="z-convo-msg from-me">Write a polite reminder to a customer whose invoice is 2 weeks overdue.</div>
                            <div class="z-convo-msg from-ai">
                                <p><strong>Subject: Invoice INV-0309, a gentle reminder</strong></p>
                                <p>Dear Mr Kamuzu, I hope you are well. Invoice INV-0309 for $320 was due on 1 October and is still open. If it has already been paid, please ignore this note. Otherwise we would be grateful for payment by Friday. Thank you for your business.</p>
                                <span class="z-convo-cost">440 tokens</span>
                            </div>
                            <div class="z-convo-msg from-me">Translate it into Shona.</div>
                        </div>
                        <div class="z-convo-input"><span>Ask anything…</span><button type="button" class="btn btn-primary btn-sm" tabindex="-1">Ask</button></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endif
