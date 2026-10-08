@extends('layouts.portal')
@section('title', 'Invoices & quotes')
@section('content')
    <h1 class="h4 mb-3">Invoices</h1>
    <div class="card mb-4">
        @if($invoices->isEmpty())
            <div class="card-body"><x-empty icon="receipt" title="No invoices yet" class="py-2" /></div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Number</th><th>Date</th><th>Due</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Balance</th><th></th></tr></thead>
                    <tbody>
                        @foreach($invoices as $invoice)
                            <tr>
                                <td class="fw-600">{{ $invoice->number }}</td>
                                <td>{{ $invoice->issue_date?->format('d M Y') }}</td>
                                <td>{{ $invoice->due_date?->format('d M Y') ?? '—' }}</td>
                                <td><x-pill :status="$invoice->status">{{ $invoice->statusLabel() }}</x-pill></td>
                                <td class="text-end">{{ $invoice->money($invoice->total) }}</td>
                                <td class="text-end fw-600">{{ $invoice->money($invoice->balance) }}</td>
                                <td class="text-end"><a href="{{ $invoice->publicUrl() }}" class="btn btn-sm {{ $invoice->isOpen() ? 'btn-primary' : 'btn-white' }}" target="_blank" rel="noopener">{{ $invoice->isOpen() ? 'View & pay' : 'View' }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <h2 class="h5 mb-3">Quotes</h2>
    <div class="card">
        @if($quotes->isEmpty())
            <div class="card-body"><x-empty icon="file-text" title="No quotes yet" class="py-2" /></div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Number</th><th>Date</th><th>Valid until</th><th>Status</th><th class="text-end">Total</th><th></th></tr></thead>
                    <tbody>
                        @foreach($quotes as $quote)
                            <tr>
                                <td class="fw-600">{{ $quote->number }}</td>
                                <td>{{ $quote->issue_date?->format('d M Y') }}</td>
                                <td>{{ $quote->valid_until?->format('d M Y') ?? '—' }}</td>
                                <td><x-pill :status="$quote->status">{{ $quote->statusLabel() }}</x-pill></td>
                                <td class="text-end">{{ $quote->money($quote->total) }}</td>
                                <td class="text-end"><a href="{{ $quote->publicUrl() }}" class="btn btn-sm btn-white" target="_blank" rel="noopener">View</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
