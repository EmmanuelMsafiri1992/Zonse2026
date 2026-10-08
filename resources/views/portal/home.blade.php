@extends('layouts.portal')
@section('title', 'Overview')
@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Hello, {{ $portalAccess->contact->displayName() }}</h1>
        <p class="text-muted mb-0">{{ $welcome ?? 'Everything you have with '.$portalWorkspace->name.', in one place.' }}</p>
    </div>

    @if(empty($counts))
        <div class="card"><div class="card-body"><x-empty icon="door-open" title="Nothing to show yet" text="{{ $portalWorkspace->name }} has not shared anything in the portal yet." class="py-2" /></div></div>
    @else
        <div class="row g-3">
            @foreach($counts as $key => $count)
                <div class="col-sm-6 col-lg-4">
                    <x-stat :label="$portalSections[$key]['label']" :value="$count['value']" :icon="$portalSections[$key]['icon']" :delta="$count['hint']" :href="route('portal.'.$key, $portalWorkspace)" />
                </div>
            @endforeach
        </div>
    @endif
@endsection
