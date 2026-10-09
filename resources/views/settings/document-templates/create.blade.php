@extends('layouts.app')
@section('title', 'New document template')
@section('content')
    <x-page-header title="New document template" sub="Pick a starting point and what the document is filled in from. You change the wording and layout next."
                   :crumbs="['Settings' => route('settings.workspace.edit'), 'Document templates' => route('settings.document-templates.index'), 'New']" />

    <form method="POST" action="{{ route('settings.document-templates.store') }}" class="row g-3" x-data="{ starter: @js(old('starter', $starter)), defaults: @js(collect($starters)->map(fn ($s) => $s['subject'])), subject: @js(old('subject', $starters[old('starter', $starter)]['subject'])) }">
        @csrf
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header"><h5 class="card-title">Start from</h5></div>
                <div class="card-body">
                    @foreach($starters as $key => $item)
                        <label class="d-flex gap-2 border rounded p-2 mb-2">
                            <input type="radio" class="form-check-input mt-1" name="starter" value="{{ $key }}" x-model="starter" @change="subject = defaults[starter]">
                            <span><span class="fw-600">{{ $item['label'] }}</span><span class="d-block fs-8 text-muted">{{ $item['description'] }}</span></span>
                        </label>
                    @endforeach
                    @error('starter')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card">
                <div class="card-body">
                    <x-form.input name="name" label="Template name" required maxlength="120" placeholder="e.g. Course certificate" />
                    <div class="mb-3">
                        <label for="subject" class="form-label">Filled in from</label>
                        <select id="subject" name="subject" class="form-select @error('subject') is-invalid @enderror" x-model="subject" required>
                            @foreach($subjects as $group => $options)
                                <optgroup label="{{ $group }}">
                                    @foreach($options as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">The document gets a Print button on these, with their details filled in.</div>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <a href="{{ route('settings.document-templates.index') }}" class="btn btn-white">Cancel</a>
                    <button class="btn btn-primary">Continue</button>
                </div>
            </div>
        </div>
    </form>
@endsection
