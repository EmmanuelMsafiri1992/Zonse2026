@extends('layouts.app')
@section('title', 'Services')
@section('content')
    <x-page-header title="Services" sub="What customers can book, how long it takes and what it costs." :crumbs="['Appointments' => route('appointments.index'), 'Services']">
        @can('create', \Modules\Appointments\Models\Service::class)
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#serviceModal_new"><x-icon name="plus" /> New service</button>
        @endcan
    </x-page-header>

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                <div class="z-search flex-grow-1" style="max-width:320px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Search services…">
                </div>
                <select name="status" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="active" @selected($filters['status'] === 'active')>Active</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
                    <option value="all" @selected($filters['status'] === 'all')>All</option>
                </select>
                <button class="btn btn-soft-primary">Filter</button>
            </form>
            <span class="fs-8 text-muted">{{ $services->total() }} {{ \Illuminate\Support\Str::plural('service', $services->total()) }}</span>
        </div>

        @if($services->isEmpty())
            <div class="card-body">
                <x-empty icon="sparkles" title="No services yet" text="Add the things people book with you, for example a consultation, a haircut or a lesson.">
                    @can('create', \Modules\Appointments\Models\Service::class)
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#serviceModal_new"><x-icon name="plus" /> New service</button>
                    @endcan
                </x-empty>
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>Service</th><th>Duration</th><th class="text-end">Price</th><th>Bookings</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach($services as $service)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="rounded-circle flex-shrink-0" style="width:12px;height:12px;background:{{ $service->color }}"></span>
                                    <span class="mw-0">
                                        <span class="z-row-title d-block">{{ $service->name }}</span>
                                        @if($service->description)<span class="z-row-sub text-truncate d-block" style="max-width:420px">{{ $service->description }}</span>@endif
                                    </span>
                                </div>
                            </td>
                            <td class="fs-7">{{ $service->durationLabel() }}</td>
                            <td class="fs-7 text-end">{{ \App\Support\Money::format($service->price, $workspace->currency_code) }}</td>
                            <td class="fs-7">{{ $service->appointments_count }}</td>
                            <td><x-pill :status="$service->is_active ? 'active' : 'inactive'" /></td>
                            <td class="text-end">
                                <div class="z-row-actions d-inline-flex gap-1">
                                    @can('update', $service)
                                        <button type="button" class="btn btn-sm btn-icon btn-soft-secondary" title="Edit" data-bs-toggle="modal" data-bs-target="#serviceModal_{{ $service->id }}"><x-icon name="pencil" class="zi zi-sm" /></button>
                                    @endcan
                                    @can('delete', $service)
                                        <form method="POST" action="{{ route('services.destroy', $service) }}" onsubmit="return confirm('Delete {{ addslashes($service->name) }}?')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-icon btn-soft-danger" title="Delete"><x-icon name="trash-2" class="zi zi-sm" /></button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($services->hasPages())
                <div class="card-footer">{{ $services->links() }}</div>
            @endif
        @endif
    </div>

    @can('create', \Modules\Appointments\Models\Service::class)
        @include('appointments::services.modal', ['service' => null])
    @endcan
    @foreach($services as $service)
        @can('update', $service)
            @include('appointments::services.modal', ['service' => $service])
        @endcan
    @endforeach

    @if($errors->any() && old('_service'))
        @push('scripts')
            <script>document.addEventListener('DOMContentLoaded', () => { const m = document.getElementById('serviceModal_{{ old('_service') }}'); if (m) bootstrap.Modal.getOrCreateInstance(m).show(); });</script>
        @endpush
    @endif
@endsection
