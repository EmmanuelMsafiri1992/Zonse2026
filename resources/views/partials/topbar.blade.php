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

        <button type="button" class="z-icon-btn" aria-label="Notifications">
            <x-icon name="bell" class="zi zi-lg" />
        </button>

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
