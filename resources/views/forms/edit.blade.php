@extends('layouts.app')
@section('title', 'Edit '.$form->name)
@section('content')
    <x-page-header :title="$form->name" :sub="'Each answer creates: '.$form->targetLabel().'.'" :crumbs="['Forms' => route('forms.index'), $form->name => route('forms.show', $form), 'Edit']" />

    <script>
        function formBuilder(available, initial) {
            return {
                available,
                fields: initial.map(f => ({key: f.key, label: f.label, required: !!f.required, help: f.help ?? ''})),
                adding: '',
                def(key) { return this.available.find(a => a.key === key) ?? {label: key, type: 'text', must: false}; },
                get unused() { return this.available.filter(a => !this.fields.some(f => f.key === a.key)); },
                add() {
                    const def = this.def(this.adding);
                    if (this.adding) { this.fields.push({key: def.key, label: def.label, required: def.must, help: ''}); }
                    this.adding = '';
                },
                move(i, by) { const j = i + by; if (j < 0 || j >= this.fields.length) return; const [f] = this.fields.splice(i, 1); this.fields.splice(j, 0, f); },
                remove(i) { this.fields.splice(i, 1); },
            };
        }
    </script>

    @if($errors->any())
        <div class="alert alert-danger fs-7"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('forms.update', $form) }}" x-data="formBuilder(@js($available), @js(json_decode((string) old('fields', json_encode($form->fields)), true) ?? $form->fields))">
        @csrf
        @method('PUT')
        <input type="hidden" name="fields" :value="JSON.stringify(fields)">

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Questions</h5></div>
                    <div class="list-group list-group-flush">
                        <template x-for="(field, i) in fields" :key="field.key">
                            <div class="list-group-item">
                                <div class="d-flex gap-2 align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <label class="form-label fs-8 text-muted mb-1" x-text="'Question (' + def(field.key).label + ')'"></label>
                                                <input type="text" class="form-control form-control-sm" x-model="field.label" maxlength="120" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fs-8 text-muted mb-1">Hint under the box</label>
                                                <input type="text" class="form-control form-control-sm" x-model="field.help" maxlength="250" placeholder="Optional">
                                            </div>
                                        </div>
                                        <div class="form-check form-switch mt-2 mb-0">
                                            <input type="checkbox" class="form-check-input" :id="'req-' + field.key" x-model="field.required" :disabled="def(field.key).must">
                                            <label class="form-check-label fs-7" :for="'req-' + field.key" x-text="def(field.key).must ? 'Required (always needed)' : 'Required'"></label>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column gap-1">
                                        <button type="button" class="btn btn-sm btn-white" title="Move up" @click="move(i, -1)" :disabled="i === 0"><x-icon name="arrow-up" /></button>
                                        <button type="button" class="btn btn-sm btn-white" title="Move down" @click="move(i, 1)" :disabled="i === fields.length - 1"><x-icon name="arrow-down" /></button>
                                        <button type="button" class="btn btn-sm btn-white text-danger" title="Remove" @click="remove(i)" x-show="!def(field.key).must"><x-icon name="trash-2" /></button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="card-footer d-flex gap-2" x-show="unused.length">
                        <select class="form-select form-select-sm" x-model="adding" aria-label="Question to add">
                            <option value="">Add a question…</option>
                            <template x-for="option in unused" :key="option.key"><option :value="option.key" x-text="option.label"></option></template>
                        </select>
                        <button type="button" class="btn btn-sm btn-white text-nowrap" @click="add()" :disabled="!adding"><x-icon name="plus" /> Add</button>
                    </div>
                </div>
                <p class="fs-8 text-muted">Need a question that is not listed? Add it under <a href="{{ route('settings.custom-fields.index') }}">Settings › Custom fields</a> and it appears here.</p>
            </div>

            <div class="col-xl-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <x-form.input name="name" label="Form name (only your team sees this)" :value="$form->name" required maxlength="120" />
                        <x-form.input name="title" label="Heading visitors see" :value="$form->title" required maxlength="160" />
                        <x-form.textarea name="intro" label="Introduction" :value="$form->intro" rows="3" maxlength="2000" />
                        <x-form.textarea name="success_message" label="Thank-you message" :value="$form->success_message" rows="2" maxlength="500" help="Shown after someone sends the form." />
                        <x-form.check name="is_active" label="Taking answers" :checked="(bool) old('is_active', $form->is_active)" switch />
                    </div>
                    <div class="card-footer text-end">
                        <a href="{{ route('forms.show', $form) }}" class="btn btn-white">Cancel</a>
                        <button class="btn btn-primary">Save form</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
