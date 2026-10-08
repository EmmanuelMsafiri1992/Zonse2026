@extends('layouts.app')
@section('title', $app->name.' reports')
@section('content')
    <x-page-header title="Reports" :sub="$app->name.' · '.$from->format('d M Y').' to '.$to->format('d M Y')"
                   :crumbs="['Apps' => route('apps.index'), $app->name => route('apps.show', $app->key), 'Reports']">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control form-control-sm" aria-label="From">
            <input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control form-control-sm" aria-label="To">
            <button class="btn btn-sm btn-primary">Apply</button>
        </form>
    </x-page-header>

    @if(empty($sections))
        <div class="card"><div class="card-body"><x-empty icon="chart-column" title="Nothing recorded in this period" /></div></div>
    @endif

    <div class="row g-3">
        @foreach($sections as $section)
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header"><h5 class="card-title">{{ $section['title'] }}</h5></div>
                    @if(empty($section['rows']))
                        <div class="card-body fs-7 text-muted">Nothing in this period.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 fs-7">
                                <thead><tr>@foreach($section['columns'] as $column)<th class="{{ $loop->first ? '' : 'text-end' }}">{{ $column }}</th>@endforeach</tr></thead>
                                <tbody>
                                    @foreach($section['rows'] as $row)
                                        <tr>@foreach(array_values($row) as $cell)<td class="{{ $loop->first ? '' : 'text-end' }}">{{ $cell }}</td>@endforeach</tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @isset($section['note'])<div class="card-body fs-8 text-muted pt-2">{{ $section['note'] }}</div>@endisset
                </div>
            </div>
        @endforeach
    </div>
@endsection
