@extends('layouts.marketing')
@section('title', 'Pricing')
@section('content')
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5" style="max-width:700px;margin:auto">
                <h1 class="fw-600">Pricing</h1>
                <p class="lead text-muted">One subscription for every app. Paid plans include a free trial, no card needed.</p>
            </div>
            <div class="row g-4 justify-content-center">
                @foreach($plans as $p)
                    @include('partials.plan-card', ['p' => $p, 'selectable' => false, 'guest' => true])
                @endforeach
            </div>
            <div class="text-center mt-5">
                <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Create your free account</a>
            </div>
        </div>
    </section>
@endsection
