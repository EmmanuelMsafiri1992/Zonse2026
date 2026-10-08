@extends('layouts.app')
@section('title', 'Contacts')
@section('content')
    <x-page-header title="Contacts" sub="Customers, suppliers, leads and everyone else you do business with." :crumbs="['Contacts']">
        <a href="{{ route('contacts.export', request()->query()) }}" class="btn btn-white"><x-icon name="download" /> Export CSV</a>
        @can('create', \Modules\Contacts\Models\Contact::class)
            <a href="{{ route('contacts.create') }}" class="btn btn-primary"><x-icon name="plus" /> New contact</a>
        @endcan
    </x-page-header>

    <div class="row g-3 mb-4">
        @foreach(\Modules\Contacts\Models\Contact::TYPES as $key => $label)
            <div class="col-6 col-xl-3">
                <x-stat :label="\Illuminate\Support\Str::plural($label)" :value="$counts[$key] ?? 0"
                        :icon="['customer' => 'user-round', 'supplier' => 'truck', 'lead' => 'sparkles', 'other' => 'users'][$key]"
                        :color="['customer' => 'primary', 'supplier' => 'info', 'lead' => 'warning', 'other' => 'purple'][$key]"
                        :href="route('contacts.index', ['type' => $key])" />
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                <div class="z-search flex-grow-1" style="max-width:360px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Search name, email, phone…">
                </div>
                <select name="type" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">All types</option>
                    @foreach(\Modules\Contacts\Models\Contact::TYPES as $key => $label)
                        <option value="{{ $key }}" @selected($filters['type'] === $key)>{{ \Illuminate\Support\Str::plural($label) }}</option>
                    @endforeach
                </select>
                <select name="status" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="active" @selected($filters['status'] === 'active')>Active</option>
                    <option value="archived" @selected($filters['status'] === 'archived')>Archived</option>
                    <option value="all" @selected($filters['status'] === 'all')>All</option>
                </select>
                <button class="btn btn-soft-primary">Filter</button>
                @if($filters['q'] || $filters['type'] || $filters['status'] !== 'active')
                    <a href="{{ route('contacts.index') }}" class="btn btn-link btn-sm text-muted">Clear</a>
                @endif
            </form>
            <span class="fs-8 text-muted">{{ $contacts->total() }} {{ \Illuminate\Support\Str::plural('contact', $contacts->total()) }}</span>
        </div>

        @if($contacts->isEmpty())
            <div class="card-body">
                <x-empty icon="contact" title="No contacts found" text="{{ $filters['q'] ? 'Nothing matches your search.' : 'Add your first customer, supplier or lead to get started.' }}">
                    @can('create', \Modules\Contacts\Models\Contact::class)
                        <a href="{{ route('contacts.create') }}" class="btn btn-primary"><x-icon name="plus" /> New contact</a>
                    @endcan
                </x-empty>
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>Contact</th><th>Type</th><th>Phone</th><th>Email</th><th>Location</th><th>Tags</th><th></th></tr></thead>
                    <tbody>
                    @foreach($contacts as $c)
                        <tr>
                            <td>
                                <a href="{{ route('contacts.show', $c) }}" class="d-flex align-items-center gap-2 text-reset text-decoration-none">
                                    <span class="z-avatar z-avatar-sm z-avatar-soft">{{ $c->initials() }}</span>
                                    <span class="mw-0">
                                        <span class="z-row-title d-block text-truncate">{{ $c->displayName() }}</span>
                                        <span class="z-row-sub">{{ $c->kind === 'company' ? $c->name : ($c->company_name ?: ($c->branch?->name ?? '')) }}</span>
                                    </span>
                                </a>
                            </td>
                            <td><x-pill :status="$c->type" /></td>
                            <td class="fs-7">{{ $c->phone ?: $c->mobile ?: '—' }}</td>
                            <td class="fs-7">{{ $c->email ?: '—' }}</td>
                            <td class="fs-7">{{ $c->city }}{{ $c->city && $c->country_code ? ', ' : '' }}{{ $c->country_code }}</td>
                            <td>@foreach($c->tags ?? [] as $tag)<span class="z-chip">{{ $tag }}</span>@endforeach</td>
                            <td class="text-end">
                                <div class="z-row-actions d-inline-flex gap-1">
                                    <a href="{{ route('contacts.show', $c) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="View"><x-icon name="eye" class="zi zi-sm" /></a>
                                    @can('update', $c)
                                        <a href="{{ route('contacts.edit', $c) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="Edit"><x-icon name="pencil" class="zi zi-sm" /></a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($contacts->hasPages())
                <div class="card-footer">{{ $contacts->links() }}</div>
            @endif
        @endif
    </div>
@endsection
