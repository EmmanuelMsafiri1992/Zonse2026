@extends('layouts.app')
@section('title', 'Import data')
@section('content')
    <x-page-header title="Import data" sub="Bring contacts, products and app records in from a spreadsheet or another system." :crumbs="['Settings' => route('settings.workspace.edit'), 'Import data']" />

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            @if($targets === [])
                <div class="card"><div class="card-body">
                    <x-empty icon="file-up" title="Nothing to import into yet" text="Switch on Contacts, Invoicing or an app first." />
                    <div class="text-center"><a href="{{ route('settings.modules.index') }}" class="btn btn-primary">Apps & modules</a></div>
                </div></div>
            @else
                <form method="POST" action="{{ route('settings.imports.store') }}" enctype="multipart/form-data" class="card"
                      x-data="{ target: @js(old('target', $selected)), templates: @js(collect($targets)->map(fn ($t) => ['xlsx' => route('settings.imports.template', [$t->key(), 'xlsx']), 'csv' => route('settings.imports.template', [$t->key(), 'csv'])])) }">
                    @csrf
                    <div class="card-header"><h5 class="card-title mb-0"><x-icon name="file-up" /> Start an import</h5></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="f_target" class="form-label">What are you importing?</label>
                            <select name="target" id="f_target" class="form-select @error('target') is-invalid @enderror" x-model="target">
                                @foreach(collect($targets)->groupBy(fn ($t) => $t instanceof \App\Support\Import\RecordTarget ? 'Apps' : 'Core') as $group => $items)
                                    <optgroup label="{{ $group === 'Core' ? 'Contacts & price list' : 'Apps' }}">
                                        @foreach($items as $target)
                                            <option value="{{ $target->key() }}" @selected(old('target', $selected) === $target->key())>{{ $target->label() }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('target')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">
                                Not sure of the layout? Download a template:
                                <a :href="templates[target]?.xlsx" href="#">Excel</a> · <a :href="templates[target]?.csv" href="#">CSV</a>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="f_file" class="form-label">File</label>
                            <input type="file" name="file" id="f_file" accept=".csv,.txt,.xlsx" class="form-control @error('file') is-invalid @enderror" required>
                            @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">CSV or Excel (.xlsx), up to 5 MB and {{ number_format($maxRows) }} rows. The first row must hold the column headings.</div>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button class="btn btn-primary"><x-icon name="upload" /> Upload and match columns</button>
                    </div>
                </form>
            @endif
        </div>
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0"><x-icon name="info" /> Moving from another system</h5></div>
                <div class="card-body fs-7">
                    <p>Export your customer, supplier and product lists from <b>QuickBooks</b>, <b>Sage</b> or <b>Xero</b> as CSV or Excel and upload them as they are. Their column headings are recognised and matched for you.</p>
                    <p>Nothing is saved until you have checked the preview. Rows with problems are left out and listed so you can fix them and import them again.</p>
                    <p class="mb-0">If an import goes wrong, open it below and press <b>Undo import</b> to remove what it added.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0"><x-icon name="history" /> Past imports</h5></div>
        @if($runs->isEmpty())
            <div class="card-body"><x-empty icon="file-spreadsheet" title="No imports yet" text="Imports you start appear here, with what each one added." /></div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle">
                    <thead><tr><th>File</th><th>Into</th><th>Status</th><th class="text-end">Added</th><th class="text-end">Updated</th><th class="text-end">Problems</th><th>Started</th></tr></thead>
                    <tbody>
                    @foreach($runs as $run)
                        <tr>
                            <td><a href="{{ route('settings.imports.show', $run) }}" class="z-row-title">{{ $run->filename }}</a>
                                <div class="z-row-sub">{{ number_format($run->total_rows) }} {{ \Illuminate\Support\Str::plural('row', $run->total_rows) }}@if($run->sourceName()) · {{ $run->sourceName() }} export @endif</div></td>
                            <td class="fs-7">{{ isset($targets[$run->target]) ? $targets[$run->target]->label() : $run->target }}</td>
                            <td><x-pill :status="['mapping' => 'draft', 'ready' => 'sent', 'done' => 'paid', 'undone' => 'cancelled'][$run->status] ?? 'draft'">{{ $run->statusLabel() }}</x-pill></td>
                            <td class="text-end fs-7">{{ $run->isFinished() ? number_format($run->created_count) : '—' }}</td>
                            <td class="text-end fs-7">{{ $run->isFinished() ? number_format($run->updated_count) : '—' }}</td>
                            <td class="text-end fs-7">{{ $run->isFinished() ? number_format($run->failed_count) : '—' }}</td>
                            <td class="fs-7 text-nowrap">{{ $run->created_at->format('d M Y, H:i') }}<div class="z-row-sub">{{ $run->user?->name }}</div></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $runs->links() }}</div>
        @endif
    </div>
@endsection
