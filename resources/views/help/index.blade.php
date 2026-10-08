@extends('layouts.app')
@section('title', 'Help centre')
@section('content')
    <div class="z-help-hero">
        <h1 class="mb-1">How can we help?</h1>
        <p class="mb-3 opacity-75">Short guides for every part of your workspace.</p>
        <form method="GET" action="{{ route('help.index') }}" role="search" class="z-help-search">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ $query }}" class="form-control" placeholder="Search the guides, e.g. send an invoice" aria-label="Search the guides">
        </form>
    </div>

    @if($results !== null)
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center">
                <h5 class="card-title mb-0 flex-grow-1">{{ $results->count() }} {{ Str::plural('guide', $results->count()) }} for “{{ $query }}”</h5>
                <a href="{{ route('help.index') }}" class="fs-7">Clear search</a>
            </div>
            <div class="card-body">
                @forelse($results as $article)
                    <div class="z-help-list">
                        <a href="{{ route('help.show', $article->slug) }}">
                            <x-icon name="book-open" class="zi text-primary flex-shrink-0 mt-1" />
                            <span><span class="d-block fw-600">{{ $article->title }}</span><span class="d-block fs-7 text-muted">{{ $article->summary }}</span></span>
                        </a>
                    </div>
                @empty
                    <x-empty icon="circle-help" title="No guides match that" text="Try fewer or different words, or browse the topics below." />
                @endforelse
            </div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="row g-4">
                @foreach($categories as $key => $category)
                    <div class="col-md-6">
                        <div class="card h-100" id="topic-{{ $key }}">
                            <div class="card-header"><h5 class="card-title mb-0"><x-icon :name="$category['icon']" /> {{ $category['label'] }}</h5></div>
                            <div class="card-body z-help-list py-2">
                                @foreach($category['articles'] as $article)
                                    <a href="{{ route('help.show', $article->slug) }}">
                                        <x-icon name="book-open" class="zi zi-sm text-muted flex-shrink-0 mt-1" />
                                        <span>{{ $article->title }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="col-lg-4">
            @if($latestRelease)
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center">
                        <h5 class="card-title mb-0 flex-grow-1"><x-icon name="sparkles" /> What's new</h5>
                        @if($unreadReleases)<span class="z-pill z-pill-info">{{ $unreadReleases }} new</span>@endif
                    </div>
                    <div class="card-body">
                        <div class="fs-8 text-muted">{{ $latestRelease->version }} · {{ $latestRelease->date->format('j M Y') }}</div>
                        <div class="fw-600 mb-2">{{ $latestRelease->title }}</div>
                        <a href="{{ route('help.releases') }}" class="btn btn-sm btn-white">See all updates</a>
                    </div>
                </div>
            @endif
            @if($tours->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0"><x-icon name="map" /> Guided tours</h5></div>
                    <div class="card-body">
                        <p class="fs-7 text-muted">A quick walk around a page, one step at a time.</p>
                        @foreach($tours as $tour)
                            <form method="POST" action="{{ route('help.tours.start', $tour['key']) }}" class="d-flex align-items-center gap-2 py-1">
                                @csrf
                                <span class="flex-grow-1">{{ $tour['title'] }}</span>
                                @if(isset($doneTours[$tour['key']]))<x-icon name="check" class="zi zi-sm text-success" />@endif
                                <button type="submit" class="btn btn-sm btn-white">{{ isset($doneTours[$tour['key']]) ? 'Take again' : 'Start' }}</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
