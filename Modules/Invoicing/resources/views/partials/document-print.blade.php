@php
    $isInvoice = $kind === 'invoice';
    $ws = $document->workspace ?? $workspace;
    $design ??= \Modules\Invoicing\Documents\DocumentDesign::for($ws);
    $logo = $design->logo($ws, $pdf ?? false);
    $customer = $document->contact;
    $customerLines = array_filter([
        $customer?->displayName(),
        $customer && $customer->kind === 'company' ? 'Attn: '.$customer->name : null,
        $customer?->address, trim(($customer?->city ?? '').' '.($customer?->country_code ? \App\Support\Lists::country($customer->country_code) : '')),
        $customer?->email, $customer?->phone,
        $customer?->tax_number ? 'Tax no. '.$customer->tax_number : null,
    ]);
    $from = $document->branch;
    $fromLines = array_filter([
        $from?->address ?? $ws?->address, $from?->city ?? $ws?->city,
        $from?->phone ?? $ws?->phone, $from?->email ?? $ws?->email,
        $ws?->tax_number ? 'Tax no. '.$ws->tax_number : null,
    ]);
    $fmtQty = fn ($q) => rtrim(rtrim(number_format((float) $q, 3), '0'), '.');
    $showTax = $design->show_tax_column;
@endphp
<div class="doc doc-{{ $design->style }}">
    <table class="head">
        <tr>
            <td class="brand">
                @if($logo)<img src="{{ $logo }}" alt="{{ $ws->name }}"><br>@endif
                <h1>{{ $ws?->name }}</h1>
                {!! implode('<br>', array_map('e', $fromLines)) !!}
            </td>
            <td class="doc-title">
                <h2>{{ $design->title($kind) }}</h2>
                <div class="num">{{ $document->number }}</div>
                <span class="status {{ $document->status }}">{{ $document->statusLabel() }}</span>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td class="to">
                <h4>{{ $isInvoice ? 'Bill to' : 'Prepared for' }}</h4>
                {!! implode('<br>', array_map('e', $customerLines)) !!}
            </td>
            <td>
                <table class="meta">
                    <tr>
                        <td><b>Issue date</b>{{ $document->issue_date?->format('d M Y') }}</td>
                        @if($isInvoice)
                            <td><b>Due date</b>{{ $document->due_date?->format('d M Y') }}</td>
                        @else
                            <td><b>Valid until</b>{{ $document->valid_until?->format('d M Y') ?? '—' }}</td>
                        @endif
                        <td><b>{{ $document->reference ? 'Reference' : 'Currency' }}</b>{{ $document->reference ?: $document->currency_code }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th>@if($showTax)<th class="r">Tax</th>@endif<th class="r">Amount</th></tr></thead>
        <tbody>
        @foreach($document->lines as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td class="r">{{ $fmtQty($line->quantity) }}{{ $line->unit ? ' '.$line->unit : '' }}</td>
                <td class="r">{{ $document->money($line->unit_price) }}</td>
                @if($showTax)<td class="r">{{ $line->tax_rate > 0 ? $fmtQty($line->tax_rate).'%' : '—' }}</td>@endif
                <td class="r b">{{ $document->money($line->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals-wrap">
        <tr>
            <td></td>
            <td style="width: 300px">
                <table class="totals">
                    <tr><td>Subtotal</td><td class="r">{{ $document->money($document->subtotal) }}</td></tr>
                    @if($document->discount_amount > 0)
                        <tr><td>Discount</td><td class="r">- {{ $document->money($document->discount_amount) }}</td></tr>
                    @endif
                    <tr><td>Tax</td><td class="r">{{ $document->money($document->tax_total) }}</td></tr>
                    <tr class="grand"><td>Total</td><td class="r">{{ $document->money($document->total) }}</td></tr>
                    @if($isInvoice && $document->amount_paid > 0)
                        <tr><td>Paid</td><td class="r">- {{ $document->money($document->amount_paid) }}</td></tr>
                        <tr class="due"><td>Balance due</td><td class="r">{{ $document->money($document->balance) }}</td></tr>
                    @elseif($isInvoice && $document->status !== 'cancelled')
                        <tr class="due"><td>Amount due</td><td class="r">{{ $document->money($document->balance ?: $document->total) }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if($isInvoice && $design->payment_details && $document->status !== 'cancelled' && $document->status !== 'paid')
        <div class="pay-details"><h4>How to pay</h4>{{ $design->payment_details }}</div>
    @endif

    @if($document->notes || $document->terms)
        <div class="notes">
            @if($document->notes)<h4>Notes</h4><p>{{ $document->notes }}</p>@endif
            @if($document->terms)<h4>Terms</h4><p>{{ $document->terms }}</p>@endif
        </div>
    @endif

    @if($design->signature)
        <table class="signature"><tr><td>For {{ $ws?->name }}</td><td class="gap"></td><td>{{ $isInvoice ? 'Received by' : 'Accepted by' }} (name, signature, date)</td></tr></table>
    @endif

    <div class="foot">
        {{ $design->footer ?: 'Thank you for your business.' }}
        @if($isInvoice && $document->payments->isNotEmpty())
            <br>Payments received: {{ $document->payments->map(fn ($p) => $p->paid_on->format('d M Y').' '.$p->money().' ('.$p->methodLabel().')')->implode(' · ') }}
        @endif
    </div>
</div>
