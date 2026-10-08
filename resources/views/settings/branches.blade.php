@extends('layouts.app')
@section('title', 'Branches')
@section('content')
    <x-page-header title="Branches & locations" sub="Shops, clinics, campuses, depots or offices. Stock, sales and staff can be tracked per branch." :crumbs="['Settings' => route('settings.workspace.edit'), 'Branches']">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#branchModal"><x-icon name="plus" /> Add branch</button>
    </x-page-header>

    <div class="card">
        <div class="z-table-wrap">
            <table class="table z-table">
                <thead><tr><th>Branch</th><th>Contact</th><th>Location</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach($branches as $b)
                    <tr>
                        <td>
                            <div class="z-row-title">{{ $b->name }} @if($b->is_default)<span class="z-pill z-pill-primary ms-1">Default</span>@endif</div>
                            <div class="z-row-sub">{{ $b->code }}</div>
                        </td>
                        <td class="fs-7">{{ $b->phone }}<br><span class="text-muted">{{ $b->email }}</span></td>
                        <td class="fs-7">{{ $b->address }}<br><span class="text-muted">{{ $b->city }}</span></td>
                        <td><x-pill :status="$b->is_active ? 'active' : 'inactive'" /></td>
                        <td class="text-end">
                            <div class="z-row-actions d-inline-flex gap-1">
                                <button type="button" class="btn btn-sm btn-icon btn-soft-secondary" data-bs-toggle="modal" data-bs-target="#editBranch{{ $b->id }}"><x-icon name="pencil" class="zi zi-sm" /></button>
                                @unless($b->is_default)
                                    <form method="POST" action="{{ route('settings.branches.destroy', $b) }}" onsubmit="return confirm('Delete {{ $b->name }}?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-icon btn-soft-danger"><x-icon name="trash-2" class="zi zi-sm" /></button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                    @push('modals')
                        <div class="modal fade" id="editBranch{{ $b->id }}" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <form class="modal-content" method="POST" action="{{ route('settings.branches.update', $b) }}">
                                    @csrf @method('PUT')
                                    <div class="modal-header"><h5 class="modal-title">Edit {{ $b->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                    <div class="modal-body">
                                        @include('settings.partials.branch-fields', ['b' => $b])
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
                                </form>
                            </div>
                        </div>
                    @endpush
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @push('modals')
        <div class="modal fade" id="branchModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ route('settings.branches.store') }}">
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">Add branch</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">@include('settings.partials.branch-fields', ['b' => null])</div>
                    <div class="modal-footer"><button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add branch</button></div>
                </form>
            </div>
        </div>
    @endpush
@endsection
