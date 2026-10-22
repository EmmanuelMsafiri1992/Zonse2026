@extends('layouts.app')
@section('title', 'Clients')
@section('content')
    <x-page-header title="Clients" sub="Everyone who has signed up to Zonseob, newest first, with the workspace they set up and where its plan stands." />

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat label="Signed up" :value="$totals['all']" icon="users" color="primary" :href="route('admin.clients.index')" :delta="$totals['today'].' today'" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat label="New this week" :value="$totals['new']" icon="user-plus" color="success" :href="route('admin.clients.index', ['filter' => 'new'])" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat label="On trial" :value="$totals['trial']" icon="hourglass" color="warning" :href="route('admin.clients.index', ['filter' => 'trial'])" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat label="Paying" :value="$totals['paying']" icon="banknote" color="info" :href="route('admin.clients.index', ['filter' => 'paying'])" /></div>
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center flex-wrap gap-2">
            <form method="GET" action="{{ route('admin.clients.index') }}" class="d-flex gap-2">
                @if($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
                <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Name, email, phone or business" aria-label="Search clients">
                <button class="btn btn-sm btn-white">Search</button>
            </form>
            <div class="btn-group ms-auto">
                <a href="{{ route('admin.clients.index', array_filter(['q' => $search])) }}" class="btn btn-sm {{ $filter ? 'btn-white' : 'btn-primary' }}">All</a>
                @foreach(\App\Http\Controllers\Admin\ClientsController::FILTERS as $key => $label)
                    <a href="{{ route('admin.clients.index', array_filter(['filter' => $key, 'q' => $search])) }}" class="btn btn-sm {{ $filter === $key ? 'btn-primary' : 'btn-white' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
        <div class="z-table-wrap">
            <table class="table z-table">
                <thead><tr><th>Signed up</th><th>Person</th><th>Business</th><th>Plan</th><th>Team</th><th>Last seen</th><th></th></tr></thead>
                <tbody>
                @forelse($clients as $client)
                    @php($workspace = $client->ownedWorkspaces->sortByDesc('created_at')->first())
                    @php($access = $workspace?->access())
                    <tr data-client="{{ $client->id }}">
                        <td class="fs-7">{{ $client->created_at->format('d M Y, H:i') }}<div class="fs-8 text-muted">{{ $client->created_at->diffForHumans() }}</div></td>
                        <td>
                            <div class="z-row-title">{{ $client->name }}</div>
                            <div class="fs-8 text-muted">{{ $client->email }}@if(! $client->email_verified_at) · not verified @endif</div>
                            @if($client->phone)<div class="fs-8 text-muted">{{ $client->phone }}</div>@endif
                        </td>
                        <td>
                            @if($workspace)
                                <div>{{ $workspace->name }}</div>
                                <div class="fs-8 text-muted">
                                    {{ $workspace->isOnboarded() ? collect([$workspace->city, $workspace->country_code])->filter()->implode(', ') : 'Setup not finished (step '.$workspace->onboarding_step.')' }}
                                    @if($client->ownedWorkspaces->count() > 1) · {{ $client->ownedWorkspaces->count() - 1 }} more @endif
                                </div>
                            @else
                                <span class="text-muted fs-7">No workspace yet</span>
                            @endif
                        </td>
                        <td>
                            @if($workspace?->subscription)
                                <div>{{ $workspace->plan()?->name ?? '—' }}</div>
                                <div class="fs-8 text-muted">
                                    @switch($access->state)
                                        @case('trial') Trial, {{ $access->daysLeft() }} {{ \Illuminate\Support\Str::plural('day', $access->daysLeft()) }} left @break
                                        @case('grace') Lapsed, closes {{ $access->until->toFormattedDateString() }} @break
                                        @case('blocked') Paused, unpaid @break
                                        @default {{ $workspace->plan()?->isFree() ? 'Free' : 'Paid'.($workspace->subscription->current_period_end ? ' to '.$workspace->subscription->current_period_end->toFormattedDateString() : '') }}
                                    @endswitch
                                </div>
                            @else
                                <span class="text-muted fs-7">No plan</span>
                            @endif
                        </td>
                        <td class="fs-7">{{ $workspace ? $workspace->members_count.' '.\Illuminate\Support\Str::plural('person', $workspace->members_count) : '—' }}</td>
                        <td class="fs-7">{{ $client->last_seen_at?->diffForHumans() ?? '—' }}</td>
                        <td class="text-end">
                            @if($workspace)
                                <form method="POST" action="{{ route('workspaces.switch', $workspace) }}">@csrf<button class="btn btn-sm btn-white">Open</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><x-empty icon="users" title="No clients found" text="People appear here as soon as they sign up." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($clients->hasPages())<div class="card-footer">{{ $clients->links() }}</div>@endif
    </div>
@endsection
