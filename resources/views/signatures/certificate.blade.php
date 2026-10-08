<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Signing certificate · {{ $signatureRequest->title }}</title>
    <style>
        @page { margin: 32px 36px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #323338; }
        h1 { font-size: 18px; margin: 0 0 2px; } h2 { font-size: 12px; margin: 18px 0 6px; color: #0073ea; text-transform: uppercase; letter-spacing: .5px; }
        .muted { color: #676879; }
        table { width: 100%; border-collapse: collapse; }
        .facts td { padding: 4px 6px; border-bottom: 1px solid #e6e9ef; vertical-align: top; }
        .facts td.k { width: 28%; color: #676879; }
        .grid th { text-align: left; font-size: 9px; color: #676879; border-bottom: 1px solid #c5c7d0; padding: 5px 6px; }
        .grid td { padding: 6px; border-bottom: 1px solid #e6e9ef; vertical-align: top; }
        .sig { height: 46px; max-width: 170px; }
        .typed { font-family: "DejaVu Serif", serif; font-style: italic; font-size: 16px; }
        .hash { font-family: "DejaVu Sans Mono", monospace; font-size: 9px; word-wrap: break-word; }
        .stamp { border: 1px solid #00854d; color: #00854d; padding: 6px 10px; display: inline-block; font-weight: bold; }
        .footer { margin-top: 18px; font-size: 8px; color: #676879; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                <div class="muted">{{ $signatureRequest->workspace->name }}</div>
                <h1>Certificate of completion</h1>
                <div>{{ $signatureRequest->title }}</div>
            </td>
            <td style="text-align:right; vertical-align:top">
                @if($signatureRequest->status === 'completed')<span class="stamp">SIGNED BY ALL PARTIES</span>@endif
            </td>
        </tr>
    </table>

    <h2>Document</h2>
    <table class="facts">
        <tr><td class="k">File</td><td>{{ $signatureRequest->document_name }} ({{ number_format($signatureRequest->document_size) }} bytes)</td></tr>
        <tr><td class="k">SHA-256 fingerprint</td><td class="hash">{{ $signatureRequest->document_hash }}</td></tr>
        <tr><td class="k">Reference</td><td class="hash">{{ $signatureRequest->uuid }}</td></tr>
        <tr><td class="k">Sent by</td><td>{{ $signatureRequest->creator?->name ?? '—' }}{{ $signatureRequest->creator ? ' <'.$signatureRequest->creator->email.'>' : '' }}</td></tr>
        <tr><td class="k">Sent</td><td>{{ $signatureRequest->created_at->format('d M Y H:i:s T') }}</td></tr>
        <tr><td class="k">Completed</td><td>{{ $signatureRequest->completed_at?->format('d M Y H:i:s T') ?? '—' }}</td></tr>
        <tr><td class="k">Signing order</td><td>{{ \App\Models\SignatureRequest::ORDERS[$signatureRequest->signing_order] ?? $signatureRequest->signing_order }}</td></tr>
    </table>

    <h2>Signatures</h2>
    <table class="grid">
        <thead><tr><th style="width:30%">Signer</th><th style="width:32%">Signature</th><th>Details</th></tr></thead>
        <tbody>
        @foreach($signatureRequest->signers as $signer)
            <tr>
                <td><strong>{{ $signer->name }}</strong><br><span class="muted">{{ $signer->email }}</span></td>
                <td>
                    @if($signer->signatureImage())
                        <img src="{{ $signer->signatureImage() }}" class="sig" alt="">
                    @elseif($signer->signature_data)
                        <span class="typed">{{ $signer->signature_data }}</span>
                    @else
                        <span class="muted">{{ $signer->statusLabel() }}</span>
                    @endif
                </td>
                <td>
                    @if($signer->signed_at)
                        Signed as “{{ $signer->signed_name }}”<br>
                        {{ $signer->signed_at->format('d M Y H:i:s T') }}<br>
                        {{ $signer->signature_type === 'draw' ? 'Drawn' : 'Typed' }} signature · IP {{ $signer->ip_address ?? 'unknown' }}<br>
                        <span class="muted">{{ \Illuminate\Support\Str::limit($signer->user_agent, 90) }}</span>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>History</h2>
    <table class="grid">
        <thead><tr><th style="width:24%">Time</th><th>Event</th><th style="width:18%">IP address</th></tr></thead>
        <tbody>
        @foreach($signatureRequest->events as $event)
            <tr>
                <td>{{ $event->created_at->format('d M Y H:i:s T') }}</td>
                <td>{{ $event->description }}</td>
                <td>{{ $event->ip_address ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="footer">
        Each signer opened a private link sent to their email address and agreed to sign electronically before signing.
        To check a copy of the document, compute its SHA-256 fingerprint and compare it with the one above; any change to the file gives a different fingerprint.
        Issued {{ now()->format('d M Y H:i:s T') }} by {{ config('app.name') }}.
    </div>
</body>
</html>
