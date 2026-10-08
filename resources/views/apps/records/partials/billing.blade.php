@php $open = $invoices->first(fn ($invoice) => $invoice->isOpen()); @endphp
<div class="card mb-3">
    <div class="card-header">
        <h5 class="card-title">Billing</h5>
        @can('update', $record)
            @if($invoices->isEmpty() || ! $open)
                <form method="POST" action="{{ route('apps.records.bill', [$app->key, $def->key, $record->id]) }}">
                    @csrf
                    <button class="btn btn-sm btn-soft-primary"><x-icon name="receipt" class="zi zi-sm" /> {{ $invoices->isEmpty() ? 'Create invoice' : 'Invoice again' }}</button>
                </form>
            @endif
        @endcan
    </div>
    @if($invoices->isEmpty())
        <div class="card-body fs-7 text-muted">Not billed yet.</div>
    @else
        <div class="list-group list-group-flush">
            @foreach($invoices as $invoice)
                <a href="{{ route('invoices.show', $invoice) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                    <span class="mw-0 flex-grow-1">
                        <span class="d-block fw-600 fs-7">{{ $invoice->number }}{{ $invoice->period ? ' · '.$invoice->period : '' }}</span>
                        <span class="d-block fs-8 text-muted">{{ $invoice->money($invoice->total) }}@if($invoice->balance > 0) · {{ $invoice->money($invoice->balance) }} owing @endif</span>
                    </span>
                    <x-pill :status="$invoice->status">{{ $invoice->statusLabel() }}</x-pill>
                </a>
            @endforeach
        </div>
    @endif
    @if($open)
        @can('update', $record)
            <div class="card-body border-top">
                <form method="POST" action="{{ route('apps.records.payments.store', [$app->key, $def->key, $record->id]) }}">
                    @csrf
                    <input type="hidden" name="invoice_id" value="{{ $open->id }}">
                    <div class="fs-8 text-muted mb-2">Take a payment on {{ $open->number }}</div>
                    <div class="row g-2">
                        <div class="col-6"><x-form.input name="amount" type="number" step="0.01" min="0.01" :value="number_format($open->balance, 2, '.', '')" label="Amount" class="form-control-sm" required /></div>
                        <div class="col-6"><x-form.select name="method" :options="\Modules\Invoicing\Models\Payment::METHODS" value="cash" label="Method" class="form-select-sm" /></div>
                    </div>
                    <x-form.input name="reference" label="Reference" class="form-control-sm" placeholder="Receipt or transaction no." />
                    @error('billing')<div class="text-danger fs-8 mb-2">{{ $message }}</div>@enderror
                    <button class="btn btn-sm btn-success w-100"><x-icon name="banknote" class="zi zi-sm" /> Record payment</button>
                </form>
            </div>
        @endcan
    @endif
    @if($errors->has('billing') && ! $open)<div class="card-body pt-0 text-danger fs-8">{{ $errors->first('billing') }}</div>@endif
</div>
