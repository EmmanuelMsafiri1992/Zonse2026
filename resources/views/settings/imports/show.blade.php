@extends('layouts.app')
@section('title', 'Import '.$run->filename)
@section('content')
    @php
        $summary = (array) $run->summary;
        $counts = $summary['counts'] ?? null;
        $actionPills = ['create' => ['success', 'Add'], 'update' => ['info', 'Update'], 'skip' => ['muted', 'Skip'], 'error' => ['danger', 'Problem']];
    @endphp
    <x-page-header :title="$run->filename" :sub="$target->label().' · '.number_format($run->total_rows).' '.\Illuminate\Support\Str::plural('row', $run->total_rows).' · '.$run->statusLabel()" :crumbs="['Settings' => route('settings.workspace.edit'), 'Import data' => route('settings.imports.index'), $run->filename]">
        @unless($run->isFinished())
            <form method="POST" action="{{ route('settings.imports.destroy', $run) }}" onsubmit="return confirm('Cancel this import? Nothing has been saved yet.')">
                @csrf @method('DELETE')
                <button class="btn btn-outline-secondary"><x-icon name="x" /> Cancel import</button>
            </form>
        @endunless
    </x-page-header>

    @if($run->isFinished())
        <div class="row g-3 mb-4">
            @foreach([['Added', $run->created_count, 'success'], ['Updated', $run->updated_count, 'info'], ['Skipped', $run->skipped_count, 'muted'], ['Not imported', $run->failed_count, 'danger']] as [$caption, $number, $tone])
                <div class="col-6 col-md-3"><div class="card"><div class="card-body">
                    <div class="text-muted fs-7">{{ $caption }}</div>
                    <div class="fs-3 fw-semibold">{{ number_format($number) }}</div>
                </div></div></div>
            @endforeach
        </div>

        <div class="card mb-4">
            <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                @if($run->status === 'undone')
                    <div class="me-auto"><x-pill status="cancelled">Undone</x-pill> <span class="fs-7 text-muted">on {{ $run->undone_at?->format('d M Y, H:i') }}. What this import added has been removed.</span></div>
                @else
                    <div class="me-auto fs-7 text-muted">Imported {{ $run->imported_at?->format('d M Y, H:i') }}@if($run->user) by {{ $run->user->name }}@endif.</div>
                    @if($target->listUrl())<a href="{{ $target->listUrl() }}" class="btn btn-outline-primary">View {{ strtolower($target->label()) }}</a>@endif
                    @if($run->created_count > 0)
                        <form method="POST" action="{{ route('settings.imports.undo', $run) }}" onsubmit="return confirm('Remove the {{ $run->created_count }} records this import added? Records already used elsewhere are kept.')">
                            @csrf
                            <button class="btn btn-outline-danger"><x-icon name="undo-2" /> Undo import</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>

        @if(! empty($summary['problems']))
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0"><x-icon name="triangle-alert" /> Rows to check</h5></div>
                <div class="z-table-wrap">
                    <table class="table z-table align-middle">
                        <thead><tr><th style="width:6rem">Row</th><th>What happened</th></tr></thead>
                        <tbody>
                        @foreach($summary['problems'] as $problem)
                            <tr>
                                <td class="fs-7">{{ $problem['line'] }}</td>
                                <td class="fs-7">
                                    @foreach($problem['errors'] as $message)<div class="text-danger">{{ $message }}</div>@endforeach
                                    @foreach($problem['warnings'] as $message)<div class="text-muted">{{ $message }}</div>@endforeach
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @else
        <form method="POST" action="{{ route('settings.imports.update', $run) }}" class="card mb-4">
            @csrf @method('PUT')
            <div class="card-header"><h5 class="card-title mb-0"><x-icon name="columns-3" /> Match the columns</h5></div>
            <div class="card-body">
                @if($run->sourceName())
                    <div class="alert alert-info fs-7">This looks like an export from <b>{{ $run->sourceName() }}</b>. Its columns have been matched for you. Check them below.</div>
                @endif
                <p class="fs-7 text-muted">For each field in Zonseo, choose the column in your file that holds it. Leave a field on "Don't import" if your file has no column for it.</p>
                <div class="z-table-wrap">
                    <table class="table z-table align-middle">
                        <thead><tr><th>Zonseo field</th><th style="min-width:14rem">Column in your file</th><th>First values</th></tr></thead>
                        <tbody>
                        @foreach($columns as $key => $column)
                            @php $field = str_replace('.', '__', $key); $current = old('mapping.'.$field, $run->mapping[$key] ?? null); @endphp
                            <tr>
                                <td><span class="fw-medium">{{ $column['label'] }}</span>@if($column['required'] ?? false)<span class="text-danger"> *</span>@endif
                                    @if(! empty($column['hint']))<div class="z-row-sub">{{ $column['hint'] }}</div>@endif</td>
                                <td>
                                    <select name="mapping[{{ $field }}]" class="form-select form-select-sm @error('mapping.'.$field) is-invalid @enderror" aria-label="Column for {{ $column['label'] }}">
                                        <option value="">Don't import</option>
                                        @foreach($run->headers as $index => $header)
                                            <option value="{{ $index }}" @selected($current !== null && $current !== '' && (int) $current === $index)>{{ $header }}</option>
                                        @endforeach
                                    </select>
                                    @error('mapping.'.$field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </td>
                                <td class="fs-7 text-muted">
                                    @if($current !== null && $current !== '')
                                        {{ \Illuminate\Support\Str::limit(collect($samples)->map(fn ($cells) => $cells[(int) $current] ?? '')->filter(fn ($value) => $value !== '')->implode(' · '), 80) ?: '(empty)' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <x-form.select name="options[duplicates]" label="When a row is already in Zonseo" :options="\App\Support\Import\Importer::DUPLICATE_MODES" :value="$run->options['duplicates'] ?? 'skip'" />
                    </div>
                    <div class="col-md-6">
                        <x-form.select name="options[date_order]" label="Dates in the file are written" :options="\App\Support\Import\Importer::DATE_ORDERS" :value="$run->options['date_order'] ?? 'dmy'" />
                    </div>
                    @foreach($extraOptions as $key => $option)
                        <div class="col-md-6">
                            <x-form.select :name="'options['.$key.']'" :label="$option['label']" :options="$option['options']" :value="$run->options[$key] ?? $option['default']" :help="$option['help'] ?? null" />
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="card-footer text-end">
                <button class="btn {{ $run->status === 'ready' ? 'btn-outline-primary' : 'btn-primary' }}"><x-icon name="file-check" /> {{ $run->status === 'ready' ? 'Check again' : 'Check the rows' }}</button>
            </div>
        </form>

        @if($run->status === 'ready' && $counts)
            <div class="card" id="preview">
                <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                    <h5 class="card-title mb-0 me-auto"><x-icon name="eye" /> Preview</h5>
                    <span class="fs-7"><b>{{ $counts['create'] }}</b> to add · <b>{{ $counts['update'] }}</b> to update · <b>{{ $counts['skip'] }}</b> to skip · <b class="{{ $counts['error'] ? 'text-danger' : '' }}">{{ $counts['error'] }}</b> with problems</span>
                </div>
                <div class="z-table-wrap">
                    <table class="table z-table align-middle">
                        <thead><tr><th style="width:5rem">Row</th><th style="width:7rem">Action</th><th>Record</th><th>Notes</th></tr></thead>
                        <tbody>
                        @foreach($summary['rows'] ?? [] as $row)
                            <tr>
                                <td class="fs-7">{{ $row['line'] }}</td>
                                <td><x-pill :status="$actionPills[$row['action']][0] ?? 'muted'">{{ $actionPills[$row['action']][1] ?? $row['action'] }}</x-pill></td>
                                <td class="fs-7">{{ $row['label'] ?: '—' }}</td>
                                <td class="fs-7">@foreach($row['messages'] as $message)<div class="{{ $row['action'] === 'error' ? 'text-danger' : 'text-muted' }}">{{ $message }}</div>@endforeach</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($run->total_rows > count($summary['rows'] ?? []))
                    <div class="card-body fs-7 text-muted border-top">Showing the first {{ count($summary['rows'] ?? []) }} of {{ number_format($run->total_rows) }} rows. The counts above cover them all.</div>
                @endif
                <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
                    <span class="fs-7 text-muted me-auto">@if($counts['error'])Rows with problems are left out. Fix them in your file and import them again later.@else Everything looks good.@endif</span>
                    @php $toSave = $counts['create'] + $counts['update']; @endphp
                    <form method="POST" action="{{ route('settings.imports.run', $run) }}">
                        @csrf
                        <button class="btn btn-primary" @disabled($toSave === 0)><x-icon name="upload" /> Import {{ number_format($toSave) }} {{ \Illuminate\Support\Str::plural('row', $toSave) }}</button>
                    </form>
                </div>
            </div>
        @endif
    @endif
@endsection
