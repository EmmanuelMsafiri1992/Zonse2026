@extends('layouts.public')
@section('content')
    <div class="text-center mb-4">
        <h1 class="h3 mb-1">{{ $settings['headline'] ?? $publicWorkspace->name }}</h1>
        @if($settings['bio'])<p class="text-muted mb-0" style="white-space:pre-line">{{ $settings['bio'] }}</p>@endif
        @if($publicWorkspace->city)<div class="fs-8 text-muted mt-1"><x-icon name="map-pin" class="zi zi-sm" /> {{ $publicWorkspace->city }}</div>@endif
    </div>

    <div class="d-grid gap-2 mx-auto" style="max-width: 460px">
        @foreach($blocks as $key => $block)
            <a href="{{ route('public.'.$key, $publicWorkspace) }}" class="btn btn-primary btn-lg d-flex align-items-center justify-content-center gap-2"><x-icon :name="$block['icon']" /> {{ $block['label'] }}</a>
        @endforeach
        @foreach($settings['links'] as $link)
            <a href="{{ $link['url'] }}" class="btn btn-white btn-lg" target="_blank" rel="noopener nofollow">{{ $link['label'] }}</a>
        @endforeach
        @if($publicWorkspace->phone)
            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $publicWorkspace->phone) }}" class="btn btn-white btn-lg d-flex align-items-center justify-content-center gap-2"><x-icon name="phone" /> Call {{ $publicWorkspace->phone }}</a>
        @endif
        @if($publicWorkspace->email)
            <a href="mailto:{{ $publicWorkspace->email }}" class="btn btn-white btn-lg d-flex align-items-center justify-content-center gap-2"><x-icon name="mail" /> Email us</a>
        @endif
        <a href="{{ route('portal.login', $publicWorkspace) }}" class="btn btn-link fs-7 text-muted">Already a client? Sign in to your portal</a>
    </div>
@endsection
