{{-- Read-only lines + totals for an invoice or quote. Expects $document. --}}
<div class="z-table-wrap">
    <table class="table z-table align-middle mb-0">
        <thead><tr><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Tax</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        @foreach($document->lines as $line)
            <tr>
                <td class="fs-7">{{ $line->description }}</td>
                <td class="text-end fs-7">{{ rtrim(rtrim(number_format($line->quantity, 3), '0'), '.') }}{{ $line->unit ? ' '.$line->unit : '' }}</td>
                <td class="text-end fs-7">{{ $document->money($line->unit_price) }}</td>
                <td class="text-end fs-7">{{ $line->tax_rate > 0 ? rtrim(rtrim(number_format($line->tax_rate, 2), '0'), '.').'%' : '—' }}</td>
                <td class="text-end fs-7 fw-600">{{ $document->money($line->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
<div class="card-footer d-flex justify-content-end">
    <div style="min-width:260px" class="fs-7">
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Subtotal</span><span>{{ $document->money($document->subtotal) }}</span></div>
        @if($document->discount_amount > 0)
            <div class="d-flex justify-content-between py-1"><span class="text-muted">Discount{{ $document->discount_type === 'percent' ? ' ('.rtrim(rtrim(number_format($document->discount_value, 2), '0'), '.').'%)' : '' }}</span><span>- {{ $document->money($document->discount_amount) }}</span></div>
        @endif
        <div class="d-flex justify-content-between py-1"><span class="text-muted">Tax</span><span>{{ $document->money($document->tax_total) }}</span></div>
        <div class="d-flex justify-content-between fw-600 fs-6 pt-2 border-top"><span>Total</span><span>{{ $document->money($document->total) }}</span></div>
        @if($document instanceof \Modules\Invoicing\Models\Invoice && $document->amount_paid > 0)
            <div class="d-flex justify-content-between py-1 text-success"><span>Paid</span><span>- {{ $document->money($document->amount_paid) }}</span></div>
            <div class="d-flex justify-content-between fw-600 pt-1 border-top"><span>Balance due</span><span>{{ $document->money($document->balance) }}</span></div>
        @endif
    </div>
</div>
