@extends('layouts.onboarding')
@section('title', 'What do you do?')
@section('content')
    <form method="GET" action="{{ route('onboarding.step', 2) }}" class="card card-flat mb-4">
        <div class="card-body">
            <label for="describe" class="fw-600 mb-1">Describe your business in a few words</label>
            <div class="d-flex gap-2">
                <input type="search" id="describe" name="describe" value="{{ $describe }}" class="form-control" maxlength="200"
                       placeholder="e.g. I run a hair salon and sell beauty products">
                <button class="btn btn-primary text-nowrap"><x-icon name="sparkles" /> Suggest</button>
            </div>
            @if($describe !== '' && $matches->isEmpty())
                <div class="fs-7 text-muted mt-2">No close match. Pick the nearest option below, or skip and choose apps yourself.</div>
            @endif
        </div>
    </form>

    @php $initial = $workspace->profession_id ?? $matches->first()?->id; @endphp
    <form method="POST" action="{{ route('onboarding.store', 2) }}" x-data="{ selected: {{ Js::from($initial) }} }">
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

        @if($matches->isNotEmpty())
            <div class="z-nav-title text-primary ps-0">Best matches for “{{ $describe }}”</div>
            <div class="row g-3 mb-4">
                @foreach($matches as $p)
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="z-profession-card" :class="{ selected: selected === {{ $p->id }} }" x-on:click="selected = {{ $p->id }}">
                            <x-icon :name="$p->icon ?: 'briefcase'" />
                            <div class="z-prof-title">{{ $p->name }}</div>
                            <div class="fs-8 text-muted mt-1">{{ count($p->module_keys ?? []) }} apps in the starter bundle</div>
                            @if($loop->first)<span class="z-pill z-pill-success mt-2">Best match</span>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

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
