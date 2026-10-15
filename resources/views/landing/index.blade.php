@extends('layouts.marketing')
@section('content')
    @include('landing.hero')

    @include('landing.ai')

    @if($professions->isNotEmpty())
        <section class="z-mk-section">
            <div class="container">
                <div class="row g-5 align-items-start">
                    <div class="col-lg-4">
                        <div class="z-mk-kicker">Who it is for</div>
                        <h2 class="z-mk-title">Built for people like you</h2>
                        <p class="z-mk-lead mb-0">Pick your profession when you sign up and we suggest the apps that fit how you work. Change them whenever you like.</p>
                    </div>
                    <div class="col-lg-8">
                        <ul class="z-profession-list">
                            @foreach($professions as $p)
                                <li>{{ $p->name }}</li>
                            @endforeach
                            <li class="text-muted">and many more</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    @endif

    <section class="z-mk-section" id="apps">
        <div class="container">
            <div class="row g-5 align-items-start">
                <div class="col-lg-4">
                    <div class="z-mk-kicker">{{ $moduleCount }} apps</div>
                    <h2 class="z-mk-title">{{ $suites->count() }} suites. Mix and match.</h2>
                    <p class="z-mk-lead mb-0">Each suite is a family of apps that share the same contacts, documents, calendar and ledger, so nothing is typed twice.</p>
                </div>
                <div class="col-lg-8">
                    <div class="z-suite-list">
                        @foreach($suites as $s)
                            <a href="{{ route('register') }}"><x-icon :name="$s->icon ?: 'box'" /> {{ $s->name }} <small>{{ $s->modules_count }} apps</small></a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="z-mk-section is-tinted" id="pricing">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="z-mk-title mb-2">Simple pricing</h2>
                <p class="z-mk-lead mx-auto">Start free. Upgrade when you grow. Pay by card, Paynow or bank transfer in your own currency.</p>
            </div>
            <div class="row g-4 justify-content-center">
                @foreach($plans as $p)
                    @include('partials.plan-card', ['p' => $p, 'selectable' => false, 'guest' => true])
                @endforeach
            </div>
        </div>
    </section>
@endsection
