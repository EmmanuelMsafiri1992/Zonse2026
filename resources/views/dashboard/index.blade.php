@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
    <x-page-header :title="'Good '.(now($workspace->timezone)->hour < 12 ? 'morning' : (now($workspace->timezone)->hour < 17 ? 'afternoon' : 'evening')).', '.auth()->user()->name"
                   :sub="'Here is what is happening in '.$workspace->name.' today.'">
        @can('manage-workspace')
            <a href="{{ route('settings.modules.index') }}" class="btn btn-white"><x-icon name="layout-grid" /> Add apps</a>
            <a href="{{ route('settings.members.index') }}" class="btn btn-primary"><x-icon name="user-plus" /> Invite team</a>
        @endcan
    </x-page-header>

    @if($subscription && $workspace->onTrial())
        <div class="alert alert-info d-flex align-items-center gap-3 shadow-z border-0">
            <x-icon name="sparkles" class="zi zi-lg" />
            <div class="flex-grow-1">
                <strong>Your {{ $plan?->name }} trial ends in {{ $subscription->daysLeftInTrial() }} {{ \Illuminate\Support\Str::plural('day', $subscription->daysLeftInTrial()) }}.</strong>
                Enjoy every app while you decide. No card needed until then.
            </div>
            @can('manage-workspace')<a href="{{ route('settings.billing.index') }}" class="btn btn-sm btn-primary">Manage plan</a>@endcan
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat label="Active apps" :value="$enabledModules->count()" icon="layout-grid" color="primary" :href="route('settings.modules.index')" :delta="$readyModules->count().' ready to use'" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat label="Team members" :value="$memberCount" icon="users" color="info" :href="route('settings.members.index')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat label="Branches" :value="$branchCount" icon="map-pin" color="success" :href="route('settings.branches.index')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat label="Plan" :value="$plan?->name ?? 'None'" icon="credit-card" color="purple" :href="route('settings.billing.index')" :delta="$subscription ? ucfirst($subscription->status) : 'Choose a plan'" /></div>
    </div>

    @if($widgets->isNotEmpty())
        <div class="row g-3 mb-4">
            @foreach($widgets as $widget)
                <div class="col-lg-{{ $widget['width'] }}">
                    {!! $widgetRegistry->render($widget) !!}
                </div>
            @endforeach
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title">Your apps</h5>
                    @can('manage-workspace')<a href="{{ route('settings.modules.index') }}" class="btn btn-sm btn-soft-primary">Manage</a>@endcan
                </div>
                <div class="card-body">
                    @if($enabledModules->isEmpty())
                        <x-empty icon="layout-grid" title="No apps enabled yet" text="Browse the marketplace and switch on what your business needs.">
                            @can('manage-workspace')<a href="{{ route('settings.modules.index') }}" class="btn btn-primary">Browse apps</a>@endcan
                        </x-empty>
                    @else
                        <div class="row g-3">
                            @foreach($enabledModules as $m)
                                <div class="col-sm-6 col-xl-4">
                                    @php $entryUrl = app(\App\Registries\ModuleRegistry::class)->entryUrl($m->key); @endphp
                                    <{{ $entryUrl ? 'a' : 'div' }} @if($entryUrl) href="{{ $entryUrl }}" @endif class="d-flex gap-3 p-3 rounded-10 border h-100 text-reset text-decoration-none {{ $m->isAvailable() ? 'hover-raise bg-white' : 'bg-soft' }}">
                                        <span class="z-avatar z-avatar-soft rounded-3"><x-icon :name="$m->icon ?: ($m->suite?->icon ?? 'box')" /></span>
                                        <div class="mw-0">
                                            <div class="fw-600 text-truncate">{{ $m->name }}</div>
                                            <div class="fs-8 text-muted">{{ $m->suite?->name }}</div>
                                            @unless($m->isAvailable())<span class="z-pill z-pill-muted mt-1">Coming soon</span>@endunless
                                        </div>
                                    </{{ $entryUrl ? 'a' : 'div' }}>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Getting started</h5></div>
                <div class="card-body z-kpi-list">
                    @php
                        $checks = [
                            ['Create your workspace', true, route('settings.workspace.edit')],
                            ['Choose your apps', $enabledModules->isNotEmpty(), route('settings.modules.index')],
                            ['Add your logo & details', (bool) $workspace->logo_path, route('settings.workspace.edit')],
                            ['Invite a team member', $memberCount > 1, route('settings.members.index')],
                            ['Add a second branch', $branchCount > 1, route('settings.branches.index')],
                            ['Secure your account with 2FA', (bool) auth()->user()->two_factor_confirmed_at, route('profile.edit')],
                        ];
                    @endphp
                    @foreach($checks as [$label, $done, $url])
                        <div class="z-kpi">
                            <a href="{{ $url }}" class="d-flex align-items-center gap-2 text-decoration-none {{ $done ? 'text-muted text-decoration-line-through' : 'text-dark' }}">
                                <span class="z-avatar z-avatar-sm {{ $done ? 'z-bg-success' : 'z-avatar-soft' }}"><x-icon :name="$done ? 'check' : 'circle'" class="zi zi-sm" /></span>
                                {{ $label }}
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title">Recent activity</h5></div>
                <div class="card-body">
                    @if($recentActivity->isEmpty())
                        <p class="text-muted fs-7 mb-0">Activity from your apps will show up here.</p>
                    @else
                        <div class="z-timeline">
                            @foreach($recentActivity as $a)
                                <div class="z-timeline-item">
                                    <div class="fs-7">{{ $a->description }}</div>
                                    <div class="z-timeline-time">{{ $a->created_at->diffForHumans() }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
