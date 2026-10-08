{{-- Printable app document. Data: $heading, $meta (label => value), $columns, $rows, $totals (label => value), $notes --}}
@extends('invoicing::layouts.print')
@section('title', $documentTitle.' '.$record->number)
@section('toolbar')
    <a href="{{ $record->url() }}" class="btn">Back to {{ strtolower($def->label) }}</a>
@endsection
@section('content')
    <div class="head">
        <div class="brand">
            @if($workspace?->logo_url)<img src="{{ $workspace->logo_url }}" alt="{{ $workspace->name }}"><br>@endif
            <h1>{{ $workspace?->name }}</h1>
            {!! implode('<br>', array_map('e', array_filter([$workspace?->address, $workspace?->city, $workspace?->phone, $workspace?->email]))) !!}
        </div>
        <div class="doc-title">
            <h2>{{ $heading ?? $documentTitle }}</h2>
            <div class="num">{{ $record->number }}</div>
        </div>
    </div>

    @if(! empty($meta))
        <div class="meta" style="margin-top:28px">
            @foreach($meta as $label => $value)
                <div><b>{{ $label }}</b>{{ $value }}</div>
            @endforeach
        </div>
    @endif

    @if(! empty($columns))
        <table>
            <thead><tr>@foreach($columns as $i => $column)<th class="{{ $loop->last ? 'r' : '' }}">{{ $column }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse($rows as $row)
                    <tr>@foreach(array_values($row) as $cell)<td class="{{ $loop->last ? 'r' : '' }}">{{ $cell }}</td>@endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($columns) }}" style="color:var(--muted)">Nothing recorded for this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    @if(! empty($totals))
        <div class="totals">
            @foreach($totals as $label => $value)
                <div class="{{ $loop->last ? 'grand' : '' }}"><span>{{ $label }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
    @endif

    @if(! empty($notes))<div class="notes"><p>{{ $notes }}</p></div>@endif
    <div class="foot">{{ $workspace?->name }} · printed {{ now()->format('d M Y') }}</div>
@endsection
