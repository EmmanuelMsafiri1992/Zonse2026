<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Verify {{ $document->fiscal_number }} · {{ config('app.name') }}</title>
    @include('partials.fonts')
    @include('partials.favicon')
    @vite(['resources/scss/app.scss'])
</head>
<body class="bg-body-tertiary">
@php
    $payload = $document->payload;
    $money = fn ($amount) => ($payload['currency'] ?? '').' '.number_format((float) $amount, 2);
    $live = ($payload['mode'] ?? 'test') !== 'test';
@endphp
<main class="container py-5" style="max-width: 560px">
    <div class="card">
        <div class="card-body p-4">
            <div class="d-flex align-items-start gap-3 mb-3">
                <div class="flex-grow-1">
                    <div class="fs-8 text-muted text-uppercase fw-600">{{ $document->authorityName() }} · {{ $document->typeLabel() }}</div>
                    <h1 class="h4 mb-1">{{ $payload['seller']['name'] }}</h1>
                    <div class="fs-7 text-muted">{{ \App\Support\Fiscal\Authorities::get($document->authority)['tax_id'] ?? 'Tax no.' }} {{ $payload['seller']['tax_id'] }}</div>
                </div>
                <div class="qr" style="width:96px">{!! $qr !!}</div>
            </div>

            @if($document->isSigned())
                <div class="alert alert-success d-flex gap-2 align-items-center"><x-icon name="shield-check" /> <div>Reported to {{ $document->authorityName() }} on {{ $document->signed_at->format('d M Y, H:i') }}.</div></div>
            @elseif($document->status === 'pending')
                <div class="alert alert-warning d-flex gap-2 align-items-center"><x-icon name="clock" /> <div>Issued and waiting to reach {{ $document->authorityName() }}.</div></div>
            @else
                <div class="alert alert-danger d-flex gap-2 align-items-center"><x-icon name="circle-alert" /> <div>{{ $document->authorityName() }} did not accept this document.</div></div>
            @endif
            @unless($live)
                <p class="fs-8 text-muted">Issued in test mode: nothing was sent to the real tax authority.</p>
            @endunless
            @if($creditNote)
                <div class="alert alert-secondary fs-7">This invoice was cancelled by credit note {{ $creditNote->fiscal_number }} on {{ $creditNote->created_at->format('d M Y') }}.</div>
            @endif

            <dl class="row fs-7 mb-0">
                <dt class="col-5 text-muted fw-normal">Fiscal number</dt><dd class="col-7 fw-600">{{ $document->fiscal_number }}</dd>
                <dt class="col-5 text-muted fw-normal">Verification code</dt><dd class="col-7 font-monospace">{{ $document->verification_code }}</dd>
                @if($payload['original_fiscal_number'] ?? null)
                    <dt class="col-5 text-muted fw-normal">Cancels</dt><dd class="col-7">{{ $payload['original_fiscal_number'] }}</dd>
                @endif
                <dt class="col-5 text-muted fw-normal">Invoice</dt><dd class="col-7">{{ $payload['invoice_number'] }}</dd>
                <dt class="col-5 text-muted fw-normal">Issued</dt><dd class="col-7">{{ \Illuminate\Support\Carbon::parse($payload['issued_at'])->format('d M Y, H:i') }}</dd>
                @if($payload['buyer']['name'] ?? null)
                    <dt class="col-5 text-muted fw-normal">Customer</dt><dd class="col-7">{{ $payload['buyer']['name'] }}</dd>
                @endif
                <dt class="col-5 text-muted fw-normal">Tax</dt><dd class="col-7">{{ $money($payload['tax_total']) }}</dd>
                <dt class="col-5 text-muted fw-normal">Total</dt><dd class="col-7 fw-600">{{ $money($payload['total']) }}</dd>
            </dl>
        </div>
    </div>
    <p class="fs-8 text-muted text-center mt-3">Check the total above matches your invoice or receipt. · {{ config('app.name') }}</p>
</main>
</body>
</html>
