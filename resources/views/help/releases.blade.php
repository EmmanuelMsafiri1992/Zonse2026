@extends('layouts.app')
@section('title', "What's new")
@section('content')
    <x-page-header title="What's new" sub="New features and improvements, newest first." :crumbs="['Help centre' => route('help.index'), 'What\'s new']" />

    <div class="row"><div class="col-lg-8">
        @forelse($releases as $release)
            <div class="card mb-4" id="v{{ Str::slug($release->version) }}">
                <div class="card-header d-flex align-items-center gap-2">
                    <span class="z-pill z-pill-info">{{ $release->version }}</span>
                    <h5 class="card-title mb-0 flex-grow-1">{{ $release->title }}</h5>
                    @if($release->isNewSince($seenBefore))<span class="z-pill z-pill-sent" data-new-release>New</span>@endif
                    <span class="fs-8 text-muted">{{ $release->date->format('j M Y') }}</span>
                </div>
                <div class="card-body z-prose">{!! $release->html() !!}</div>
            </div>
        @empty
            <div class="card"><div class="card-body"><x-empty icon="sparkles" title="No updates yet" /></div></div>
        @endforelse
    </div></div>
@endsection
