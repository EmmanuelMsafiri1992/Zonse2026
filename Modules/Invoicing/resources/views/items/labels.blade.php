<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Item labels</title>
    <style>
        @page { margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; font: 12px/1.3 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #000; }
        .toolbar { display: flex; gap: 8px; justify-content: flex-end; padding: 12px 16px; }
        .toolbar button { padding: 8px 14px; border: 1px solid #ccc; border-radius: 4px; background: #fff; cursor: pointer; }
        .sheet { display: grid; grid-template-columns: repeat(auto-fill, 64mm); gap: 2mm; justify-content: center; padding: 0 16px 16px; }
        .label { width: 64mm; height: 34mm; padding: 2mm 3mm; background: #fff; border: 1px dashed #bbb; display: flex; flex-direction: column; align-items: center; justify-content: space-between; overflow: hidden; page-break-inside: avoid; }
        .name { font-weight: 700; text-align: center; max-height: 2.6em; overflow: hidden; width: 100%; }
        .price { font-size: 16px; font-weight: 700; }
        .code svg { max-width: 58mm; height: auto; max-height: 18mm; display: block; }
        .qr { display: flex; gap: 3mm; align-items: center; }
        .qr svg { width: 18mm; height: 18mm; }
        .muted { font-family: monospace; font-size: 10px; }
        @media print { body { background: #fff; } .toolbar { display: none; } .sheet { padding: 0; } .label { border-color: transparent; } }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Print labels</button></div>
    <div class="sheet">
        @foreach($labels as $item)
            <div class="label">
                <div class="name">{{ $item->name }}</div>
                @if($svg = $item->barcodeSvg(2, 40))
                    <div class="code">{!! $svg !!}</div>
                    <div class="price">{{ \App\Support\Money::format($item->price) }}@if($item->unit && $item->unit !== 'each') / {{ $item->unit }}@endif</div>
                @elseif($qrCodes[$item->id])
                    <div class="qr">{!! $qrCodes[$item->id] !!}<div><div class="price">{{ \App\Support\Money::format($item->price) }}</div><div class="muted">{{ $item->barcode ?: $item->sku }}</div></div></div>
                @else
                    <div class="price">{{ \App\Support\Money::format($item->price) }}@if($item->unit && $item->unit !== 'each') / {{ $item->unit }}@endif</div>
                    <div class="muted">No barcode yet</div>
                @endif
            </div>
        @endforeach
    </div>
</body>
</html>
