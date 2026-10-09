@extends('layouts.app')
@section('title', 'Edit '.$template->name)
@section('content')
    <x-page-header :title="$template->name" :sub="$template->kindLabel().' · filled in from '.mb_strtolower($template->subjectLabel())"
                   :crumbs="['Settings' => route('settings.workspace.edit'), 'Document templates' => route('settings.document-templates.index'), $template->name]">
        <a href="{{ route('settings.document-templates.sample', $template) }}" target="_blank" class="btn btn-white" data-no-ajax><x-icon name="file-text" /> Sample PDF</a>
        <form method="POST" action="{{ route('settings.document-templates.destroy', $template) }}" onsubmit="return confirm('Delete this template? Documents already printed are not affected.')">
            @csrf @method('DELETE')
            <button class="btn btn-soft-danger btn-icon" title="Delete"><x-icon name="trash-2" /></button>
        </form>
    </x-page-header>

    <script>
        function templateDesigner(previewUrl) {
            return {
                target: null,
                timer: null,
                init() {
                    this.target = this.$refs.body;
                    this.refresh();
                },
                remember(event) {
                    if (event.target.matches('input[type=text], textarea')) {
                        this.target = event.target;
                    }
                },
                insert(tag) {
                    const field = this.target || this.$refs.body;
                    const text = '@{{ ' + tag + ' }}';
                    const start = field.selectionStart ?? field.value.length;
                    const end = field.selectionEnd ?? field.value.length;
                    field.value = field.value.slice(0, start) + text + field.value.slice(end);
                    field.focus();
                    field.selectionStart = field.selectionEnd = start + text.length;
                    this.queue();
                },
                queue() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => this.refresh(), 400);
                },
                async refresh() {
                    const data = new FormData(this.$root);
                    data.delete('_method');
                    try {
                        const response = await fetch(previewUrl, { method: 'POST', body: data, headers: { 'Accept': 'text/html' } });
                        if (response.ok) {
                            this.$refs.preview.srcdoc = await response.text();
                        }
                    } catch (error) {
                        // The preview catches up on the next change.
                    }
                },
            };
        }
    </script>

    @unless($available)
        <div class="alert alert-warning">This template is filled in from {{ mb_strtolower($template->subjectLabel()) }}, which is switched off in this workspace, so it cannot be printed right now.</div>
    @endunless

    <form method="POST" action="{{ route('settings.document-templates.update', $template) }}" class="row g-3"
          x-data="templateDesigner(@js(route('settings.document-templates.preview', $template)))" @input="queue()" @change="queue()" @focusin="remember($event)">
        @csrf @method('PUT')
        <div class="col-xl-5">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Wording</h5></div>
                <div class="card-body">
                    <x-form.input name="heading" label="Heading" :value="$template->heading" maxlength="160" placeholder="e.g. Certificate of Completion" />
                    <x-form.textarea name="body" label="Text" :value="$template->body" rows="12" required maxlength="10000" x-ref="body" class="font-monospace fs-7"
                                     help="Blank line = new paragraph. **bold**, *italic*, # big line, - list. Click a tag below to put it where the cursor is." />
                    <div class="row">
                        @for($i = 0; $i < \App\Support\DocumentTemplates::MAX_SIGNATURES; $i++)
                            <div class="col-md-4">
                                <x-form.input name="signatures[{{ $i }}]" :label="$i === 0 ? 'Signature lines' : ' '" :value="$template->signatures[$i] ?? null" maxlength="60" placeholder="e.g. Director" />
                            </div>
                        @endfor
                    </div>
                    <x-form.input name="footer" label="Footer" :value="$template->footer" maxlength="255" />
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Tags</h5></div>
                <div class="card-body">
                    @foreach($tags as $group => $items)
                        <div class="fs-8 text-muted text-uppercase fw-600 mb-1">{{ $group }}</div>
                        <div class="d-flex flex-wrap gap-1 mb-3">
                            @foreach($items as $tag => $label)
                                <button type="button" class="btn btn-sm btn-white" @click="insert(@js($tag))" title="{{ $tag }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Layout</h5></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6"><x-form.input name="name" label="Template name" :value="$template->name" required maxlength="120" /></div>
                        <div class="col-md-6"><x-form.select name="kind" label="Kind" :options="\App\Support\DocumentTemplates::KINDS" :value="$template->kind" required /></div>
                        <div class="col-md-6"><x-form.select name="paper" label="Paper" :options="\App\Support\DocumentTemplates::PAPERS" :value="$template->paper" required /></div>
                        <div class="col-md-6"><x-form.select name="orientation" label="Orientation" :options="\App\Support\DocumentTemplates::ORIENTATIONS" :value="$template->orientation" required /></div>
                        <div class="col-md-6"><x-form.select name="font" label="Font" :options="\App\Support\DocumentTemplates::FONTS" :value="$template->font" required /></div>
                        <div class="col-md-6"><x-form.select name="align" label="Text alignment" :options="\App\Support\DocumentTemplates::ALIGNS" :value="$template->align" required /></div>
                        <div class="col-md-6"><x-form.select name="border" label="Border" :options="\App\Support\DocumentTemplates::BORDERS" :value="$template->border" required /></div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="template-color" class="form-label">Accent colour</label>
                                <input type="color" id="template-color" name="color" value="{{ old('color', $template->color) }}" class="form-control form-control-color @error('color') is-invalid @enderror">
                                @error('color')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                    <x-form.check name="show_logo" label="Show the workspace logo" :checked="$template->show_logo" switch />
                    <x-form.check name="is_active" label="In use (shows a Print button)" :checked="$template->is_active" switch />
                </div>
                <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary"><x-icon name="check" /> Save template</button></div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card position-sticky" style="top: 1rem">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="card-title">Preview</h5>
                    <span class="fs-8 text-muted">Tags show their names here. Real details are filled in when you print.</span>
                </div>
                <div class="card-body p-2 bg-body-tertiary">
                    <iframe x-ref="preview" title="Preview" class="w-100 border rounded bg-white" style="height: 75vh" sandbox></iframe>
                </div>
            </div>
        </div>
    </form>
@endsection
