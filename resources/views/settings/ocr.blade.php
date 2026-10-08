@extends('layouts.app')
@section('title', 'Document capture settings')
@section('content')
    <x-page-header title="Document capture settings" sub="Choose how scanned receipts, invoices and ID documents are read." :crumbs="['Scan documents' => route('captures.index'), 'Settings']" />

    <div class="row g-4">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('settings.ocr.update') }}" class="card" x-data="{ provider: {{ Js::from(old('provider', $current ?? '')) }} }">
                @csrf @method('PUT')
                <div class="card-header"><h5 class="card-title">How documents are read</h5></div>
                <div class="card-body">
                    <p class="fs-7 text-muted">Documents are read with your own account at the provider, so you pay them directly at their rates. Start with <strong>Test mode</strong> to try scanning and saving without paying for anything.</p>

                    <label class="border rounded p-3 mb-2 d-flex gap-2 align-items-start">
                        <input type="radio" class="form-check-input mt-1" name="provider" value="" x-model="provider">
                        <span><span class="fw-600">Off</span><span class="d-block fs-8 text-muted">Nobody can scan documents.</span></span>
                    </label>
                    @foreach($providers as $key => $row)
                        <div class="border rounded p-3 mb-2">
                            <label class="d-flex gap-2 align-items-start mb-0">
                                <input type="radio" class="form-check-input mt-1" name="provider" value="{{ $key }}" x-model="provider">
                                <span class="flex-grow-1">
                                    <span class="fw-600">{{ $row['provider']->label() }}</span>
                                    @if($current === $key && $row['configured'])
                                        <span class="badge bg-soft-success text-success ms-1">Active</span>
                                    @elseif($current === $key)
                                        <span class="badge bg-soft-warning text-warning ms-1">Needs its key</span>
                                    @endif
                                    <span class="d-block fs-8 text-muted">{{ $row['provider']->description() }}</span>
                                </span>
                            </label>
                            @if($row['provider']->fields())
                                <div class="row mt-3" x-show="provider === {{ Js::from($key) }}" x-cloak>
                                    @foreach($row['provider']->fields() as $field => $meta)
                                        <div class="col-md-6">
                                            @if($meta['secret'])
                                                <x-form.input name="{{ $key }}[{{ $field }}]" type="password" :label="$meta['label']" autocomplete="new-password"
                                                    :placeholder="$row['values'][$field] ? 'Saved '.$row['values'][$field].' · leave blank to keep' : ''" :help="$meta['help'] ?? null" />
                                            @else
                                                <x-form.input name="{{ $key }}[{{ $field }}]" :label="$meta['label']" :value="$row['values'][$field]" :help="$meta['help'] ?? null" />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                    <div class="fs-8 text-muted">Keys are stored encrypted.</div>
                </div>
                <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary"><x-icon name="check" /> Save settings</button></div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="card-title">How it works</h5></div>
                <div class="card-body fs-7">
                    <ol class="ps-3 mb-3">
                        <li class="mb-2">Upload a photo or PDF of a receipt, supplier invoice or ID document.</li>
                        <li class="mb-2">The text is read and the shop, date, total, VAT, invoice number or ID details are filled in.</li>
                        <li class="mb-2">You check and correct the details. Nothing is saved until you do.</li>
                        <li>Receipts and invoices become expenses (invoices also add the supplier as a contact); ID documents become contacts.</li>
                    </ol>
                    <p class="text-muted mb-0">Files stay private to your workspace. Members only see their own scans; owners, admins and managers see everyone's. The same file cannot be uploaded twice.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
