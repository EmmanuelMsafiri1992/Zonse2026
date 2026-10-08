@extends('layouts.app')
@section('title', 'E-signatures')
@section('content')
    <x-page-header title="E-signatures" sub="Send a PDF to be signed online, and keep a certificate of who signed and when.">
        @if($canSend)
            <a href="{{ route('signatures.create') }}" class="btn btn-primary"><x-icon name="plus" /> Send for signing</a>
        @endif
    </x-page-header>

    <ul class="nav nav-pills mb-3 gap-1">
        @foreach(\App\Http\Controllers\SignatureRequestController::TABS as $key => $label)
            <li class="nav-item">
                <a href="{{ route('signatures.index', ['tab' => $key]) }}" @class(['nav-link', 'active' => $tab === $key])>
                    {{ $label }}
                    @php $count = match ($key) { 'pending' => $counts['pending'] ?? 0, 'completed' => $counts['completed'] ?? 0, default => null }; @endphp
                    @if($count)<span class="badge bg-soft-secondary text-secondary ms-1">{{ $count }}</span>@endif
                </a>
            </li>
        @endforeach
    </ul>

    <div class="card">
        @if($requests->isEmpty())
            <div class="card-body">
                <x-empty icon="file-signature" :title="$tab === 'pending' ? 'Nothing waiting for signatures' : 'Nothing here yet'"
                         text="Upload a contract, agreement or form as a PDF, add who must sign, and each person gets a private link to sign on any phone or computer." />
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle mb-0">
                    <thead><tr><th>Document</th><th>Signers</th><th>Sent</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach($requests as $signatureRequest)
                        @php $signed = $signatureRequest->signers->where('status', 'signed')->count(); @endphp
                        <tr>
                            <td class="z-row-title">
                                <a href="{{ route('signatures.show', $signatureRequest) }}">{{ $signatureRequest->title }}</a>
                                <div class="fs-8 text-muted">{{ $signatureRequest->document_name }}</div>
                            </td>
                            <td class="fs-7">
                                {{ $signatureRequest->signers->pluck('name')->join(', ') }}
                                <div class="fs-8 text-muted">{{ $signed }} of {{ $signatureRequest->signers->count() }} signed</div>
                            </td>
                            <td class="fs-7">{{ $signatureRequest->creator?->name ?? '—' }}<div class="fs-8 text-muted">{{ $signatureRequest->created_at->format('d M Y') }}</div></td>
                            <td>
                                <x-pill :status="$signatureRequest->pillStatus()">{{ $signatureRequest->statusLabel() }}</x-pill>
                                @if($signatureRequest->isPending() && $signatureRequest->expires_at)
                                    <div @class(['fs-8', 'text-danger' => $signatureRequest->isOverdue(), 'text-muted' => ! $signatureRequest->isOverdue()])>
                                        {{ $signatureRequest->isOverdue() ? 'Past its deadline' : 'Due '.$signatureRequest->expires_at->format('d M Y') }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($requests->hasPages())<div class="card-footer">{{ $requests->links() }}</div>@endif
        @endif
    </div>
@endsection
