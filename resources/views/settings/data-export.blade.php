@extends('layouts.app')
@section('title', 'Export all data')
@section('content')
    <x-page-header title="Export all data" sub="Download everything this workspace holds as spreadsheet (CSV) files in one ZIP." :crumbs="['Settings' => route('settings.workspace.edit'), 'Audit log' => route('settings.audit.index'), 'Export all data']" />

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-2">What you get</h5>
                    <p class="fs-7 text-muted">One CSV file per kind of record, plus your workspace profile, team members and the full audit log. Use it to keep your own copy, move to another system, or answer a customer's data-access request (GDPR, POPIA, Zimbabwe's Cyber and Data Protection Act).</p>
                    <p class="fs-7 text-muted">Passwords, payment gateway keys and SMS keys are never included.</p>

                    @if($canExport)
                        <form method="POST" action="{{ route('settings.data-export.store') }}">
                            @csrf
                            <button class="btn btn-primary"><x-icon name="download" /> Download ZIP</button>
                        </form>
                    @else
                        <div class="alert alert-warning fs-7 mb-0">Only the workspace owner can export all data.</div>
                    @endif

                    @if($lastExport)
                        <p class="fs-8 text-muted mt-3 mb-0">Last export: {{ $lastExport->created_at->format('d M Y, H:i') }} by {{ $lastExport->causer?->name ?? 'System' }}.</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Files in the export</h5></div>
                <div class="card-body fs-7">
                    <div class="d-flex flex-wrap gap-1">
                        <span class="z-chip">workspace.csv</span>
                        <span class="z-chip">members.csv</span>
                        @foreach($tables as $table)
                            <span class="z-chip">{{ $table }}.csv</span>
                        @endforeach
                        <span class="z-chip">audit_log.csv</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
