@extends('layouts.onboarding')
@section('title', 'Pick your apps')
@section('content')
    <form method="POST" action="{{ route('onboarding.store', 3) }}" x-data="selectable({{ Js::from($selected) }})">
        @csrf
        <template x-for="key in list" :key="key">
            <input type="hidden" name="modules[]" :value="key">
        </template>

        <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-4">
            <div>
                <h3 class="mb-1">Pick the apps you need</h3>
                <p class="text-muted mb-0">
                    @if($recommended)We've pre-selected a bundle for <strong>{{ $workspace->profession->name }}</strong>. @endif
                    Tick anything else that fits. Apps marked "coming soon" can be enabled now and will light up when released.
                </p>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <span class="z-chip"><x-icon name="layout-grid" class="zi zi-sm" /> <span x-text="list.length"></span> selected</span>
                @if($recommended)
                    <button type="button" class="btn btn-white" x-on:click="selected = new Set({{ Js::from($recommended) }})" title="Go back to the {{ $workspace->profession->name }} starter bundle">
                        <x-icon name="rotate-ccw" /> Recommended bundle
                    </button>
                @endif
                <a href="{{ route('onboarding.step', 2) }}" class="btn btn-white"><x-icon name="arrow-left" /> Back</a>
                <button type="submit" class="btn btn-primary">Continue <x-icon name="arrow-right" /></button>
            </div>
        </div>

        <div x-data="{ q: '' }" class="mb-4">
            <div class="z-search position-relative" style="max-width:420px">
                <x-icon name="search" class="zi position-absolute" style="left:.85rem;top:50%;transform:translateY(-50%);color:#98A4B8" />
                <input type="search" class="form-control ps-5" placeholder="Filter apps…" x-model="q">
            </div>
            @foreach($suites as $suite)
                <div class="mt-4" x-show="!q || $el.innerText.toLowerCase().includes(q.toLowerCase())">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="z-avatar z-avatar-sm z-avatar-soft rounded-2"><x-icon :name="$suite->icon ?: 'box'" class="zi zi-sm" /></span>
                        <h5 class="mb-0">{{ $suite->name }}</h5>
                        <span class="text-muted fs-8">{{ $suite->modules->count() }} apps</span>
                    </div>
                    <div class="row g-3">
                        @foreach($suite->modules as $module)
                            <div class="col-md-6 col-xl-4" x-show="!q || {{ Js::from(strtolower($module->name.' '.$module->description)) }}.includes(q.toLowerCase())">
                                <x-module-card :module="$module" :recommended="in_array($module->key, $recommended, true)"
                                               x-on:click="toggle({{ Js::from($module->key) }})"
                                               x-bind:class="{ selected: has({{ Js::from($module->key) }}) }" />
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="sticky-bottom bg-white border-top py-3 d-flex justify-content-between align-items-center" style="margin: 0 -1rem; padding: 0 1rem;">
            <span class="text-muted fs-7"><span x-text="list.length"></span> apps selected · core apps (contacts, dashboards, settings) are always included</span>
            <div class="d-flex gap-2">
                <a href="{{ route('onboarding.step', 2) }}" class="btn btn-white">Back</a>
                <button type="submit" class="btn btn-primary btn-lg">Continue <x-icon name="arrow-right" /></button>
            </div>
        </div>
    </form>
@endsection
