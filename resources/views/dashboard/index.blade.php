@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
    <x-page-header :title="'Good '.(now($workspace->timezone)->hour < 12 ? 'morning' : (now($workspace->timezone)->hour < 17 ? 'afternoon' : 'evening')).', '.auth()->user()->name"
                   :sub="'Here is what is happening in '.$workspace->name.' today.'">
        @can('manage-workspace')
            <a href="{{ route('settings.modules.index') }}" class="btn btn-white" data-tour="add-apps"><x-icon name="layout-grid" /> Add apps</a>
            <a href="{{ route('settings.members.index') }}" class="btn btn-primary"><x-icon name="user-plus" /> Invite team</a>
        @endcan
    </x-page-header>

    @php
        $planDelta = match (true) {
            ! $subscription => 'Choose a plan',
            $workspace->onTrial() => 'Trial, '.$subscription->daysLeftInTrial().' '.\Illuminate\Support\Str::plural('day', $subscription->daysLeftInTrial()).' left',
            default => ucfirst($subscription->status),
        };
    @endphp
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat label="Active apps" :value="$enabledModules->count()" icon="layout-grid" color="primary" :href="route('settings.modules.index')" :delta="$readyModules->count().' ready to use'" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat label="Team members" :value="$memberCount" icon="users" color="info" :href="route('settings.members.index')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat label="Branches" :value="$branchCount" icon="map-pin" color="success" :href="route('settings.branches.index')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat label="Plan" :value="$plan?->name ?? 'None'" icon="credit-card" color="purple" :href="route('settings.billing.index')" :delta="$planDelta" /></div>
    </div>

    <div class="z-dash-section">
        <div class="z-dash-section-head">
            <h2>Your apps</h2>
            @can('manage-workspace')<a href="{{ route('settings.modules.index') }}">Manage apps</a>@endcan
        </div>
        @if($enabledModules->isEmpty())
            <div class="card"><div class="card-body">
                <x-empty icon="layout-grid" title="No apps enabled yet" text="Browse the marketplace and switch on what your business needs.">
                    @can('manage-workspace')<a href="{{ route('settings.modules.index') }}" class="btn btn-primary">Browse apps</a>@endcan
                </x-empty>
            </div></div>
        @else
            @php $registry = app(\App\Registries\ModuleRegistry::class); @endphp
            @if($pinnedModules->isNotEmpty())
                <div class="z-dash-apps">
                    @foreach($pinnedModules as $m)
                        @php $entryUrl = $registry->entryUrl($m->key); @endphp
                        <a href="{{ $entryUrl ?: route('settings.modules.index') }}" class="z-dash-app">
                            <span class="z-dash-app-icon">{!! \App\Support\Icon::ref($m->icon ?: ($m->suite?->icon ?? 'box')) !!}</span>
                            <div>
                                <b>{{ $m->name }}</b>
                                <small>{{ $m->suite?->name }}</small>
                                @if($m->description)<p>{{ \Illuminate\Support\Str::limit($m->description, 90) }}</p>@endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
            @if($otherModules->isNotEmpty())
                <div class="z-dash-more">
                    @foreach($otherModules as $m)
                        @php $entryUrl = $m->isAvailable() ? $registry->entryUrl($m->key) : null; @endphp
                        @if($entryUrl)
                            <a href="{{ $entryUrl }}">{!! \App\Support\Icon::ref($m->icon ?: ($m->suite?->icon ?? 'box')) !!} {{ $m->name }}</a>
                        @else
                            <span>{!! \App\Support\Icon::ref($m->icon ?: ($m->suite?->icon ?? 'box')) !!} {{ $m->name }}@unless($m->isAvailable()) <em class="text-muted">coming soon</em>@endunless</span>
                        @endif
                    @endforeach
                </div>
            @endif
            {!! \App\Support\Icon::sprite() !!}
        @endif
    </div>

    @if($widgets->isNotEmpty())
        <div class="z-dash-section">
            <div class="z-dash-section-head"><h2>At a glance</h2></div>
            <div class="row g-3">
                @foreach($widgets as $widget)
                    <div class="col-lg-{{ $widget['width'] }}">
                        {!! $widgetRegistry->render($widget) !!}
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="z-dash-section">
                <div class="z-dash-section-head"><h2>Getting started</h2></div>
                <div class="card"><div class="card-body z-kpi-list">
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
                </div></div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="z-dash-section">
                <div class="z-dash-section-head"><h2>Recent activity</h2></div>
                <div class="card"><div class="card-body">
                    @if($recentActivity->isEmpty())
                        <p class="text-muted mb-0">Activity from your apps will show up here.</p>
                    @else
                        <div class="z-timeline">
                            @foreach($recentActivity as $a)
                                <div class="z-timeline-item">
                                    <div>{{ $a->description }}</div>
                                    <div class="z-timeline-time">{{ $a->created_at->diffForHumans() }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div></div>
            </div>
        </div>
    </div>
@endsection
