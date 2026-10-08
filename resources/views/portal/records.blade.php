@extends('layouts.portal')
@section('title', 'My records')
@section('content')
    <h1 class="h4 mb-3">My records</h1>
    <div class="card">
        @if($records->isEmpty())
            <div class="card-body"><x-empty icon="folder-open" title="No records yet" class="py-2" /></div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Record</th><th>Type</th><th>Status</th><th class="text-end">Amount</th><th>Date</th></tr></thead>
                    <tbody>
                        @foreach($records as $record)
                            <tr>
                                <td><span class="fw-600">{{ $record->title }}</span>@if($record->number)<span class="d-block fs-8 text-muted">{{ $record->number }}</span>@endif</td>
                                <td>{{ $record->definition()->label }}</td>
                                <td>@if($record->status)<x-pill :status="$record->status">{{ $record->statusLabel() }}</x-pill>@endif</td>
                                <td class="text-end">{{ $record->amount !== null ? \App\Support\Money::format($record->amount, $record->currency ?? $portalWorkspace->currency_code) : '—' }}</td>
                                <td>{{ $record->created_at->format('d M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
