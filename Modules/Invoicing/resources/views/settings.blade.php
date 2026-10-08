@extends('layouts.app')
@section('title', 'Sales settings')
@section('content')
    <x-page-header title="Sales settings" sub="Numbering, default terms and the tax rates you charge." :crumbs="['Settings' => route('settings.workspace.edit'), 'Sales']" />

    <div class="row g-3">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('settings.invoicing.update') }}" class="card mb-3">
                @csrf @method('PUT')
                <div class="card-header"><h5 class="card-title">Numbering & defaults</h5></div>
                <div class="card-body">
                    @foreach(['invoice' => 'Invoices', 'quote' => 'Quotes', 'payment' => 'Payment receipts'] as $key => $label)
                        <div class="row align-items-end">
                            <div class="col-md-4"><div class="form-label mb-3 fw-600">{{ $label }}</div></div>
                            <div class="col-md-4"><x-form.input name="{{ $key }}_prefix" label="Prefix" :value="$sequences[$key]['prefix']" required /></div>
                            <div class="col-md-4"><x-form.input name="{{ $key }}_next" type="number" min="1" label="Next number" :value="$sequences[$key]['next_number']" required /></div>
                        </div>
                    @endforeach
                    <hr>
                    <div class="row">
                        <div class="col-md-6"><x-form.input name="due_days" type="number" min="0" max="365" label="Invoices due after (days)" :value="$settings['due_days']" required /></div>
                        <div class="col-md-6"><x-form.input name="quote_valid_days" type="number" min="1" max="365" label="Quotes valid for (days)" :value="$settings['quote_valid_days']" required /></div>
                    </div>
                    <x-form.textarea name="terms" label="Default terms & conditions" :value="$settings['terms']" rows="3" />
                    <x-form.textarea name="notes" label="Default invoice note" :value="$settings['notes']" rows="2" help="For example your bank details or mobile money number." />
                    <x-form.input name="footer" label="Footer line on printed documents" :value="$settings['footer']" placeholder="Thank you for your business." />
                </div>
                <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary"><x-icon name="check" /> Save settings</button></div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Tax rates</h5></div>
                @if($taxRates->isEmpty())
                    <div class="card-body"><x-empty icon="percent" title="No tax rates" text="Add VAT or sales tax so lines can be taxed automatically." class="py-2" /></div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($taxRates as $rate)
                            <li class="list-group-item">
                                <form method="POST" action="{{ route('settings.invoicing.tax-rates.update', $rate) }}" class="d-flex gap-2 align-items-center flex-wrap">
                                    @csrf @method('PUT')
                                    <input type="text" name="name" value="{{ $rate->name }}" class="form-control form-control-sm" style="width:130px" required>
                                    <div class="input-group input-group-sm" style="width:110px"><input type="number" step="0.01" min="0" max="100" name="rate" value="{{ rtrim(rtrim(number_format($rate->rate, 2, '.', ''), '0'), '.') }}" class="form-control text-end" required><span class="input-group-text">%</span></div>
                                    <label class="form-check form-check-inline fs-8 mb-0"><input type="checkbox" class="form-check-input" name="is_default" value="1" @checked($rate->is_default)> Default</label>
                                    <label class="form-check form-check-inline fs-8 mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked($rate->is_active)> Active</label>
                                    <span class="ms-auto d-inline-flex gap-1">
                                        <button class="btn btn-sm btn-icon btn-soft-secondary" title="Save"><x-icon name="check" class="zi zi-sm" /></button>
                                        <button type="submit" form="delRate{{ $rate->id }}" class="btn btn-sm btn-icon btn-soft-danger" title="Remove"><x-icon name="trash-2" class="zi zi-sm" /></button>
                                    </span>
                                </form>
                                <form id="delRate{{ $rate->id }}" method="POST" action="{{ route('settings.invoicing.tax-rates.destroy', $rate) }}" onsubmit="return confirm('Remove {{ $rate->name }}?')">@csrf @method('DELETE')</form>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ route('settings.invoicing.tax-rates.store') }}" class="card-footer d-flex gap-2 align-items-end flex-wrap">
                    @csrf
                    <div class="flex-grow-1"><label class="form-label fs-8 mb-1">New rate</label><input type="text" name="name" value="{{ old('name') }}" class="form-control form-control-sm" placeholder="VAT" required></div>
                    <div style="width:110px"><label class="form-label fs-8 mb-1">Rate</label><div class="input-group input-group-sm"><input type="number" step="0.01" min="0" max="100" name="rate" value="{{ old('rate') }}" class="form-control text-end" required><span class="input-group-text">%</span></div></div>
                    <label class="form-check fs-8 mb-2"><input type="checkbox" class="form-check-input" name="is_default" value="1" @checked($taxRates->isEmpty())> Default</label>
                    <button class="btn btn-sm btn-primary"><x-icon name="plus" class="zi zi-sm" /> Add</button>
                </form>
            </div>
        </div>
    </div>
@endsection
