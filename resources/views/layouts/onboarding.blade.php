<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Set up') · {{ config('app.name') }}</title>
    @include('partials.fonts')
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
<div class="container py-4 py-lg-5" style="max-width: 1040px">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <a href="{{ route('home') }}" class="d-inline-flex align-items-center gap-2 text-decoration-none font-heading fw-600 fs-5 text-dark">
            <span class="rounded-3 bg-primary text-white fw-bold" style="width:36px;height:36px;display:grid;place-items:center">Z</span>
            {{ config('app.name') }}
        </a>
        <div class="d-flex align-items-center gap-3 fs-7 text-muted">
            <span>{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">@csrf <button class="btn btn-sm btn-white">Sign out</button></form>
        </div>
    </div>

    <div class="z-steps">
        @foreach($steps as $n => $s)
            @php $state = $n < $step ? 'done' : ($n === $step ? 'active' : ''); @endphp
            <div class="z-step {{ $state }}">
                <span class="z-step-num">@if($n < $step)<x-icon name="check" class="zi zi-sm" />@else{{ $n }}@endif</span>
                <span class="d-none d-sm-inline">{{ $s['title'] }}</span>
            </div>
            @if(! $loop->last)<div class="z-step-line"></div>@endif
        @endforeach
    </div>

    @include('partials.flash')
    @yield('content')
</div>
</body>
</html>
