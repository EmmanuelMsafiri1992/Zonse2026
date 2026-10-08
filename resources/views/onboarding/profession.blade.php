@extends('layouts.onboarding')
@section('title', 'What do you do?')
@section('content')
    <form method="POST" action="{{ route('onboarding.store', 2) }}" x-data="{ selected: {{ Js::from($workspace->profession_id) }} }">
        @csrf
        <input type="hidden" name="profession_id" :value="selected">

        <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-4">
            <div>
                <h3 class="mb-1">What best describes you?</h3>
                <p class="text-muted mb-0">We'll recommend a starter bundle of apps. You can change everything afterwards.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('onboarding.step', 1) }}" class="btn btn-white"><x-icon name="arrow-left" /> Back</a>
                <button type="submit" class="btn btn-white" x-on:click="selected = null">Skip</button>
                <button type="submit" class="btn btn-primary" :disabled="!selected">Continue <x-icon name="arrow-right" /></button>
            </div>
        </div>

        @foreach($groups as $group => $professions)
            <div class="z-nav-title text-muted ps-0">{{ $group }}</div>
            <div class="row g-3 mb-3">
                @foreach($professions as $p)
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="z-profession-card" :class="{ selected: selected === {{ $p->id }} }" x-on:click="selected = {{ $p->id }}">
                            <x-icon :name="$p->icon ?: 'briefcase'" />
                            <div class="z-prof-title">{{ $p->name }}</div>
                            @if($p->description)<div class="fs-8 text-muted mt-1">{{ $p->description }}</div>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach

        <div class="d-flex justify-content-end gap-2 mt-2">
            <button type="submit" class="btn btn-white" x-on:click="selected = null">Skip for now</button>
            <button type="submit" class="btn btn-primary btn-lg" :disabled="!selected">Continue <x-icon name="arrow-right" /></button>
        </div>
    </form>
@endsection
