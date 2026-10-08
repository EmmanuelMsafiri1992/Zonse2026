<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'One platform for every profession') · {{ config('app.name') }}</title>
    @include('partials.fonts')
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
    <div class="container">
        <a class="navbar-brand d-inline-flex align-items-center gap-2 font-heading fw-600" href="{{ route('home') }}">
            <span class="rounded-3 bg-primary text-white fw-bold" style="width:34px;height:34px;display:grid;place-items:center">Z</span>
            {{ config('app.name') }}
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('pricing') }}" class="btn btn-link text-decoration-none d-none d-sm-inline-flex">Pricing</a>
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary">Open dashboard</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-white">Sign in</a>
                <a href="{{ route('register') }}" class="btn btn-primary">Start free</a>
            @endauth
        </div>
    </div>
</nav>

@yield('content')

<footer class="py-4 border-top bg-white">
    <div class="container d-flex flex-wrap justify-content-between fs-7 text-muted">
        <span>&copy; {{ date('Y') }} {{ config('app.name') }}</span>
        <span>Built for every profession.</span>
    </div>
</footer>
</body>
</html>
