@extends('layouts.app')
@section('title', 'Apps & modules')
@section('content')
    <x-page-header title="Apps & modules" sub="Switch on what your business needs. Everything shares the same contacts, team and billing." :crumbs="['Settings' => route('settings.workspace.edit'), 'Apps']">
        <span class="z-chip"><x-icon name="layout-grid" class="zi zi-sm" /> {{ $counts['enabled'] }} of {{ $counts['total'] }} apps enabled</span>
        <span class="z-chip"><x-icon name="rocket" class="zi zi-sm" /> {{ $counts['available'] }} ready today</span>
        @if($usage['allowance'] !== null)
            <span class="z-chip"><x-icon name="gauge" class="zi zi-sm" /> {{ $usage['used'] }} of {{ $usage['allowance'] }} plan apps used</span>
        @endif
        @if($addonKeys)
            <a href="{{ route('settings.billing.index') }}" class="z-chip"><x-icon name="puzzle" class="zi zi-sm" /> {{ count($addonKeys) }} paid {{ \Illuminate\Support\Str::plural('add-on', count($addonKeys)) }}</a>
        @endif
    </x-page-header>

    @if($quote = session('addon_quote'))
        <div class="alert alert-warning d-flex flex-wrap gap-3 align-items-center">
            <x-icon name="puzzle" class="zi" />
            <div class="flex-grow-1">
                <div class="fw-600">{{ $quote['message'] }}</div>
                @if(count($quote['apps']) > 1)<div class="fs-7">Includes: {{ implode(', ', $quote['apps']) }}.</div>@endif
            </div>
            <form method="POST" action="{{ $quote['action'] ?? route('settings.modules.enable', $quote['key']) }}">
                @csrf
                @foreach($quote['fields'] ?? [] as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                <input type="hidden" name="confirm_addon" value="all">
                <button class="btn btn-sm btn-primary">Add for {{ $quote['total'] }}/{{ $quote['per'] }}</button>
            </form>
            <a href="{{ route('settings.billing.index') }}" class="btn btn-sm btn-white">Compare plans</a>
        </div>
    @endif

    <form method="GET" class="card card-flat mb-4">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <div class="z-search position-relative flex-grow-1" style="max-width:420px">
                <input type="search" name="q" value="{{ $q }}" class="form-control" placeholder="Search apps, e.g. invoices, patients, stock…">
            </div>
            <div class="btn-group">
                @foreach(['all' => 'All', 'enabled' => 'Enabled', 'available' => 'Ready now', 'coming_soon' => 'Coming soon'] as $k => $label)
                    <button type="submit" name="filter" value="{{ $k }}" class="btn btn-sm {{ $filter === $k ? 'btn-primary' : 'btn-white' }}">{{ $label }}</button>
                @endforeach
            </div>
            @if($plan && ! $plan->includes_all_modules)
                <span class="text-muted fs-7 ms-auto">Your <strong>{{ $plan->name }}</strong> plan includes a limited set of apps; others can be added at their own price. <a href="{{ route('settings.billing.index') }}">Upgrade</a> to include all.</span>
            @elseif($slotsLeft === 0)
                <span class="text-muted fs-7 ms-auto">All the apps in your <strong>{{ $plan->name }}</strong> plan are in use; more can be added at their own price. <a href="{{ route('settings.billing.index') }}">Upgrade</a></span>
            @endif
        </div>
    </form>

    <form method="POST" action="{{ route('settings.modules.bundle') }}" class="card card-flat mb-4">
        @csrf
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <x-icon name="package-plus" class="zi text-primary" />
            <span class="fw-600">Starter bundles</span>
            <span class="text-muted fs-7">Switch on the set of apps we recommend for a type of business.</span>
            <select name="profession" class="form-select form-select-sm ms-auto" style="max-width:280px" required>
                <option value="">Choose a business type…</option>
                @foreach($professions->groupBy('group') as $group => $items)
                    <optgroup label="{{ $group }}">
                        @foreach($items as $profession)
                            <option value="{{ $profession->key }}" @selected((int) $workspace->profession_id === $profession->id)>{{ $profession->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <button class="btn btn-sm btn-primary">Apply bundle</button>
        </div>
    </form>

    @forelse($suites as $suite)
        <div class="mb-4">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="z-avatar z-avatar-sm z-avatar-soft rounded-2"><x-icon :name="$suite->icon ?: 'box'" class="zi zi-sm" /></span>
                <h5 class="mb-0">{{ $suite->name }}</h5>
                <span class="text-muted fs-8">{{ $suite->modules->count() }}</span>
            </div>
            <div class="row g-3">
                @foreach($suite->modules as $m)
                    @php
                        $on = $m->is_core || in_array($m->key, $enabled, true);
                        $isAddon = in_array($m->key, $addonKeys, true);
                        $wouldBeAddon = ! $m->is_core && ! $on && (($planKeys !== null && ! in_array($m->key, $planKeys, true)) || $slotsLeft === 0);
                        $price = \App\Support\Money::format($m->priceFor($cycle), 'USD', 0).'/'.($cycle === 'yearly' ? 'yr' : 'mo');
                    @endphp
                    <div class="col-md-6 col-xl-4">
                        <div class="z-module-card {{ $on ? 'selected' : '' }}" style="cursor:default">
                            <div class="z-module-icon"><x-icon :name="$m->icon ?: ($suite->icon ?? 'box')" /></div>
                            <div class="mw-0 flex-grow-1">
                                <div class="z-module-title">{{ $m->name }} <span class="text-muted fs-8 fw-normal">{{ $m->ref }}</span></div>
                                <div class="z-module-desc">{{ $m->description }}</div>
                                <div class="mt-2 d-flex flex-wrap gap-1 align-items-center">
                                    @if($m->is_core)<span class="z-pill z-pill-success">Core</span>
                                    @elseif(! $m->isAvailable())<span class="z-pill z-pill-muted">Coming soon</span>
                                    @elseif($m->status === 'beta')<span class="z-pill z-pill-info">Beta</span>
                                    @else<span class="z-pill z-pill-success">Ready</span>@endif
                                    @if($isAddon)<span class="z-pill z-pill-info">Add-on</span>
                                    @elseif($wouldBeAddon && $m->isAvailable())<span class="z-pill z-pill-warning">{{ $price }} add-on</span>@endif
                                    @if($m->depends)<span class="fs-8 text-muted">needs {{ implode(', ', $m->depends) }}</span>@endif
                                </div>
                            </div>
                            @unless($m->is_core)
                                <div class="position-absolute" style="top:.75rem;right:.75rem">
                                    @if($on)
                                        <form method="POST" action="{{ route('settings.modules.disable', $m->key) }}">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-soft-secondary" title="Disable">On</button>
                                        </form>
                                    @elseif($wouldBeAddon)
                                        <form method="POST" action="{{ route('settings.modules.enable', $m->key) }}">
                                            @csrf
                                            <input type="hidden" name="confirm_addon" value="1">
                                            <button class="btn btn-sm btn-white" title="Billed {{ $price }} on top of your plan">Add · {{ $price }}</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('settings.modules.enable', $m->key) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-primary">Enable</button>
                                        </form>
                                    @endif
                                </div>
                            @endunless
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <x-empty icon="search" title="No apps match" text="Try a different search or filter." />
    @endforelse
@endsection
