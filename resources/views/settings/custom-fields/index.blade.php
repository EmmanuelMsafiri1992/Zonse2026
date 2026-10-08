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
                    @if($list->isEmpty())
                        <div class="card-body text-muted fs-7">No extra fields on {{ mb_strtolower($definition['label']) }} yet.</div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($list as $field)
                                <div class="list-group-item d-flex gap-2 align-items-center">
                                    <x-icon :name="\App\Models\CustomField::TYPES[$field->type]['icon'] ?? 'type'" class="zi text-muted" />
                                    <div class="flex-grow-1 min-w-0">
                                        <a href="{{ route('settings.custom-fields.edit', $field) }}" class="fw-600 text-body">{{ $field->label }}</a>
                                        @if($field->is_required)<span class="text-danger" title="Required">*</span>@endif
                                        <div class="fs-8 text-muted text-truncate">{{ $field->typeLabel() }}@if($field->type === 'select') · {{ implode(', ', $field->options ?? []) }}@endif</div>
                                    </div>
                                    <form method="POST" action="{{ route('settings.custom-fields.move', $field) }}" class="d-flex gap-1">
                                        @csrf
                                        <button name="direction" value="up" class="btn btn-sm btn-white" title="Move up" @disabled($loop->first)><x-icon name="arrow-up" /></button>
                                        <button name="direction" value="down" class="btn btn-sm btn-white" title="Move down" @disabled($loop->last)><x-icon name="arrow-down" /></button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endsection
