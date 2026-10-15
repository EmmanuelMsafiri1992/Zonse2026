{{-- Zonseob AI on the public pages. Expects $aiPacks (empty when the platform has no Claude key) and $aiWelcomeTokens. --}}
@if($aiPacks)
    <section class="py-5 border-top" id="zonseob-ai">
        <div class="container">
            <div class="row g-4 align-items-start">
                <div class="col-lg-5">
                    <h3 class="mb-2">Zonseob AI, powered by Claude</h3>
                    <p class="text-muted">Claude from Anthropic, for writing, planning, explaining, translating and everyday questions, inside your Zonseob account. No Anthropic account, no foreign-currency card: buy tokens from us with EcoCash, a local card or a bank transfer and start asking.</p>
                    <ul class="text-muted fs-7 ps-3 mb-3">
                        <li>Every account starts with {{ number_format($aiWelcomeTokens) }} free tokens.</li>
                        <li>Tokens never expire and stay with you in every workspace.</li>
                        <li>Included with every plan, including Free.</li>
                    </ul>
                    <a href="{{ route('register') }}" class="btn btn-primary">Start free and try it</a>
                </div>
                <div class="col-lg-7">
                    <div class="row g-3">
                        @foreach($aiPacks as $pack)
                            <div class="col-sm-4">
                                <div class="card card-flat h-100">
                                    <div class="card-body">
                                        <div class="fw-600">{{ $pack['name'] }}</div>
                                        <div class="fs-3 fw-600">{{ $pack['usd'] }}</div>
                                        <div class="fs-8 text-muted">{{ number_format($pack['tokens']) }} tokens</div>
                                        @if($pack['local'])
                                            <div class="fs-8 text-muted mt-2">About {{ implode(' or ', $pack['local']) }}</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="fs-8 text-muted mt-3">A short question and answer uses a few hundred tokens; a long document a few thousand. Prices are in US dollars and local amounts follow today's rate.</div>
                </div>
            </div>
        </div>
    </section>
@endif
