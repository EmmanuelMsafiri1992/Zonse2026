@extends('layouts.app')
@section('title', 'Payments')
@section('content')
    <x-page-header title="Payments received" sub="Every payment recorded against an invoice." :crumbs="['Payments']" />

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                <div class="z-search flex-grow-1" style="max-width:300px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Receipt, invoice, reference, customer…">
                </div>
                <select name="method" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">All methods</option>
                    @foreach($methods as $key => $label)<option value="{{ $key }}" @selected($filters['method'] === $key)>{{ $label }}</option>@endforeach
                </select>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control w-auto" title="From">
                <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control w-auto" title="To">
                <button class="btn btn-soft-primary">Filter</button>
                @if($filters['q'] || $filters['method'] || $filters['from'] || $filters['to'])<a href="{{ route('payments.index') }}" class="btn btn-link btn-sm text-muted">Clear</a>@endif
            </form>
            <span class="fs-7 fw-600">{{ \App\Support\Money::format($total) }} <span class="fs-8 text-muted fw-normal">across {{ $payments->total() }} {{ \Illuminate\Support\Str::plural('payment', $payments->total()) }}</span></span>
        </div>

        @if($payments->isEmpty())
            <div class="card-body"><x-empty icon="banknote" title="No payments yet" text="Payments you record on invoices will show up here." /></div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>Receipt</th><th>Date</th><th>Invoice</th><th>Customer</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    @foreach($payments as $p)
                        <tr>
                            <td class="z-row-title">{{ $p->number }}</td>
                            <td class="fs-7">{{ $p->paid_on->format('d M Y') }}</td>
                            <td class="fs-7">@if($p->invoice)<a href="{{ route('invoices.show', $p->invoice) }}">{{ $p->invoice->number }}</a>@else —@endif</td>
                            <td class="fs-7">{{ $p->contact?->displayName() }}</td>
                            <td class="fs-7">{{ $p->methodLabel() }}</td>
                            <td class="fs-7">{{ $p->reference ?: '—' }}</td>
                            <td class="text-end fs-7 fw-600">{{ $p->money() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($payments->hasPages())<div class="card-footer">{{ $payments->links() }}</div>@endif
        @endif
    </div>
@endsection
