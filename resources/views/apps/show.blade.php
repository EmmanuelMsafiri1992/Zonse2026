@extends('layouts.app')
@section('title', $app->name)
@section('content')
    <x-page-header :title="$app->name" :sub="$app->description" :crumbs="['Apps' => route('apps.index'), $app->name]">
        @if($app->key === 'pos')
            <a href="{{ route('apps.pos.till') }}" class="btn btn-success"><x-icon name="shopping-cart" /> Open till</a>
        @endif
        <a href="{{ route('apps.reports', $app->key) }}" class="btn btn-white"><x-icon name="chart-column" /> Reports</a>
        @can('create', \App\Models\Record::class)
            @php $primary = $app->primaryEntity(); @endphp
            <a href="{{ route('apps.records.create', [$app->key, $primary->key]) }}" class="btn btn-primary"><x-icon name="plus" /> New {{ strtolower($primary->label) }}</a>
        @endcan
    </x-page-header>

    <div class="row g-3 mb-4">
        @foreach($entities as $entity)
            <div class="col-6 col-xl-3">
                <x-stat :label="$entity['def']->plural" :value="number_format($entity['count'])" :icon="$entity['def']->icon"
                        :href="route('apps.records.index', [$app->key, $entity['def']->key])" />
            </div>
        @endforeach
    </div>

    @if($cards)
        <div class="row g-3 mb-4">
            @foreach($cards as $card)
                <div class="col-lg-6 d-flex flex-column">@include($card['view'], $card['data'])</div>
            @endforeach
        </div>
    @endif

    <div class="row g-3">
        @foreach($entities as $entity)
            @php $def = $entity['def']; @endphp
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title"><x-icon :name="$def->icon" class="zi me-1" /> {{ $def->plural }}</h5>
                        <div class="d-flex gap-1">
                            @can('create', \App\Models\Record::class)
                                <a href="{{ route('apps.records.create', [$app->key, $def->key]) }}" class="btn btn-sm btn-primary"><x-icon name="plus" class="zi zi-sm" /> {{ $def->label }}</a>
                            @endcan
                            <a href="{{ route('apps.records.index', [$app->key, $def->key]) }}" class="btn btn-sm btn-soft-primary">All</a>
                        </div>
                    </div>
                    @if($entity['byStatus']->isNotEmpty() || $entity['amount'] !== null || $entity['dueSoon'] !== null)
                        <div class="card-body pb-0 d-flex flex-wrap gap-2">
                            @foreach($def->statuses as $status => $label)
                                @if($entity['byStatus'][$status] ?? 0)
                                    <a href="{{ route('apps.records.index', [$app->key, $def->key, 'status' => $status]) }}" class="text-decoration-none">
                                        <x-pill :status="$def->statusTone($status)">{{ $label }} · {{ $entity['byStatus'][$status] }}</x-pill>
                                    </a>
                                @endif
                            @endforeach
                            @if($entity['amount'])
                                <span class="fs-8 text-muted ms-auto">{{ $def->amountLabel }}: <strong class="text-body">{{ \App\Support\Money::format($entity['amount']) }}</strong></span>
                            @endif
                            @if($entity['dueSoon'])
                                <span class="fs-8 text-warning">{{ $entity['dueSoon'] }} {{ strtolower($def->dueLabel) }} in the next 7 days</span>
                            @endif
                        </div>
                    @endif
                    @if($entity['recent']->isEmpty())
                        <div class="card-body"><x-empty :icon="$def->icon" :title="'No '.strtolower($def->plural).' yet'" class="py-2" /></div>
                    @else
                        <div class="list-group list-group-flush mt-3">
                            @foreach($entity['recent'] as $record)
                                <a href="{{ $record->url() }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                                    <span class="mw-0 flex-grow-1">
                                        <span class="d-block fw-600 fs-7 text-truncate">{{ $record->title }}</span>
                                        <span class="d-block fs-8 text-muted text-truncate">{{ $record->number }}{{ $record->contact ? ' · '.$record->contact->displayName() : '' }} · {{ $record->created_at?->diffForHumans() }}</span>
                                    </span>
                                    <x-pill :status="$record->statusTone()">{{ $record->statusLabel() }}</x-pill>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endsection
