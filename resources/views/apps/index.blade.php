@extends('layouts.app')
@section('title', $contact ? 'Records for '.$contact->displayName() : 'Apps')
@section('content')
    <x-page-header :title="$contact ? 'Records for '.$contact->displayName() : 'Your apps'"
                   :sub="$contact ? 'Everything linked to this contact across your enabled apps.' : 'Every industry app switched on for this workspace.'"
                   :crumbs="$contact ? ['Apps' => route('apps.index'), $contact->displayName()] : ['Apps']">
        @can('manage-workspace')
            <a href="{{ route('settings.modules.index') }}" class="btn btn-white"><x-icon name="layout-grid" /> Add apps</a>
        @endcan
    </x-page-header>

    @if($contact)
        <div class="card">
            @if($contactRecords->isEmpty())
                <div class="card-body"><x-empty icon="link" title="No linked records" text="Nothing in your apps points at this contact yet." /></div>
            @else
                <div class="list-group list-group-flush">
                    @foreach($contactRecords as $record)
                        <a href="{{ $record->url() }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                            <span class="mw-0 flex-grow-1">
                                <span class="d-block fw-600 fs-7 text-truncate">{{ $record->title }}</span>
                                <span class="d-block fs-8 text-muted">{{ $record->number }} · {{ $record->blueprintDefinition()->name }} · {{ $record->definition()->label }}</span>
                            </span>
                            <x-pill :status="$record->statusTone()">{{ $record->statusLabel() }}</x-pill>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @elseif($apps->isEmpty())
        <div class="card"><div class="card-body">
            <x-empty icon="layout-grid" title="No industry apps yet" text="Switch on apps like Clinic, Farm, Gym or Rentals from the module marketplace.">
                @can('manage-workspace')
                    <a href="{{ route('settings.modules.index') }}" class="btn btn-primary"><x-icon name="plus" /> Browse apps</a>
                @endcan
            </x-empty>
        </div></div>
    @else
        <div class="row g-3">
            @foreach($apps as $app)
                <div class="col-md-6 col-xl-4">
                    <a href="{{ route('apps.show', $app->key) }}" class="card h-100 text-reset text-decoration-none hover-raise">
                        <div class="card-body d-flex gap-3">
                            <span class="z-avatar z-avatar-soft"><x-icon :name="$app->icon" /></span>
                            <span class="mw-0">
                                <span class="d-block fw-600">{{ $app->name }}</span>
                                <span class="d-block fs-8 text-muted mb-2">{{ $app->description }}</span>
                                <span class="fs-8 text-muted">{{ number_format($counts[$app->key] ?? 0) }} {{ \Illuminate\Support\Str::plural('record', $counts[$app->key] ?? 0) }} · {{ collect($app->entities)->pluck('plural')->implode(', ') }}</span>
                            </span>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
@endsection
