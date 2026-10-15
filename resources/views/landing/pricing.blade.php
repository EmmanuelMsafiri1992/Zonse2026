@extends('layouts.marketing')
@section('title', 'Pricing')
@section('content')
    <section class="z-mk-section">
        <div class="container">
            <div class="text-center mb-5">
                <h1 class="z-mk-title mb-2">Pricing</h1>
                <p class="z-mk-lead mx-auto">One subscription for every app. Paid plans include a free trial, no card needed. Pay by card, Paynow or bank transfer in your own currency.</p>
            </div>
            <div class="row g-4 justify-content-center">
                @foreach($plans as $p)
                    @include('partials.plan-card', ['p' => $p, 'selectable' => false, 'guest' => true])
                @endforeach
            </div>
            <div class="text-center mt-5">
                <a href="{{ route('register') }}" class="btn btn-primary btn-lg px-4">Create your free account</a>
            </div>
        </div>
    </section>

    @include('landing.ai')
@endsection
