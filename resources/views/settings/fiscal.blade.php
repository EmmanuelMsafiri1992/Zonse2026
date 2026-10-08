@extends('layouts.app')
@section('title', 'Fiscalisation')
@section('content')
    <x-page-header title="Fiscalisation" sub="Report invoices and receipts to your tax authority as they are issued." :crumbs="['Settings' => route('settings.workspace.edit'), 'Fiscalisation']">
        <a href="{{ route('settings.fiscal.log') }}" class="btn btn-white"><x-icon name="scroll-text" /> Fiscal log</a>
    </x-page-header>

    @unless($invoicingReady)
        <div class="alert alert-info">Fiscalisation reports invoices, so it needs the Invoicing app. Switch it on under <a href="{{ route('settings.modules.index') }}">Apps</a>.</div>
    @endunless

    <form method="POST" action="{{ route('settings.fiscal.update') }}"
          x-data="{ authorities: @js(\App\Support\Fiscal\Authorities::ALL), authority: @js(old('authority', $settings['authority'] ?? '')), enabled: @js((bool) old('enabled', $switchedOn)) }">
        @csrf
        @method('PUT')
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0"><x-icon name="landmark" /> Tax authority</h5></div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="enabled" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" id="f_enabled" name="enabled" value="1" x-model="enabled">
                            <label class="form-check-label" for="f_enabled">Report invoices to the tax authority</label>
                            <div class="form-text">Each invoice is reported when it is issued (marked as sent, or paid at the till). Reported invoices can no longer be edited or deleted; cancelling one issues a credit note.</div>
                        </div>

                        <x-form.select name="authority" label="Authority" :options="\App\Support\Fiscal\Authorities::options()" :value="$settings['authority']" placeholder="Choose…" x-model="authority" />
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="f_taxpayer_id" class="form-label" x-text="authorities[authority]?.tax_id ?? 'Taxpayer number'">Taxpayer number</label>
                                    <input type="text" name="taxpayer_id" id="f_taxpayer_id" value="{{ old('taxpayer_id', $settings['taxpayer_id'] ?? $workspace->tax_number) }}" maxlength="40"
                                           class="form-control @error('taxpayer_id') is-invalid @enderror">
                                    @error('taxpayer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text" x-show="authorities[authority]?.tax_id_hint" x-text="authorities[authority]?.tax_id_hint"></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="f_device_id" class="form-label" x-text="authorities[authority]?.device ?? 'Device ID'">Device ID</label>
                                    <input type="text" name="device_id" id="f_device_id" value="{{ old('device_id', $settings['device_id']) }}" maxlength="40"
                                           class="form-control @error('device_id') is-invalid @enderror">
                                    @error('device_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">Optional. Becomes part of each fiscal number.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0"><x-icon name="flask-conical" /> Connection</h5></div>
                    <div class="card-body">
                        <x-form.select name="mode" label="Mode" :options="\App\Support\Fiscal\FiscalSettings::MODES" :value="$settings['mode']" required
                                       help="Sending to a live authority needs the certified device credentials it issues to your business. Until then, documents are numbered, chained and checked exactly as they would be, without leaving this platform." />
                        <x-form.check name="simulate_outage" label="Pretend the authority cannot be reached" :checked="$settings['simulate_outage']" switch
                                      help="Documents wait in the fiscal log and are sent again automatically every five minutes, or when you press Send waiting documents." />
                    </div>
                </div>

                <button class="btn btn-primary">Save</button>
            </div>

            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title mb-0">Documents</h5></div>
                    <div class="card-body fs-7">
                        @foreach(\App\Models\FiscalDocument::STATUSES as $status => $label)
                            <div class="d-flex justify-content-between mb-1"><span>{{ $label }}</span><a href="{{ route('settings.fiscal.log', ['status' => $status]) }}" class="fw-600">{{ $counts[$status] ?? 0 }}</a></div>
                        @endforeach
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">How it works</h5></div>
                    <div class="card-body fs-7">
                        <p>Every invoice and credit note gets a running fiscal number and a verification code. Each one is sealed with a hash that also covers the document before it, so nothing can be changed or removed without the chain showing it.</p>
                        <p class="mb-0">Invoices, PDFs and till receipts carry a QR code that opens a page where your customer can check the document was reported.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
