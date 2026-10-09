@php $user = auth()->user(); $myWorkspaces = $user->workspaces()->orderBy('name')->get(); @endphp
<header class="z-topbar">
    <button type="button" class="z-icon-btn" onclick="zonseo.toggleSidebar()" aria-label="Toggle menu">
        <x-icon name="menu" class="zi zi-lg" />
    </button>

    <form class="z-search d-none d-md-block" method="GET" action="{{ route('search') }}" role="search">
        <x-icon name="search" />
        <input type="search" name="q" id="z-global-search" class="form-control" placeholder="Search anything…" autocomplete="off" value="{{ request()->routeIs('search') ? request('q') : '' }}">
        <kbd>/</kbd>
    </form>

    <div class="ms-auto d-flex align-items-center gap-2">
        @if($workspace)
            <div class="dropdown">
                <a href="#" class="z-workspace-switch" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="z-ws-avatar">{{ $workspace->initials }}</span>
                    <span class="fw-600 fs-7 d-none d-sm-inline text-truncate" style="max-width:160px">{{ $workspace->name }}</span>
                    <x-icon name="chevron-down" class="zi zi-sm text-muted" />
                </a>
                <div class="dropdown-menu dropdown-menu-end shadow-z" style="min-width:260px">
                    <div class="dropdown-header">Your workspaces</div>
                    @foreach($myWorkspaces as $ws)
                        <form method="POST" action="{{ route('workspaces.switch', $ws) }}">
                            @csrf
                            <button type="submit" class="dropdown-item {{ $ws->id === $workspace->id ? 'active' : '' }}">
                                <span class="z-avatar z-avatar-sm z-avatar-soft rounded-2">{{ $ws->initials }}</span>
                                <span class="flex-grow-1 text-truncate">{{ $ws->name }}</span>
                                @if($ws->id === $workspace->id)<x-icon name="check" class="zi zi-sm" />@endif
                            </button>
                        </form>
                    @endforeach
                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#newWorkspaceModal"><x-icon name="plus" /> New workspace</a>
                </div>
            </div>
        @endif

        @php
            $helpCentre = app(\App\Support\Help\HelpCentre::class);
            $pageGuides = $helpCentre->forRoute(request()->route()?->getName(), $workspace);
            $pageTour = \App\Support\Help\Tours::forRoute(request()->route()?->getName(), $user, $workspace);
            $newReleases = $helpCentre->unreadReleases($user);
        @endphp
        <div class="dropdown" data-tour="help">
            <button type="button" class="z-icon-btn position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Help{{ $newReleases ? ' ('.$newReleases.' new updates)' : '' }}">
                <x-icon name="life-buoy" class="zi zi-lg" />
                @if($newReleases)<span class="z-badge-dot z-badge-dot-info">{{ $newReleases > 9 ? '9+' : $newReleases }}</span>@endif
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-z" style="min-width:280px">
                @if($pageGuides->isNotEmpty())
                    <div class="dropdown-header">Help with this page</div>
                    @foreach($pageGuides as $guide)
                        <a href="{{ route('help.show', $guide->slug) }}" class="dropdown-item text-wrap"><x-icon name="book-open" /> {{ $guide->title }}</a>
                    @endforeach
                    <div class="dropdown-divider"></div>
                @endif
                @if($pageTour)
                    <button type="button" class="dropdown-item" onclick="window.dispatchEvent(new CustomEvent('zonseo:tour'))"><x-icon name="map" /> Take the tour of this page</button>
                @endif
                <a href="{{ route('help.index') }}" class="dropdown-item"><x-icon name="circle-help" /> Help centre</a>
                <a href="{{ route('help.releases') }}" class="dropdown-item d-flex align-items-center"><x-icon name="sparkles" /> <span class="flex-grow-1">What's new</span>@if($newReleases)<span class="z-pill z-pill-info ms-2">{{ $newReleases }} new</span>@endif</a>
                @if($user->is_super_admin)
                    <a href="{{ route('help.feedback.index') }}" class="dropdown-item"><x-icon name="thumbs-up" /> Guide ratings</a>
                @endif
            </div>
        </div>
        @if($pageTour)
            @include('partials.tour', ['tour' => $pageTour, 'autoStart' => \App\Support\Help\Tours::isPending($pageTour['key'], $user)])
        @endif

        @if($workspace)
            @php
                $inbox =$user->notifications()->where('workspace_id', $workspace->id);
                $unreadAlerts = (clone $inbox)->whereNull('read_at')->count();
                $latestAlerts = (clone $inbox)->latest()->limit(6)->get();
            @endphp
            <div class="dropdown">
                <button type="button" class="z-icon-btn position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                        aria-label="Notifications{{ $unreadAlerts ? ' ('.$unreadAlerts.' unread)' : '' }}">
                    <x-icon name="bell" class="zi zi-lg" />
                    @if($unreadAlerts)
                        <span class="z-badge-dot" data-unread="{{ $unreadAlerts }}">{{ $unreadAlerts > 9 ? '9+' : $unreadAlerts }}</span>
                    @endif
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-z p-0 z-alerts">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                        <span class="fw-600">Notifications</span>
                        @if($unreadAlerts)
                            <form method="POST" action="{{ route('notifications.read-all') }}">
                                @csrf
                                <button class="btn btn-link btn-sm p-0">Mark all read</button>
                            </form>
                        @endif
                    </div>
                    @forelse($latestAlerts as $alert)
                        <a href="{{ route('notifications.open', $alert->id) }}" data-no-prefetch class="dropdown-item z-alert {{ $alert->read_at ? '' : 'is-unread' }}">
                            <x-icon :name="$alert->data['icon'] ?? 'bell'" class="zi text-primary flex-shrink-0" />
                            <span class="flex-grow-1 min-w-0">
                                <span class="d-block text-wrap fs-7 {{ $alert->read_at ? '' : 'fw-600' }}">{{ $alert->data['title'] ?? '' }}</span>
                                <span class="d-block fs-8 text-muted">{{ $alert->created_at->diffForHumans() }}</span>
                            </span>
                        </a>
                    @empty
                        <div class="px-3 py-4 text-center fs-7 text-muted">You're all caught up.</div>
                    @endforelse
                    <a href="{{ route('notifications.index') }}" class="d-block text-center fs-7 py-2 border-top">See all notifications</a>
                </div>
            </div>
        @endif

        <div class="dropdown">
            <div class="z-user" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="text-end d-none d-md-block lh-sm">
                    <div class="fw-600 fs-7">{{ $user->name }}</div>
                    <div class="fs-8 text-muted text-capitalize">{{ $workspace ? ($user->roleIn($workspace) ?? 'member') : '' }}</div>
                </div>
                <span class="z-avatar">
                    @if($user->avatar_url)<img src="{{ $user->avatar_url }}" alt="">@else{{ $user->initials }}@endif
                </span>
            </div>
            <div class="dropdown-menu dropdown-menu-end shadow-z">
                <a href="{{ route('profile.edit') }}" class="dropdown-item"><x-icon name="user" /> My profile</a>
                @can('manage-workspace')
                    <a href="{{ route('settings.workspace.edit') }}" class="dropdown-item"><x-icon name="settings" /> Workspace settings</a>
                    <a href="{{ route('settings.billing.index') }}" class="dropdown-item"><x-icon name="credit-card" /> Plan &amp; billing</a>
                @endcan
                <div class="dropdown-divider"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger"><x-icon name="log-out" /> Sign out</button>
                </form>
            </div>
        </div>
    </div>
</header>
