@extends('layouts.app')
@section('title', 'Fiscal log')
@section('content')
    <x-page-header title="Fiscal log" sub="Every invoice and credit note reported to the tax authority, in order." :crumbs="['Settings' => route('settings.workspace.edit'), 'Fiscalisation' => route('settings.fiscal.edit'), 'Fiscal log']">
        <form method="POST" action="{{ route('settings.fiscal.verify-chain') }}">@csrf<button class="btn btn-white"><x-icon name="shield-check" /> Check the chain</button></form>
        @if($pending > 0)
            <form method="POST" action="{{ route('settings.fiscal.retry') }}">@csrf<button class="btn btn-primary"><x-icon name="refresh-cw" /> Send waiting documents ({{ $pending }})</button></form>
        @endif
    </x-page-header>

    <div class="card">
        <div class="card-header flex-wrap gap-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                <div class="z-search flex-grow-1" style="max-width:300px">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Fiscal number, code or invoice…">
                </div>
                <select name="status" class="form-select w-auto" onchange="this.form.submit()">
                    <option value="">Every status</option>
                    @foreach(\App\Models\FiscalDocument::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="btn btn-soft-primary">Filter</button>
                @if($filters['q'] !== '' || $filters['status'])
                    <a href="{{ route('settings.fiscal.log') }}" class="btn btn-link btn-sm text-muted">Clear</a>
                @endif
            </form>
            <span class="fs-8 text-muted">{{ $documents->total() }} {{ \Illuminate\Support\Str::plural('document', $documents->total()) }}</span>
        </div>

        @if($documents->isEmpty())
            <div class="card-body">
                <x-empty icon="landmark" title="Nothing reported yet" text="{{ $filters['q'] !== '' || $filters['status'] ? 'No documents match these filters.' : 'Invoices appear here as they are issued, once fiscalisation is switched on.' }}" />
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>Fiscal number</th><th>Type</th><th>Invoice</th><th class="text-end">Total</th><th>Status</th><th>Issued</th></tr></thead>
                    <tbody>
                    @foreach($documents as $document)
                        <tr>
                            <td>
                                <a href="{{ $document->verifyUrl() }}" target="_blank" class="z-row-title">{{ $document->fiscal_number }}</a>
                                <div class="z-row-sub font-monospace">{{ $document->verification_code }}</div>
                            </td>
                            <td class="fs-7">{{ $document->typeLabel() }}<div class="z-row-sub">{{ $document->authorityName() }}</div></td>
                            <td class="fs-7">
                                @if($document->invoice && ! $document->invoice->trashed())
                                    <a href="{{ route('invoices.show', $document->invoice) }}">{{ $document->payload['invoice_number'] }}</a>
                                @else
                                    {{ $document->payload['invoice_number'] }}
                                @endif
                            </td>
                            <td class="text-end fs-7 text-nowrap">{{ $document->payload['currency'] }} {{ number_format((float) $document->payload['total'], 2) }}</td>
                            <td>
                                <x-pill :status="['signed' => 'paid', 'pending' => 'sent', 'rejected' => 'overdue'][$document->status] ?? $document->status">{{ $document->statusLabel() }}</x-pill>
                                @if($document->last_error)<div class="z-row-sub text-wrap" style="max-width:240px">{{ $document->last_error }}</div>@endif
                                @if($document->authority_reference)<div class="z-row-sub">{{ $document->authority_reference }}</div>@endif
                            </td>
                            <td class="fs-7 text-nowrap">{{ $document->created_at->format('d M Y, H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $documents->links() }}</div>
        @endif
    </div>
@endsection
