@extends('layouts.app')
@section('title', 'Invoices')
@section('content')
    <x-page-header title="Invoices" :sub="$contact ? 'Invoices for '.$contact->displayName() : 'Everything you have billed, what is paid and what is still owed.'" :crumbs="['Invoices']">
        @can('create', \Modules\Invoicing\Models\Invoice::class)
            <a href="{{ route('invoices.create', $contact ? ['contact' => $contact->id] : []) }}" class="btn btn-primary" data-tour="new-invoice"><x-icon name="plus" /> New invoice</a>
        @endcan
    </x-page-header>

    <div class="row g-3 mb-4" data-tour="invoice-stats">
        <div class="col-6 col-xl-3"><x-stat label="Outstanding" :value="\App\Support\Money::format($stats['outstanding'])" icon="hourglass" color="primary" :href="route('invoices.index', ['status' => 'open'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Overdue" :value="\App\Support\Money::format($stats['overdue'])" icon="alert-circle" color="danger" :href="route('invoices.index', ['status' => 'overdue'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Collected this month" :value="\App\Support\Money::format($stats['collected'])" icon="banknote" color="success" :href="route('payments.index')" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Drafts" :value="$stats['drafts']" icon="file-edit" color="warning" :href="route('invoices.index', ['status' => 'draft'])" /></div>
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
                    <option value="open" @selected($filters['status'] === 'open')>Open (unpaid)</option>
                    @foreach(\Modules\Invoicing\Models\Invoice::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="btn btn-soft-primary">Filter</button>
                @if($filters['q'] || $filters['status'] || $contact)
                    <a href="{{ route('invoices.index') }}" class="btn btn-link btn-sm text-muted">Clear</a>
                @endif
            </form>
            <span class="fs-8 text-muted">{{ $invoices->total() }} {{ \Illuminate\Support\Str::plural('invoice', $invoices->total()) }}</span>
        </div>

        @if($invoices->isEmpty())
            <div class="card-body">
                <x-empty icon="file-text" title="No invoices found" text="{{ $filters['q'] || $filters['status'] ? 'Nothing matches your filters.' : 'Create your first invoice and get paid faster.' }}">
                    @can('create', \Modules\Invoicing\Models\Invoice::class)
                        <a href="{{ route('invoices.create') }}" class="btn btn-primary"><x-icon name="plus" /> New invoice</a>
                    @endcan
                </x-empty>
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>Invoice</th><th>Customer</th><th>Issued</th><th>Due</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Balance</th><th></th></tr></thead>
                    <tbody>
                    @foreach($invoices as $inv)
                        <tr>
                            <td><a href="{{ route('invoices.show', $inv) }}" class="z-row-title text-reset text-decoration-none">{{ $inv->number }}</a>@if($inv->reference)<div class="z-row-sub">{{ $inv->reference }}</div>@endif</td>
                            <td class="fs-7">{{ $inv->contact?->displayName() }}</td>
                            <td class="fs-7">{{ $inv->issue_date->format('d M Y') }}</td>
                            <td class="fs-7 {{ $inv->isOverdue() ? 'text-danger fw-600' : '' }}">{{ $inv->due_date->format('d M Y') }}</td>
                            <td><x-pill :status="$inv->status">{{ $inv->statusLabel() }}</x-pill></td>
                            <td class="text-end fs-7">{{ $inv->money($inv->total) }}</td>
                            <td class="text-end fs-7 fw-600">{{ $inv->status === 'cancelled' ? '—' : $inv->money($inv->balance) }}</td>
                            <td class="text-end">
                                <div class="z-row-actions d-inline-flex gap-1">
                                    <a href="{{ route('invoices.show', $inv) }}" class="btn btn-sm btn-icon btn-soft-secondary" title="View"><x-icon name="eye" class="zi zi-sm" /></a>
                                    <a href="{{ route('invoices.print', $inv) }}" target="_blank" class="btn btn-sm btn-icon btn-soft-secondary" title="Print"><x-icon name="printer" class="zi zi-sm" /></a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($invoices->hasPages())<div class="card-footer">{{ $invoices->links() }}</div>@endif
        @endif
    </div>
@endsection
