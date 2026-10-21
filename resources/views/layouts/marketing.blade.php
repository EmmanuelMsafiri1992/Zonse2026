<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'One platform for every profession') · {{ config('app.name') }}</title>
    @include('partials.fonts')
    @include('partials.favicon')
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
<nav class="z-mk-nav sticky-top">
    <div class="container">
        <a class="z-mk-brand" href="{{ route('home') }}">
            <img src="{{ asset('images/logo-mark.png') }}" alt="" width="32" height="32">
            <span>{{ config('app.name') }}</span>
        </a>
        <div class="z-mk-links">
            <a href="{{ route('home') }}#apps">Apps</a>
            @if(! empty($aiPacks))<a href="{{ route('home') }}#zonseob-ai">Zonseob AI</a>@endif
            <a href="{{ route('pricing') }}">Pricing</a>
        </div>
        <div class="z-mk-actions">
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary">Open dashboard</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-link text-decoration-none">Sign in</a>
                <a href="{{ route('register') }}" class="btn btn-primary">Start free</a>
            @endauth
        </div>
    </div>
</nav>

@yield('content')

<footer class="z-mk-foot">
    <div class="container">
        <a class="z-mk-brand" href="{{ route('home') }}"><img src="{{ asset('images/logo-mark.png') }}" alt="" width="24" height="24"> {{ config('app.name') }}</a>
        <a href="{{ route('home') }}#apps">Apps</a>
        <a href="{{ route('pricing') }}">Pricing</a>
        @if(! empty($aiPacks))<a href="{{ route('home') }}#zonseob-ai">Zonseob AI</a>@endif
        <a href="{{ route('login') }}">Sign in</a>
        <a href="{{ route('privacy') }}">Privacy</a>
        <span class="ms-auto">&copy; {{ date('Y') }} {{ config('app.name') }}. One platform, every profession.</span>
    </div>
</footer>
@include('partials.landing-chat')
</body>
</html>
