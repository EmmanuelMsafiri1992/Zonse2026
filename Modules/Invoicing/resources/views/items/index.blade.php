@extends('layouts.app')
@section('title', 'Products & services')
@section('content')
    <x-page-header title="Products & services" sub="Your price list. Pick from it when building invoices and quotes." :crumbs="['Products & services']">
        @can('create', \Modules\Invoicing\Models\Item::class)
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#itemModal_new"><x-icon name="plus" /> New item</button>
        @endcan
    </x-page-header>

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                <div class="z-search flex-grow-1" style="max-width:320px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Search name or SKU…">
                </div>
                <select name="type" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">Products & services</option>
                    @foreach($types as $key => $label)<option value="{{ $key }}" @selected($filters['type'] === $key)>{{ \Illuminate\Support\Str::plural($label) }}</option>@endforeach
                </select>
                <select name="status" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="active" @selected($filters['status'] === 'active')>Active</option>
                    <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
                    <option value="all" @selected($filters['status'] === 'all')>All</option>
                </select>
                <button class="btn btn-soft-primary">Filter</button>
            </form>
            <span class="fs-8 text-muted">{{ $items->total() }} {{ \Illuminate\Support\Str::plural('item', $items->total()) }}</span>
        </div>

        @if($items->isEmpty())
            <div class="card-body">
                <x-empty icon="package" title="No items yet" text="Add the things you sell so invoices take seconds to build.">
                    @can('create', \Modules\Invoicing\Models\Item::class)<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#itemModal_new"><x-icon name="plus" /> New item</button>@endcan
                </x-empty>
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>Item</th><th>Type</th><th>SKU</th><th>Unit</th><th>Stock</th><th>Tax</th><th class="text-end">Price</th><th></th></tr></thead>
                    <tbody>
                    @foreach($items as $item)
                        <tr>
                            <td><div class="z-row-title">{{ $item->name }} @unless($item->is_active)<x-pill status="inactive" class="ms-1" />@endunless</div>@if($item->description)<div class="z-row-sub text-truncate" style="max-width:360px">{{ $item->description }}</div>@endif</td>
                            <td><x-pill :status="$item->type === 'product' ? 'info' : 'purple'">{{ \Modules\Invoicing\Models\Item::TYPES[$item->type] ?? $item->type }}</x-pill></td>
                            <td class="fs-7">{{ $item->sku ?: '—' }}</td>
                            <td class="fs-7">{{ $item->unit ?: '—' }}</td>
                            <td class="fs-7 {{ $item->isLowOnStock() ? 'text-danger fw-600' : '' }}">{{ $item->tracksStock() ? rtrim(rtrim(number_format($item->stock_qty, 3), '0'), '.') : '—' }}</td>
                            <td class="fs-7">{{ $item->taxRate ? $item->taxRate->name.' '.rtrim(rtrim(number_format($item->taxRate->rate, 2), '0'), '.').'%' : '—' }}</td>
                            <td class="text-end fs-7 fw-600">{{ \App\Support\Money::format($item->price) }}</td>
                            <td class="text-end">
                                <div class="z-row-actions d-inline-flex gap-1">
                                    @can('update', $item)<button type="button" class="btn btn-sm btn-icon btn-soft-secondary" data-bs-toggle="modal" data-bs-target="#itemModal_{{ $item->id }}" title="Edit"><x-icon name="pencil" class="zi zi-sm" /></button>@endcan
                                    @can('delete', $item)
                                        <form method="POST" action="{{ route('items.destroy', $item) }}" onsubmit="return confirm('Delete {{ addslashes($item->name) }}?')">@csrf @method('DELETE')<button class="btn btn-sm btn-icon btn-soft-danger" title="Delete"><x-icon name="trash-2" class="zi zi-sm" /></button></form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                        @can('update', $item)
                            @push('modals')@include('invoicing::items.modal', ['item' => $item])@endpush
                        @endcan
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($items->hasPages())<div class="card-footer">{{ $items->links() }}</div>@endif
        @endif
    </div>

    @can('create', \Modules\Invoicing\Models\Item::class)
        @push('modals')@include('invoicing::items.modal', ['item' => null])@endpush
    @endcan
    @if($errors->any())
        @push('scripts')<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal('#itemModal_{{ old('_item', 'new') }}').show());</script>@endpush
    @endif
@endsection
