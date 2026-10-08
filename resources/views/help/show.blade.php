@extends('layouts.app')
@section('title', $article->title)
@section('content')
    <x-page-header :title="$article->title" :sub="$article->summary" :crumbs="['Help centre' => route('help.index'), $category['label'] => route('help.index').'#topic-'.$article->category, $article->title]" />

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-body">
                    <div class="fs-8 text-muted mb-3"><x-icon name="clock" class="zi zi-sm" /> {{ $article->minutes() }} min read</div>
                    <div class="z-prose">{!! $article->html() !!}</div>
                </div>
            </div>

            <div class="card mb-4" id="feedback">
                <div class="card-body" x-data="{ helpful: @js($feedback?->helpful) }">
                    <form method="POST" action="{{ route('help.feedback', $article->slug) }}">
                        @csrf
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="fw-600 me-2">Was this guide helpful?</span>
                            <label class="btn btn-sm" :class="helpful === true ? 'btn-primary' : 'btn-white'">
                                <input type="radio" name="helpful" value="1" class="d-none" @change="helpful = true" @checked($feedback?->helpful === true)> <x-icon name="thumbs-up" /> Yes
                            </label>
                            <label class="btn btn-sm" :class="helpful === false ? 'btn-primary' : 'btn-white'">
                                <input type="radio" name="helpful" value="0" class="d-none" @change="helpful = false" @checked($feedback?->helpful === false)> <x-icon name="thumbs-down" /> No
                            </label>
                            @if($feedback)<span class="fs-8 text-muted">You answered {{ $feedback->updated_at->diffForHumans() }}.</span>@endif
                        </div>
                        @error('helpful')<div class="text-danger fs-7 mt-2">{{ $message }}</div>@enderror
                        <div class="mt-3" x-show="helpful !== null" x-cloak>
                            <label for="f_comment" class="form-label fs-7" x-text="helpful ? 'Anything we could add? (optional)' : 'What were you looking for? (optional)'"></label>
                            <textarea name="comment" id="f_comment" rows="2" maxlength="1000" class="form-control @error('comment') is-invalid @enderror">{{ old('comment', $feedback?->comment) }}</textarea>
                            @error('comment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <button type="submit" class="btn btn-sm btn-primary mt-2">Send</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="d-flex gap-2">
                @if($previous)<a href="{{ route('help.show', $previous->slug) }}" class="btn btn-white"><x-icon name="arrow-left" /> {{ $previous->title }}</a>@endif
                @if($next)<a href="{{ route('help.show', $next->slug) }}" class="btn btn-white ms-auto">{{ $next->title }} <x-icon name="arrow-right" /></a>@endif
            </div>
        </div>
        <div class="col-lg-4">
            <form method="GET" action="{{ route('help.index') }}" class="mb-4" role="search">
                <input type="search" name="q" class="form-control" placeholder="Search the guides" aria-label="Search the guides">
            </form>
            @if($related->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0"><x-icon :name="$category['icon']" /> More in {{ $category['label'] }}</h5></div>
                    <div class="card-body z-help-list py-2">
                        @foreach($related as $item)
                            <a href="{{ route('help.show', $item->slug) }}"><x-icon name="book-open" class="zi zi-sm text-muted flex-shrink-0 mt-1" /><span>{{ $item->title }}</span></a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
