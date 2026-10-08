<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $receipt['number'] }}</title>
    <style>
        @page { size: {{ $paperWidth }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; font: 12px/1.35 "Courier New", monospace; color: #000; }
        .slip { width: {{ $paperWidth }}mm; margin: 16px auto; padding: 4mm 3mm; background: #fff; }
        .center { text-align: center; }
        .business { font-size: 15px; font-weight: 700; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .row span:last-child { text-align: right; white-space: nowrap; }
        .rule { border-top: 1px dashed #000; margin: 6px 0; }
        .bold { font-weight: 700; }
        .qr svg { width: 30mm; height: 30mm; }
        .actions { text-align: center; margin: 12px; font-family: system-ui, sans-serif; }
        @media print { body { background: #fff; } .slip { margin: 0; } .actions { display: none; } }
    </style>
</head>
<body>
    <div class="slip">
        <div class="center">
            <div class="business">{{ $receipt['business'] }}</div>
            @foreach($receipt['header'] as $line)<div>{{ $line }}</div>@endforeach
            <div class="bold" style="margin-top:6px">Receipt {{ $receipt['number'] }}</div>
        </div>
        <div class="row"><span>Date</span><span>{{ $receipt['date'] }}</span></div>
        @if($receipt['till'])<div class="row"><span>Till</span><span>{{ $receipt['till'] }}</span></div>@endif
        @if($receipt['cashier'])<div class="row"><span>Cashier</span><span>{{ $receipt['cashier'] }}</span></div>@endif
        <div class="rule"></div>
        @foreach($receipt['lines'] as $line)
            <div>{{ $line['description'] }}</div>
            <div class="row"><span>&nbsp;&nbsp;{{ $line['quantity'] }} × {{ number_format($line['unit_price'], 2) }}</span><span>{{ number_format($line['total'], 2) }}</span></div>
        @endforeach
        <div class="rule"></div>
        @if($receipt['tax'] > 0)<div class="row"><span>Tax</span><span>{{ number_format($receipt['tax'], 2) }}</span></div>@endif
        <div class="row bold"><span>Total {{ $receipt['currency'] }}</span><span>{{ number_format($receipt['total'], 2) }}</span></div>
        <div class="row"><span>Paid by</span><span>{{ $receipt['method'] }}</span></div>
        @if($receipt['cash'])
            <div class="row"><span>Tendered</span><span>{{ number_format($receipt['tendered'], 2) }}</span></div>
            <div class="row"><span>Change</span><span>{{ number_format($receipt['change'], 2) }}</span></div>
        @endif
        @if($receipt['approval_code'])<div class="row"><span>Card approval</span><span>{{ $receipt['approval_code'] }}</span></div>@endif
        @if($receipt['fiscal'])
            <div class="rule"></div>
            <div class="row"><span>Fiscal no.</span><span>{{ $receipt['fiscal']['number'] }}</span></div>
            <div class="row"><span>Verify code</span><span>{{ $receipt['fiscal']['code'] }}</span></div>
            @if($receipt['fiscal']['test'])<div>{{ $receipt['fiscal']['authority'] }} test mode</div>@endif
        @endif
        <div class="center" style="margin-top:8px">{{ $receipt['footer'] }}</div>
        @if($qr)
            <div class="center qr" style="margin-top:6px">{!! $qr !!}<div>{{ $receipt['qr_label'] }}</div></div>
        @endif
    </div>
    <div class="actions"><button type="button" onclick="window.print()">Print</button></div>
    @if(request()->boolean('print'))<script>window.addEventListener('load', () => window.print());</script>@endif
</body>
</html>
