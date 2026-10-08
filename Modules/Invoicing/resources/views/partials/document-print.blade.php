@php
    $isInvoice = $kind === 'invoice';
    $ws = $document->workspace ?? $workspace;
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
@endphp
<div class="head">
    <div class="brand">
        @if($ws?->logo_url)<img src="{{ $ws->logo_url }}" alt="{{ $ws->name }}"><br>@endif
        <h1>{{ $ws?->name }}</h1>
        {!! implode('<br>', array_map('e', $fromLines)) !!}
    </div>
    <div class="doc-title">
        <h2>{{ $isInvoice ? 'Invoice' : 'Quotation' }}</h2>
        <div class="num">{{ $document->number }}</div>
        <span class="status {{ $document->status }}">{{ $document->statusLabel() }}</span>
    </div>
</div>

<div class="parties">
    <div>
        <h4>{{ $isInvoice ? 'Bill to' : 'Prepared for' }}</h4>
        <p>{!! implode('<br>', array_map('e', $customerLines)) !!}</p>
    </div>
    <div class="meta">
        <div><b>Issue date</b>{{ $document->issue_date?->format('d M Y') }}</div>
        @if($isInvoice)
            <div><b>Due date</b>{{ $document->due_date?->format('d M Y') }}</div>
        @else
            <div><b>Valid until</b>{{ $document->valid_until?->format('d M Y') ?? '—' }}</div>
        @endif
        <div><b>{{ $document->reference ? 'Reference' : 'Currency' }}</b>{{ $document->reference ?: $document->currency_code }}</div>
    </div>
</div>

<table>
    <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Tax</th><th class="r">Amount</th></tr></thead>
    <tbody>
    @foreach($document->lines as $line)
        <tr>
            <td>{{ $line->description }}</td>
            <td class="r">{{ $fmtQty($line->quantity) }}{{ $line->unit ? ' '.$line->unit : '' }}</td>
            <td class="r">{{ $document->money($line->unit_price) }}</td>
            <td class="r">{{ $line->tax_rate > 0 ? $fmtQty($line->tax_rate).'%' : '—' }}</td>
            <td class="r b">{{ $document->money($line->line_total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="totals">
    <div><span>Subtotal</span><span>{{ $document->money($document->subtotal) }}</span></div>
    @if($document->discount_amount > 0)
        <div><span>Discount</span><span>- {{ $document->money($document->discount_amount) }}</span></div>
    @endif
    <div><span>Tax</span><span>{{ $document->money($document->tax_total) }}</span></div>
    <div class="grand"><span>Total</span><span>{{ $document->money($document->total) }}</span></div>
    @if($isInvoice && $document->amount_paid > 0)
        <div><span>Paid</span><span>- {{ $document->money($document->amount_paid) }}</span></div>
        <div class="due"><span>Balance due</span><span>{{ $document->money($document->balance) }}</span></div>
    @elseif($isInvoice && $document->status !== 'cancelled')
        <div class="due"><span>Amount due</span><span>{{ $document->money($document->balance ?: $document->total) }}</span></div>
    @endif
</div>

@if($document->notes || $document->terms)
    <div class="notes">
        @if($document->notes)<h4>Notes</h4><p>{{ $document->notes }}</p>@endif
        @if($document->terms)<h4>Terms</h4><p>{{ $document->terms }}</p>@endif
    </div>
@endif

<div class="foot">
    {{ $ws?->setting('invoicing.footer') ?: 'Thank you for your business.' }}
    @if($isInvoice && $document->payments->isNotEmpty())
        <br>Payments received: {{ $document->payments->map(fn ($p) => $p->paid_on->format('d M Y').' '.$p->money().' ('.$p->methodLabel().')')->implode(' · ') }}
    @endif
</div>
