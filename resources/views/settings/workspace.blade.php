@extends('layouts.app')
@section('title', 'Workspace settings')
@section('content')
    <x-page-header title="Workspace settings" sub="Name, contact details, currency and branding used across all your apps." :crumbs="['Settings' => route('settings.workspace.edit'), 'General']" />

    <form method="POST" action="{{ route('settings.workspace.update') }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <div class="z-form-section">
                            <div class="z-form-section-title">Identity</div>
                            <div class="z-form-section-sub">Shown on invoices, receipts, portals and emails.</div>
                            <div class="row">
                                <div class="col-md-8"><x-form.input name="name" label="Workspace name" :value="$workspace->name" required /></div>
                                <div class="col-md-4"><x-form.select name="type" label="Type" :options="$types" :value="$workspace->type" required /></div>
                            </div>
                            <div class="row">
                                <div class="col-md-6"><x-form.input name="email" label="Email" type="email" :value="$workspace->email" /></div>
                                <div class="col-md-6"><x-form.input name="phone" label="Phone" :value="$workspace->phone" /></div>
                            </div>
                            <x-form.input name="website" label="Website" :value="$workspace->website" placeholder="https://" />
                            <x-form.input name="tax_number" label="Tax / VAT number" :value="$workspace->tax_number" />
                        </div>
                        <div class="z-form-section">
                            <div class="z-form-section-title">Address</div>
                            <x-form.input name="address" label="Street address" :value="$workspace->address" />
                            <div class="row">
                                <div class="col-md-6"><x-form.input name="city" label="City" :value="$workspace->city" /></div>
                                <div class="col-md-6"><x-form.select name="country_code" label="Country" :options="$countries" :value="$workspace->country_code" required /></div>
                            </div>
                        </div>
                        <div class="z-form-section">
                            <div class="z-form-section-title">Localisation</div>
                            <div class="row">
                                <div class="col-md-6"><x-form.select name="currency_code" label="Main currency" :options="$currencies" :value="$workspace->currency_code" required /></div>
                                <div class="col-md-6"><x-form.select name="timezone" label="Time zone" :options="$timezones" :value="$workspace->timezone" required /></div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary"><x-icon name="save" /> Save changes</button>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h5 class="card-title">Logo</h5></div>
                    <div class="card-body text-center">
                        @if($workspace->logo_url)
                            <img src="{{ $workspace->logo_url }}" alt="Logo" class="img-fluid rounded-10 mb-3" style="max-height:120px">
                            <x-form.check name="remove_logo" label="Remove current logo" />
                        @else
                            <div class="z-avatar z-avatar-lg z-avatar-soft mx-auto mb-3 rounded-3">{{ $workspace->initials }}</div>
                        @endif
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <div class="form-text">PNG, JPG or SVG, up to 2 MB.</div>
                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-header"><h5 class="card-title">Workspace ID</h5></div>
                    <div class="card-body fs-7 text-muted">
                        <div><strong>Slug:</strong> {{ $workspace->slug }}</div>
                        <div class="text-break"><strong>UUID:</strong> {{ $workspace->uuid }}</div>
                        <div><strong>Created:</strong> {{ $workspace->created_at->toFormattedDateString() }}</div>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
