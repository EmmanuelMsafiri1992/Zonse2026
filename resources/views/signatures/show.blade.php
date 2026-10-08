@extends('layouts.app')
@section('title', $signatureRequest->title)
@section('content')
    <x-page-header :title="$signatureRequest->title" :sub="'Sent by '.($signatureRequest->creator?->name ?? 'someone').' on '.$signatureRequest->created_at->format('d M Y H:i').' · '.\App\Models\SignatureRequest::ORDERS[$signatureRequest->signing_order]"
                   :crumbs="['E-signatures' => route('signatures.index'), $signatureRequest->title]">
        <a href="{{ route('signatures.document', $signatureRequest) }}" target="_blank" class="btn btn-white"><x-icon name="file-text" /> Document</a>
        @if($signatureRequest->certificate_path)
            <a href="{{ route('signatures.certificate', $signatureRequest) }}" target="_blank" class="btn btn-primary"><x-icon name="file-check" /> Certificate</a>
        @endif
        @if($canManage && $signatureRequest->isPending())
            @unless($signatureRequest->isOverdue())
                <form method="POST" action="{{ route('signatures.remind', $signatureRequest) }}">@csrf<button class="btn btn-white"><x-icon name="bell-ring" /> Send reminder</button></form>
            @endunless
            <form method="POST" action="{{ route('signatures.cancel', $signatureRequest) }}" onsubmit="return confirm('Cancel this signature request? The signing links will stop working.')">@csrf<button class="btn btn-white text-danger"><x-icon name="ban" /> Cancel</button></form>
        @endif
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="card-title mb-0">Signers</h5>
                        <x-pill :status="$signatureRequest->pillStatus()">{{ $signatureRequest->statusLabel() }}</x-pill>
                    </div>
                    @if($signatureRequest->isPending() && $signatureRequest->expires_at)
                        <span @class(['fs-8', 'text-danger' => $signatureRequest->isOverdue(), 'text-muted' => ! $signatureRequest->isOverdue()])>
                            {{ $signatureRequest->isOverdue() ? 'Deadline passed '.$signatureRequest->expires_at->diffForHumans() : 'Sign by '.$signatureRequest->expires_at->format('d M Y') }}
                        </span>
                    @endif
                </div>
                <ul class="list-group list-group-flush">
                    @foreach($signatureRequest->signers as $signer)
                        <li class="list-group-item">
                            <div class="d-flex gap-3 align-items-start flex-wrap">
                                <div class="fs-8 text-muted pt-1" style="width:18px">{{ $signer->position }}</div>
                                <div class="flex-grow-1">
                                    <div class="fw-600">{{ $signer->name }}</div>
                                    <div class="fs-8 text-muted">{{ $signer->email }}</div>
                                    @if($signer->status === 'signed')
                                        <div class="fs-8 text-success">Signed {{ $signer->signed_at->format('d M Y H:i') }} as “{{ $signer->signed_name }}” from {{ $signer->ip_address }}</div>
                                    @elseif($signer->status === 'declined')
                                        <div class="fs-8 text-danger">Declined {{ $signer->declined_at->format('d M Y H:i') }}@if($signer->decline_reason): “{{ $signer->decline_reason }}”@endif</div>
                                    @elseif($signer->viewed_at)
                                        <div class="fs-8 text-muted">Opened {{ $signer->viewed_at->diffForHumans() }}</div>
                                    @elseif($signatureRequest->isPending() && ! in_array($signer->id, $current, true))
                                        <div class="fs-8 text-muted">Gets the link once the signers before them have signed</div>
                                    @endif
                                </div>
                                @if($signer->status === 'signed')
                                    @if($signer->signatureImage())
                                        <img src="{{ $signer->signatureImage() }}" alt="Signature of {{ $signer->name }}" style="max-height:48px;max-width:180px">
                                    @else
                                        <span style="font-family:'Brush Script MT','Segoe Script',cursive;font-size:22px">{{ $signer->signature_data }}</span>
                                    @endif
                                @else
                                    <div class="d-flex align-items-center gap-2">
                                        <x-pill :status="$signer->status === 'declined' ? 'rejected' : ($signer->status === 'viewed' ? 'open' : 'pending')">{{ $signer->statusLabel() }}</x-pill>
                                        @if($canManage && in_array($signer->id, $current, true))
                                            <button type="button" class="btn btn-sm btn-icon btn-white" title="Copy signing link" onclick="navigator.clipboard.writeText({{ Js::from($signer->signingUrl()) }}).then(() => zonseo.toast({{ Js::from('Signing link for '.$signer->name.' copied') }}))"><x-icon name="copy" class="zi zi-sm" /></button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title">History</h5></div>
                <ul class="list-group list-group-flush">
                    @foreach($signatureRequest->events as $event)
                        <li class="list-group-item fs-7 d-flex justify-content-between gap-3">
                            <span>{{ $event->description }}</span>
                            <span class="fs-8 text-muted text-nowrap">{{ $event->created_at->format('d M Y H:i') }}@if($event->ip_address) · {{ $event->ip_address }}@endif</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body fs-7">
                    <div class="fs-8 text-muted text-uppercase fw-600 mb-1">Document</div>
                    <div class="fw-600 text-break">{{ $signatureRequest->document_name }}</div>
                    <div class="fs-8 text-muted mb-2">{{ number_format($signatureRequest->document_size / 1024, 0) }} KB</div>
                    <div class="fs-8 text-muted">SHA-256 fingerprint</div>
                    <code class="fs-8 text-break d-block mb-2">{{ $signatureRequest->document_hash }}</code>
                    @if($intact)
                        <div class="fs-8 text-success"><x-icon name="shield-check" class="zi zi-sm" /> File unchanged since it was sent</div>
                    @else
                        <div class="fs-8 text-danger"><x-icon name="shield-alert" class="zi zi-sm" /> The stored file no longer matches its fingerprint</div>
                    @endif
                    @if($signatureRequest->signable && method_exists($signatureRequest->signable, 'activityUrl'))
                        <hr>
                        <div class="fs-8 text-muted">Made from</div>
                        <a href="{{ $signatureRequest->signable->activityUrl() }}">{{ class_basename($signatureRequest->signable) }} {{ $signatureRequest->signable->number ?? '' }}</a>
                    @endif
                </div>
            </div>
            @if($signatureRequest->message)
                <div class="card mb-3"><div class="card-body fs-7">
                    <div class="fs-8 text-muted text-uppercase fw-600 mb-1">Message to signers</div>
                    <div style="white-space:pre-line">{{ $signatureRequest->message }}</div>
                </div></div>
            @endif
        </div>
    </div>
@endsection
