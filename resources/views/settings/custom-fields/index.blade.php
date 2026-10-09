@extends('layouts.app')
@section('title', 'Custom fields')
@section('content')
    <x-page-header title="Custom fields" sub="Add the details your business needs to keep, such as a medical aid number on contacts or a vehicle registration on tickets." :crumbs="['Settings' => route('settings.workspace.edit'), 'Custom fields']">
        <a href="{{ route('settings.custom-fields.create') }}" class="btn btn-primary"><x-icon name="plus" /> New field</a>
    </x-page-header>

    <div class="row g-3">
        @foreach($entities as $entity => $definition)
            @php $list = $fields->get($entity, collect()); @endphp
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">{{ $definition['label'] }} <span class="text-muted fw-normal fs-7">({{ $list->count() }})</span></h5>
                        <a href="{{ route('settings.custom-fields.create', ['entity' => $entity]) }}" class="btn btn-sm btn-white"><x-icon name="plus" /> Field</a>
                    </div>
                    @unless($enabled[$entity])
                        <div class="alert alert-warning fs-8 py-1 mx-3 mt-3 mb-0">The {{ $definition['label'] }} app is off in this workspace, so these fields are not in use.</div>
                    @endunless
                    @include('settings.custom-fields.partials.list', ['list' => $list, 'label' => $definition['label']])
                </div>
            </div>
        @endforeach
    </div>

    @if($apps !== [])
        <h5 class="mt-4 mb-1">Your apps</h5>
        <p class="text-muted fs-7 mb-3">Each app's records can carry their own extra fields too.</p>
        <div class="row g-3">
            @foreach($apps as $appName => $appEntities)
                <div class="col-xl-6">
                    <div class="card h-100">
                        <div class="card-header"><h5 class="card-title mb-0">{{ $appName }}</h5></div>
                        @foreach($appEntities as $entity => $plural)
                            @php $list = $fields->get($entity, collect()); @endphp
                            <div class="d-flex justify-content-between align-items-center px-3 pt-3 pb-1 {{ $loop->first ? '' : 'border-top' }}">
                                <span class="fw-600 fs-7">{{ $plural }} <span class="text-muted fw-normal">({{ $list->count() }})</span></span>
                                <a href="{{ route('settings.custom-fields.create', ['entity' => $entity]) }}" class="btn btn-sm btn-white"><x-icon name="plus" /> Field</a>
                            </div>
                            @include('settings.custom-fields.partials.list', ['list' => $list, 'label' => $plural])
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
