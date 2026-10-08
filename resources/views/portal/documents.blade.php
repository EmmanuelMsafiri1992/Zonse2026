@extends('layouts.portal')
@section('title', 'Documents to sign')
@section('content')
    <h1 class="h4 mb-3">Waiting for your signature</h1>
    <div class="card mb-4">
        @if($waiting->isEmpty())
            <div class="card-body"><x-empty icon="file-signature" title="Nothing to sign" class="py-2" /></div>
        @else
            <ul class="list-group list-group-flush">
                @foreach($waiting as $signer)
                    <li class="list-group-item py-3 d-flex align-items-center gap-3">
                        <div class="me-auto">
                            <div class="fw-600">{{ $signer->request->title }}</div>
                            <div class="fs-8 text-muted">Sent {{ $signer->request->created_at->format('d M Y') }}@if($signer->request->expires_at) · sign by {{ $signer->request->expires_at->format('d M Y') }}@endif</div>
                        </div>
                        <a href="{{ $signer->signingUrl() }}" class="btn btn-sm btn-primary">Review &amp; sign</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if($signed->isNotEmpty())
        <h2 class="h5 mb-3">Signed</h2>
        <div class="card">
            <ul class="list-group list-group-flush">
                @foreach($signed as $signer)
                    <li class="list-group-item py-3 d-flex align-items-center gap-3">
                        <div class="me-auto">
                            <div class="fw-600">{{ $signer->request->title }}</div>
                            <div class="fs-8 text-muted">Signed {{ $signer->signed_at?->format('d M Y') }}</div>
                        </div>
                        <a href="{{ $signer->signingUrl() }}" class="btn btn-sm btn-white">View</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
