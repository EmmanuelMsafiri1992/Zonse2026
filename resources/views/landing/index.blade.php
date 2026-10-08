@extends('layouts.marketing')
@section('content')
    <section class="py-5" style="background: linear-gradient(180deg, #fff 0%, #F5F7FA 100%)">
        <div class="container py-lg-5 text-center" style="max-width: 860px">
            <span class="z-chip mb-3"><x-icon name="sparkles" class="zi zi-sm text-primary" /> {{ $moduleCount }}+ apps · {{ $suites->count() }} suites · one login</span>
            <h1 class="display-5 fw-600 mb-3">Every business system your profession needs, in one subscription.</h1>
            <p class="lead text-muted mb-4">Accounting, invoicing, appointments, patients, students, stock, POS, HR, projects, tickets, tenants, farms, churches and more. Switch on what fits you. Nothing you don't need.</p>
            <div class="d-flex justify-content-center gap-2 flex-wrap">
                <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Start free, no card needed</a>
                <a href="{{ route('pricing') }}" class="btn btn-white btn-lg">See pricing</a>
            </div>
        </div>
    </section>

    @if($professions->isNotEmpty())
        <section class="py-5 bg-white border-top border-bottom">
            <div class="container">
                <h4 class="text-center mb-4">Built for people like you</h4>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    @foreach($professions as $p)
                        <span class="z-chip"><x-icon :name="$p->icon ?: 'briefcase'" class="zi zi-sm text-primary" /> {{ $p->name }}</span>
                    @endforeach
                    <span class="z-chip">…and many more</span>
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
