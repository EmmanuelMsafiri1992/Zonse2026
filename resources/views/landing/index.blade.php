@extends('layouts.marketing')
@section('content')
    @include('landing.hero')

    @if($professions->isNotEmpty())
        <section class="py-5 bg-white border-top border-bottom">
            <div class="container">
                <div class="row g-4 align-items-start">
                    <div class="col-lg-4">
                        <h3 class="mb-2">Built for people like you</h3>
                        <p class="text-muted mb-0">Pick your profession when you sign up and we suggest the apps that fit how you work.</p>
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

    <section class="py-5">
        <div class="container">
            <div class="text-center mb-4">
                <h3>{{ $suites->count() }} suites. Mix and match.</h3>
                <p class="text-muted">Each suite is a family of apps that share the same contacts, documents, calendar and ledger.</p>
            </div>
            <div class="row g-3">
                @foreach($suites as $s)
                    <div class="col-sm-6 col-lg-4 col-xl-3">
                        <div class="card card-flat h-100 hover-raise">
                            <div class="card-body d-flex gap-3">
                                <span class="z-avatar z-avatar-soft rounded-3"><x-icon :name="$s->icon ?: 'box'" /></span>
                                <div>
                                    <div class="fw-600">{{ $s->name }}</div>
                                    <div class="fs-8 text-muted">{{ $s->modules_count }} apps</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="py-5 bg-white border-top">
        <div class="container">
            <div class="text-center mb-4"><h3>Simple pricing</h3><p class="text-muted">Start free. Upgrade when you grow.</p></div>
            <div class="row g-4 justify-content-center">
                @foreach($plans as $p)
                    @include('partials.plan-card', ['p' => $p, 'selectable' => false, 'guest' => true])
                @endforeach
            </div>
        </div>
    </section>
@endsection
