@extends('layouts.app')
@section('title', 'Branding')
@section('content')
    <x-page-header title="Branding" sub="Your name, colour and web address across the workspace and its sign-in pages." :crumbs="['Settings' => route('settings.workspace.edit'), 'Branding']" />

    <div class="row g-4">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('settings.branding.update') }}" class="card" x-data="{ color: {{ Js::from(old('brand_color', $brandColor ?? \App\Support\Branding::DEFAULT_COLOR)) }} }">
                @csrf @method('PUT')
                <div class="card-header"><h5 class="card-title">Look and feel</h5></div>
                <div class="card-body">
                    @if($workspace->reseller && $workspace->reseller->isWhiteLabelPartner())
                        <div class="alert alert-info fs-7">This workspace is managed by <strong>{{ $workspace->reseller->name }}</strong>. Its branding applies unless you set your own here.</div>
                    @endif
                    <x-form.input name="brand_name" label="Brand name" :value="$brandName" maxlength="40" :placeholder="config('app.name')"
                        help="Shown in the sidebar, page titles and sign-in pages. Leave blank to use the default." />
                    <label class="form-label" for="f_brand_color">Brand colour</label>
                    <div class="d-flex gap-2 align-items-start mb-1">
                        <input type="color" class="form-control form-control-color" x-model="color" aria-label="Pick a colour">
                        <input type="text" name="brand_color" id="f_brand_color" x-model="color" maxlength="7"
                               class="form-control @error('brand_color') is-invalid @enderror" style="max-width:9rem" placeholder="#007C8A">
                    </div>
                    @error('brand_color')<div class="text-danger fs-8 mb-2">{{ $message }}</div>@enderror
                    <div class="form-text mb-3">Used for buttons, links and highlights. The logo comes from <a href="{{ route('settings.workspace.edit') }}">General settings</a>.</div>

                    <div class="border rounded p-3 d-flex align-items-center gap-3">
                        @if($brand['logo_url'])
                            <img src="{{ $brand['logo_url'] }}" alt="" style="height:32px">
                        @else
                            <span class="z-avatar rounded-3" :style="`background:${color};color:#fff`">{{ $brand['mark'] }}</span>
                        @endif
                        <span class="fw-600">{{ $brand['name'] }}</span>
                        <span class="btn btn-sm ms-auto text-white" :style="`background:${color};border-color:${color}`">Preview button</span>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary"><x-icon name="check" /> Save branding</button></div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Custom domain</h5></div>
                <div class="card-body">
                    <p class="fs-7 text-muted">Serve your sign-in pages from your own address, such as <code>app.yourbusiness.com</code>. Visitors on it see your branding.</p>

                    @if($workspace->custom_domain)
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <x-icon name="globe" />
                            <span class="fw-600">{{ $workspace->custom_domain }}</span>
                            @if($workspace->hasVerifiedDomain())
                                <span class="badge bg-soft-success text-success">Verified</span>
                            @else
                                <span class="badge bg-soft-warning text-warning">Waiting for DNS</span>
                            @endif
                        </div>

                        @unless($workspace->hasVerifiedDomain())
                            <p class="fs-8 text-muted mb-2">Add these two records at your domain provider:</p>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm fs-8 mb-0">
                                    <thead><tr><th>Type</th><th>Name</th><th>Value</th></tr></thead>
                                    <tbody>
                                        <tr><td>CNAME</td><td class="text-break">{{ $workspace->custom_domain }}</td><td class="text-break">{{ $cnameTarget }}</td></tr>
                                        <tr><td>TXT</td><td class="text-break">{{ $recordName }}</td><td class="text-break"><code>{{ $domainToken }}</code></td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <form method="POST" action="{{ route('settings.branding.domain.verify') }}" class="d-inline">
                                @csrf
                                <button class="btn btn-primary btn-sm"><x-icon name="badge-check" /> Verify</button>
                            </form>
                        @endunless
                        <form method="POST" action="{{ route('settings.branding.domain.destroy') }}" class="d-inline" onsubmit="return confirm('Remove this domain?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-white btn-sm"><x-icon name="trash-2" /> Remove</button>
                        </form>
                        <hr>
                    @endif

                    <form method="POST" action="{{ route('settings.branding.domain.update') }}">
                        @csrf @method('PUT')
                        <x-form.input name="custom_domain" :label="$workspace->custom_domain ? 'Change domain' : 'Domain'" :value="$workspace->custom_domain" placeholder="app.yourbusiness.com" required />
                        <button class="btn btn-white btn-sm"><x-icon name="check" /> Save domain</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
