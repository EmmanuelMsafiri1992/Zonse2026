@extends('layouts.app')
@section('title', 'Search')
@section('content')
    <x-page-header title="Search" :sub="$q !== '' ? 'Results for “'.$q.'” across your apps.' : 'Type something in the search box to look across contacts, invoices, tickets, tasks and more.'" />

    <form method="GET" action="{{ route('search') }}" class="card card-flat mb-4">
        <div class="card-body d-flex gap-2">
            <input type="search" name="q" value="{{ $q }}" class="form-control" placeholder="Search anything…" autofocus>
            <button class="btn btn-primary"><x-icon name="search" /> Search</button>
        </div>
    </form>

    @if($q !== '' && $groups->isEmpty())
        <div class="card"><div class="card-body"><x-empty icon="search-x" title="No matches" text="Nothing matched that search. Try a name, number, email or phone." /></div></div>
    @endif

    <div class="row g-3">
        @foreach($groups as $group)
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h5 class="card-title"><x-icon :name="$group['icon']" class="zi me-1" /> {{ $group['label'] }} <span class="badge rounded-pill z-soft-primary ms-1">{{ $group['results']->count() }}</span></h5></div>
                    <div class="list-group list-group-flush">
                        @foreach($group['results'] as $r)
                            <a href="{{ $r['url'] }}" class="list-group-item list-group-item-action d-flex align-items-center gap-3">
                                <span class="z-avatar z-avatar-sm z-avatar-soft rounded-2"><x-icon :name="$r['icon'] ?? $group['icon']" class="zi zi-sm" /></span>
                                <span class="mw-0">
                                    <span class="d-block fw-600 text-truncate">{{ $r['title'] }}</span>
                                    @if(! empty($r['sub']))<span class="d-block fs-8 text-muted text-truncate">{{ $r['sub'] }}</span>@endif
                                </span>
                                @if(! empty($r['badge']))<x-pill :status="$r['badge']" class="ms-auto" />@endif
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
