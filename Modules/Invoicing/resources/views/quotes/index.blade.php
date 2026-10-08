@extends('layouts.app')
@section('title', 'Quotes')
@section('content')
    <x-page-header title="Quotes" :sub="$contact ? 'Quotes for '.$contact->displayName() : 'Priced proposals waiting for a yes. Accepted quotes become invoices in one click.'" :crumbs="['Quotes']">
        @can('create', \Modules\Invoicing\Models\Quote::class)
            <a href="{{ route('quotes.create', $contact ? ['contact' => $contact->id] : []) }}" class="btn btn-primary"><x-icon name="plus" /> New quote</a>
        @endcan
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-stat label="Awaiting answer" :value="\App\Support\Money::format($stats['open'])" :delta="$stats['open_count'].' open'" icon="hourglass" color="info" :href="route('quotes.index', ['status' => 'sent'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Won this month" :value="\App\Support\Money::format($stats['accepted'])" icon="trophy" color="success" :href="route('quotes.index', ['status' => 'accepted'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Drafts" :value="$stats['drafts']" icon="file-edit" color="warning" :href="route('quotes.index', ['status' => 'draft'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="All quotes" :value="$quotes->total()" icon="file-signature" color="primary" :href="route('quotes.index')" /></div>
    </div>

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                @if($contact)<input type="hidden" name="contact" value="{{ $contact->id }}">@endif
                <div class="z-search flex-grow-1" style="max-width:360px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Search number, reference or customer…">
                </div>
                <select name="status" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    @foreach(\Modules\Invoicing\Models\Quote::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="btn btn-soft-primary">Filter</button>
                @if($filters['q'] || $filters['status'] || $contact)<a href="{{ route('quotes.index') }}" class="btn btn-link btn-sm text-muted">Clear</a>@endif
            </form>
            <span class="fs-8 text-muted">{{ $quotes->total() }} {{ \Illuminate\Support\Str::plural('quote', $quotes->total()) }}</span>
        </div>

        @if($quotes->isEmpty())
            <div class="card-body">
                <x-empty icon="file-signature" title="No quotes found" text="{{ $filters['q'] || $filters['status'] ? 'Nothing matches your filters.' : 'Send your first quote and track it until it is won.' }}">
                    @can('create', \Modules\Invoicing\Models\Quote::class)<a href="{{ route('quotes.create') }}" class="btn btn-primary"><x-icon name="plus" /> New quote</a>@endcan
                </x-empty>
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>Quote</th><th>Customer</th><th>Issued</th><th>Valid until</th><th>Status</th><th class="text-end">Total</th><th></th></tr></thead>
                    <tbody>
                    @foreach($quotes as $q)
                        <tr>
                            <td><a href="{{ route('quotes.show', $q) }}" class="z-row-title text-reset text-decoration-none">{{ $q->number }}</a>@if($q->reference)<div class="z-row-sub">{{ $q->reference }}</div>@endif</td>
                            <td class="fs-7">{{ $q->contact?->displayName() }}</td>
                            <td class="fs-7">{{ $q->issue_date->format('d M Y') }}</td>
                            <td class="fs-7 {{ $q->isExpired() ? 'text-danger' : '' }}">{{ $q->valid_until?->format('d M Y') ?? '—' }}</td>
                            <td><x-pill :status="$q->status">{{ $q->statusLabel() }}</x-pill></td>
                            <td class="text-end fs-7 fw-600">{{ $q->money($q->total) }}</td>
                            <td class="text-end">
                                <div class="z-row-actions d-inline-flex gap-1">
                                    <a href="{{ route('quotes.show', $q) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="View"><x-icon name="eye" class="zi zi-sm" /></a>
                                    <a href="{{ route('quotes.print', $q) }}" target="_blank" class="btn btn-sm btn-icon btn-soft-secondary" title="Print"><x-icon name="printer" class="zi zi-sm" /></a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($quotes->hasPages())<div class="card-footer">{{ $quotes->links() }}</div>@endif
        @endif
    </div>
@endsection
