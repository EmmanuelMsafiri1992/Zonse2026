@extends('layouts.app')
@section('title', $capture->summary())
@section('content')
    <x-page-header :title="$capture->summary()" :sub="$capture->typeLabel().' · uploaded by '.($capture->creator?->name ?? 'someone').' on '.$capture->created_at->format('d M Y H:i')"
                   :crumbs="['Scan documents' => route('captures.index'), $capture->summary()]">
        <a href="{{ route('captures.file', $capture) }}" target="_blank" class="btn btn-white"><x-icon name="file-text" /> Original</a>
        <form method="POST" action="{{ route('captures.destroy', $capture) }}" onsubmit="return confirm('Delete this scan? Anything already saved from it is kept.')">
            @csrf @method('DELETE')
            <button class="btn btn-white text-danger"><x-icon name="trash-2" /> Delete</button>
        </form>
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h5 class="card-title">{{ $capture->file_name }}</h5><span class="fs-8 text-muted">{{ number_format($capture->file_size / 1024) }} KB</span></div>
                <div class="card-body p-2 text-center bg-light">
                    @if($capture->isImage())
                        <img src="{{ route('captures.file', $capture) }}" alt="Scanned {{ strtolower($capture->typeLabel()) }}" class="img-fluid rounded" style="max-height:75vh">
                    @else
                        <iframe src="{{ route('captures.file', $capture) }}" title="Scanned document" class="w-100 rounded border-0" style="height:75vh"></iframe>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            @if($capture->status === 'processing')
                <div class="card">
                    <div class="card-body text-center py-5">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <h5>Reading the document…</h5>
                        <p class="text-muted fs-7 mb-0">This usually takes a few seconds. The page refreshes by itself.</p>
                    </div>
                </div>
                <script>setTimeout(() => location.reload(), 3000);</script>
            @elseif($capture->status === 'failed')
                <div class="card">
                    <div class="card-body">
                        <x-empty icon="triangle-alert" title="The document could not be read" :text="$capture->error ?? 'Something went wrong.'" class="py-2" />
                        <form method="POST" action="{{ route('captures.retry', $capture) }}" class="text-center">@csrf<button class="btn btn-primary"><x-icon name="refresh-cw" /> Try again</button></form>
                    </div>
                </div>
            @elseif($capture->status === 'done')
                <div class="card mb-3">
                    <div class="card-body d-flex gap-3 align-items-center">
                        <x-icon name="circle-check" class="text-success" />
                        <div class="flex-grow-1">
                            <div class="fw-600">Saved</div>
                            @if($capture->result)
                                <div class="fs-7 text-muted">
                                    As {{ $capture->result instanceof \App\Models\Record ? 'expense '.$capture->result->number.' · '.$capture->result->title : 'contact '.$capture->result->name }}
                                </div>
                            @else
                                <div class="fs-7 text-muted">What it was saved as has since been deleted.</div>
                            @endif
                        </div>
                        @if($capture->result)
                            <a href="{{ $capture->result->activityUrl() }}" class="btn btn-white">Open</a>
                        @endif
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h5 class="card-title">What was saved</h5></div>
                    <table class="table mb-0 fs-7">
                        @foreach($capture->fieldDefinitions() as $key => $meta)
                            <tr><td class="text-muted" style="width:40%">{{ $meta['label'] }}</td><td>{{ $capture->field($key) ?? '—' }}</td></tr>
                        @endforeach
                    </table>
                </div>
            @else
                <form method="POST" action="{{ route('captures.update', $capture) }}" class="card">
                    @csrf @method('PUT')
                    <div class="card-header">
                        <h5 class="card-title">Check the details</h5>
                        <span class="fs-8 text-muted">Read by {{ $capture->provider === 'test' ? 'test mode' : $capture->provider }}</span>
                    </div>
                    <div class="card-body">
                        @if($capture->provider === 'test')
                            <div class="alert alert-info fs-7">Test mode: photos are filled with sample details so you can try the steps. Switch to a provider in settings to read real documents.</div>
                        @endif
                        @if($capture->error)
                            <div class="alert alert-warning fs-7">{{ $capture->error }}</div>
                        @endif
                        @if($possibleDuplicate)
                            <div class="alert alert-warning fs-7">
                                An expense for the same amount on the same day already exists:
                                <a href="{{ $possibleDuplicate->url() }}">{{ $possibleDuplicate->number }} · {{ $possibleDuplicate->title }}</a>. Make sure this is not the same receipt.
                            </div>
                        @endif

                        <div class="row">
                            @foreach($capture->fieldDefinitions() as $key => $meta)
                                <div class="col-md-6">
                                    @switch($meta['type'])
                                        @case('date')
                                            <x-form.input name="fields[{{ $key }}]" type="date" :label="$meta['label']" :value="$capture->field($key)" />
                                            @break
                                        @case('money')
                                            <x-form.input name="fields[{{ $key }}]" type="number" step="0.01" min="0" inputmode="decimal" :label="$meta['label']" :value="$capture->field($key)" />
                                            @break
                                        @case('currency')
                                            <x-form.select name="fields[{{ $key }}]" :label="$meta['label']" placeholder="—"
                                                :options="collect(array_unique(array_filter([...$currencies, $capture->field($key)])))->mapWithKeys(fn ($code) => [$code => $code])->all()" :value="$capture->field($key)" />
                                            @break
                                        @case('category')
                                            <x-form.select name="fields[{{ $key }}]" :label="$meta['label']"
                                                :options="collect($categories)->mapWithKeys(fn ($category) => [$category => ucfirst($category)])->all()" :value="$capture->field($key) ?? 'other'" />
                                            @break
                                        @default
                                            <x-form.input name="fields[{{ $key }}]" :label="$meta['label']" :value="$capture->field($key)" maxlength="190" />
                                    @endswitch
                                </div>
                            @endforeach
                            @if($capture->type === 'id_document')
                                <div class="col-md-6">
                                    <x-form.select name="contact_type" label="Save as" :options="$contactTypes" value="customer" />
                                </div>
                            @endif
                        </div>

                        @if($capture->raw_text)
                            <details class="mt-2">
                                <summary class="fs-7 text-muted">Text read from the document</summary>
                                <pre class="bg-light rounded p-2 mt-2 fs-8 mb-0" style="white-space:pre-wrap;max-height:260px;overflow:auto">{{ $capture->raw_text }}</pre>
                            </details>
                        @endif
                    </div>
                    <div class="card-footer d-flex justify-content-end flex-wrap gap-2">
                        <button name="action" value="save" class="btn btn-white">Save changes</button>
                        @if($capture->type === 'id_document')
                            <button name="action" value="contact" class="btn btn-primary"><x-icon name="user-plus" /> Save as contact</button>
                        @elseif($canSaveExpense)
                            <button name="action" value="expense" class="btn btn-primary"><x-icon name="receipt" /> Save as expense</button>
                        @else
                            <span class="fs-8 text-muted align-self-center">Switch on the Expenses app to save this as an expense.</span>
                        @endif
                    </div>
                </form>
            @endif
        </div>
    </div>
@endsection
