<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Portal') · {{ $portalWorkspace->name }}</title>
    @include('partials.fonts')
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @if($brandCss = app(\App\Support\Branding::class)->css($brand['color']))<style>{!! $brandCss !!}</style>@endif
</head>
<body class="bg-body-tertiary">
@php $portalLogo = $portalWorkspace->logo_url ?? $brand['logo_url']; @endphp
<header class="bg-white border-bottom">
    <div class="container py-3 d-flex align-items-center gap-3 flex-wrap" style="max-width: 1040px">
        <a href="{{ isset($portalAccess) ? route('portal.home', $portalWorkspace) : route('portal.login', $portalWorkspace) }}" class="d-inline-flex align-items-center gap-2 text-decoration-none text-dark font-heading fw-600 fs-5 me-auto">
            @include('partials.brand-mark', ['brand' => ['logo_url' => $portalLogo, 'mark' => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($portalWorkspace->name, 0, 1))], 'class' => 'rounded-3 bg-primary text-white fw-bold'])
            {{ $portalWorkspace->name }}
        </a>
        @isset($portalAccess)
            <a href="{{ route('portal.profile', $portalWorkspace) }}" class="d-inline-flex align-items-center gap-1 fs-7 text-muted text-decoration-none"><x-icon name="user-round" class="zi zi-sm" /> {{ $portalAccess->contact->displayName() }}</a>
            <form method="POST" action="{{ route('portal.logout', $portalWorkspace) }}">@csrf <button class="btn btn-sm btn-white"><x-icon name="log-out" class="zi zi-sm" /> Sign out</button></form>
        @endisset
    </div>
    @isset($portalAccess)
        <nav class="container" style="max-width: 1040px" aria-label="Portal">
            <ul class="nav nav-underline gap-3 fs-7 flex-nowrap overflow-auto">
                <li class="nav-item"><a class="nav-link {{ request()->routeIs('portal.home') ? 'active' : '' }}" href="{{ route('portal.home', $portalWorkspace) }}">Overview</a></li>
                @foreach($portalSections as $key => $section)
                    <li class="nav-item text-nowrap"><a class="nav-link {{ request()->routeIs('portal.'.$key.'*') ? 'active' : '' }}" href="{{ route('portal.'.$key, $portalWorkspace) }}">{{ $section['label'] }}</a></li>
                @endforeach
            </ul>
        </nav>
    @endisset
</header>

<main class="container py-4" style="max-width: 1040px">
    @include('partials.flash')
    @yield('content')
</main>

<footer class="container pb-4 fs-8 text-muted text-center" style="max-width: 1040px">
    &copy; {{ date('Y') }} {{ $portalWorkspace->name }}@unless($brand['hide_powered_by']) · Powered by {{ config('app.name') }}@endunless
</footer>
</body>
</html>
