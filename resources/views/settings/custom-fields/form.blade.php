@extends('layouts.app')
@section('title', $field->exists ? $field->label : 'New field')
@section('content')
    <x-page-header :title="$field->exists ? $field->label : 'New field'" :sub="$field->exists ? 'A field on '.mb_strtolower($field->entityLabel()).'.' : 'It will appear on the add and edit forms, and on each record.'" :crumbs="['Settings' => route('settings.workspace.edit'), 'Custom fields' => route('settings.custom-fields.index'), $field->exists ? 'Edit' : 'New']" />

    <div class="row g-4">
        <div class="col-xl-7">
            <form method="POST" action="{{ $field->exists ? route('settings.custom-fields.update', $field) : route('settings.custom-fields.store') }}" class="card" x-data="{ type: @js(old('type', $field->type)) }">
                @csrf
                @if($field->exists) @method('PUT') @endif
                <div class="card-body">
                    @if($field->exists)
                        <div class="mb-3"><span class="form-label d-block">Used on</span>{{ $field->entityLabel() }}</div>
                    @else
                        <x-form.select name="entity" label="Used on" :options="$entities" :value="$field->entity" required />
                    @endif
                    <x-form.input name="label" label="Name" :value="$field->label" maxlength="120" required placeholder="e.g. Medical aid number" />
                    <x-form.select name="type" label="Kind of answer" :options="collect($types)->map(fn ($type) => $type['label'])->all()" :value="$field->type" required x-model="type" />
                    <div x-show="type === 'select'" x-cloak>
                        <x-form.textarea name="options" label="Choices" :value="implode(PHP_EOL, old('options', $field->options ?? []))" rows="5" help="One per line, up to 50." />
                    </div>
                    @if($field->exists)
                        <div class="alert alert-info fs-8 py-2" x-show="type !== @js($field->type)" x-cloak>Answers already saved stay as they are. Any that don't fit the new kind will need fixing the next time each record is edited.</div>
                    @endif
                    <x-form.input name="help" label="Hint (optional)" :value="$field->help" maxlength="255" help="Shown under the field on forms." />
                    <div x-show="type !== 'checkbox'">
                        <x-form.check name="is_required" label="Required" :checked="$field->is_required" help="New and edited records must fill it in." switch />
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button class="btn btn-primary"><x-icon name="check" /> Save field</button>
                    <a href="{{ route('settings.custom-fields.index') }}" class="btn btn-white">Cancel</a>
                </div>
            </form>
        </div>
        @if($field->exists)
            <div class="col-xl-5">
                <div class="card">
                    <div class="card-body">
                        <h6>Remove this field</h6>
                        <p class="fs-7 text-muted">It disappears from forms and records straight away. Answers already saved stop showing and are cleared the next time each record is saved.</p>
                        <form method="POST" action="{{ route('settings.custom-fields.destroy', $field) }}" onsubmit="return confirm('Remove this field?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-soft-danger"><x-icon name="trash-2" /> Remove field</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
