@extends('layouts.onboarding')
@section('title', 'Choose a plan')
@section('content')
    @php $featured = $plans->firstWhere('is_featured', true) ?? $plans->first(); @endphp
    <form method="POST" action="{{ route('onboarding.store', 4) }}" x-data="{ plan: {{ Js::from($featured?->key) }}, cycle: 'monthly' }">
        @csrf
        <input type="hidden" name="plan" :value="plan">
        <input type="hidden" name="billing_cycle" :value="cycle">

        <div class="text-center mb-4">
            <h3 class="mb-1">Choose a plan</h3>
            <p class="text-muted">You picked {{ $moduleCount }} {{ \Illuminate\Support\Str::plural('app', $moduleCount) }}. Every paid plan starts with a free trial, no card needed.</p>
            <div class="btn-group" role="group">
                <button type="button" class="btn" :class="cycle === 'monthly' ? 'btn-primary' : 'btn-white'" x-on:click="cycle = 'monthly'">Monthly</button>
                <button type="button" class="btn" :class="cycle === 'yearly' ? 'btn-primary' : 'btn-white'" x-on:click="cycle = 'yearly'">Yearly <span class="badge bg-success ms-1">2 months free</span></button>
            </div>
        </div>

        <div class="row g-4 justify-content-center">
            @foreach($plans as $p)
                @include('partials.plan-card', ['p' => $p, 'selectable' => true])
            @endforeach
        </div>

        <div class="d-flex justify-content-between mt-4">
            <a href="{{ route('onboarding.step', 3) }}" class="btn btn-white"><x-icon name="arrow-left" /> Back</a>
            <button type="submit" class="btn btn-primary btn-lg" :disabled="!plan">Finish setup <x-icon name="check" /></button>
        </div>
    </form>
@endsection
