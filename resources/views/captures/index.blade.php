@extends('layouts.app')
@section('title', 'Scan documents')
@section('content')
    <x-page-header title="Scan documents" sub="Photograph a receipt, supplier invoice or ID card. The details are read for you to check, then saved as an expense or a contact.">
        @if($canConfigure)
            <a href="{{ route('settings.ocr.edit') }}" class="btn btn-white"><x-icon name="settings" /> Settings</a>
        @endif
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Upload</h5></div>
                @if($provider)
                    <form method="POST" action="{{ route('captures.store') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="card-body">
                            <div class="form-label">What is it?</div>
                            @foreach($types as $key => $type)
                                <label class="border rounded p-2 mb-2 d-flex gap-2 align-items-start">
                                    <input type="radio" class="form-check-input mt-1" name="type" value="{{ $key }}" @checked(old('type', 'receipt') === $key)>
                                    <span><span class="fw-600">{{ $type['label'] }}</span><span class="d-block fs-8 text-muted">{{ $type['hint'] }}</span></span>
                                </label>
                            @endforeach
                            @error('type')<div class="text-danger fs-8 mb-2">{{ $message }}</div>@enderror

                            <label for="f_files" class="form-label mt-2">Photos or PDFs</label>
                            <input type="file" name="files[]" id="f_files" multiple required accept="image/jpeg,image/png,image/webp,application/pdf"
                                   @class(['form-control', 'is-invalid' => $errors->has('files') || $errors->has('files.*')])>
                            @if($errors->has('files') || $errors->has('files.*'))
                                <div class="invalid-feedback">{{ $errors->first('files') ?: collect($errors->get('files.*'))->flatten()->first() }}</div>
                            @endif
                            <div class="form-text">Up to {{ \App\Http\Controllers\DocumentCaptureController::MAX_FILES }} at once, 10 MB each. On a phone this opens the camera. Lay the paper flat in good light.</div>
                        </div>
                        <div class="card-footer d-flex justify-content-between align-items-center">
                            <span class="fs-8 text-muted">Read by {{ $provider->label() }}</span>
                            <button class="btn btn-primary"><x-icon name="scan-text" /> Scan</button>
                        </div>
                    </form>
                @else
                    <div class="card-body">
                        <x-empty icon="scan-text" title="Not set up yet" :text="$canConfigure ? 'Choose how documents are read. Test mode is free and lets you try every step.' : 'Ask a workspace admin to switch on document capture in settings.'" class="py-2" />
                        @if($canConfigure)
                            <div class="text-center"><a href="{{ route('settings.ocr.edit') }}" class="btn btn-primary">Set up document capture</a></div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-8">
            <ul class="nav nav-pills mb-3 gap-1">
                @foreach(\App\Http\Controllers\DocumentCaptureController::TABS as $key => $label)
                    <li class="nav-item">
                        <a href="{{ route('captures.index', ['tab' => $key]) }}" @class(['nav-link', 'active' => $tab === $key])>
                            {{ $label }}
                            @if($key === 'review' && $toCheck)<span class="badge bg-soft-secondary text-secondary ms-1">{{ $toCheck }}</span>@endif
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="card">
                @if($captures->isEmpty())
                    <div class="card-body">
                        <x-empty icon="receipt" :title="$tab === 'review' ? 'Nothing to check' : 'Nothing here yet'" text="Scanned documents appear here until you save them." />
                    </div>
                @else
                    <div class="z-table-wrap">
                        <table class="table z-table align-middle mb-0">
                            <thead><tr><th>Document</th><th>Type</th><th class="text-end">Amount</th><th>Uploaded</th><th>Status</th></tr></thead>
                            <tbody>
                            @foreach($captures as $capture)
                                <tr>
                                    <td class="z-row-title">
                                        <a href="{{ route('captures.show', $capture) }}">{{ $capture->summary() }}</a>
                                        <div class="fs-8 text-muted">{{ $capture->file_name }}</div>
                                    </td>
                                    <td class="fs-7">{{ $capture->typeLabel() }}</td>
                                    <td class="text-end fs-7">
                                        @if($capture->field('total'))
                                            {{ \App\Support\Money::format($capture->field('total'), $capture->field('currency')) }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="fs-7">{{ $capture->creator?->name ?? '—' }}<div class="fs-8 text-muted">{{ $capture->created_at->format('d M Y H:i') }}</div></td>
                                    <td><x-pill :status="$capture->pillStatus()">{{ $capture->statusLabel() }}</x-pill></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($captures->hasPages())<div class="card-footer">{{ $captures->links() }}</div>@endif
                @endif
            </div>
        </div>
    </div>

    @if($captures->contains('status', 'processing'))
        <script>setTimeout(() => window.zonseo.reload(@js(request()->url())), 4000);</script>
    @endif
@endsection
