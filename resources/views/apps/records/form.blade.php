@extends('layouts.app')
@section('title', ($record->exists ? 'Edit ' : 'New ').strtolower($def->label))
@section('content')
    @php
        $backUrl = $record->exists ? $record->url() : route('apps.records.index', [$app->key, $def->key]);
        $inputTypes = ['number' => 'number', 'money' => 'number', 'date' => 'date', 'datetime' => 'datetime-local', 'time' => 'time', 'email' => 'email', 'phone' => 'tel', 'url' => 'url'];
    @endphp
    <x-page-header :title="$record->exists ? 'Edit '.$record->number : 'New '.strtolower($def->label)"
                   :sub="$record->exists ? $record->title : $app->name"
                   :crumbs="['Apps' => route('apps.index'), $app->name => route('apps.show', $app->key), $def->plural => route('apps.records.index', [$app->key, $def->key]), $record->exists ? 'Edit' : 'New']" />

    <form method="POST" action="{{ $record->exists ? route('apps.records.update', [$app->key, $def->key, $record->id]) : route('apps.records.store', [$app->key, $def->key]) }}">
        @csrf
        @if($record->exists) @method('PUT') @endif

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-body">
                        <x-form.input name="title" :label="$def->titleLabel" :value="$record->title" required autofocus />
                        <div class="row g-3">
                            @foreach($def->fields as $field)
                                @php $name = 'data['.$field->key.']'; $value = $record->value($field->key); @endphp
                                <div class="{{ $field->type === 'textarea' ? 'col-12' : 'col-md-6' }}">
                                    @switch($field->type)
                                        @case('textarea')
                                            <x-form.textarea :name="$name" :label="$field->label" :value="$value" :required="$field->required" rows="3" />
                                            @break
                                        @case('select')
                                            <x-form.select :name="$name" :label="$field->label" :options="$field->options" :value="$value" :required="$field->required" placeholder="—" />
                                            @break
                                        @case('record')
                                            <x-form.select :name="$name" :label="$field->label" :options="$relatedOptions[$field->key] ?? []" :value="$value" :required="$field->required" placeholder="—" />
                                            @break
                                        @case('user')
                                            <x-form.select :name="$name" :label="$field->label" :options="$members" :value="$value" :required="$field->required" placeholder="—" />
                                            @break
                                        @case('checkbox')
                                            <div class="pt-md-4"><x-form.check :name="$name" :label="$field->label" :checked="(bool) $value" switch /></div>
                                            @break
                                        @default
                                            <x-form.input :name="$name" :label="$field->label" :type="$inputTypes[$field->type] ?? 'text'" :value="$value" :required="$field->required"
                                                          :step="in_array($field->type, ['number', 'money'], true) ? ($field->type === 'money' ? '0.01' : 'any') : null" />
                                    @endswitch
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-body">
                        <x-form.select name="status" label="Status" :options="$def->statuses" :value="$record->status" required />
                        @if($def->hasContact())
                            @if($contacts)
                                <x-form.select name="contact_id" :label="$def->contactLabel" :options="$contacts" :value="$record->contact_id" placeholder="—" />
                            @else
                                <p class="fs-8 text-muted">Switch on Contacts to link a {{ strtolower($def->contactLabel) }}.</p>
                            @endif
                        @endif
                        @if($def->hasAssignee)
                            <x-form.select name="assignee_id" label="Assigned to" :options="$members" :value="$record->assignee_id" placeholder="Unassigned" />
                        @endif
                        @if($def->hasAmount())
                            <x-form.input name="amount" type="number" step="0.01" min="0" :label="$def->amountLabel" :value="$record->amount" />
                        @endif
                        @if($def->hasDate())
                            <x-form.input name="occurs_on" type="date" :label="$def->dateLabel" :value="$record->occurs_on?->toDateString() ?? ($record->exists ? null : now()->toDateString())" />
                        @endif
                        @if($def->hasDue())
                            <x-form.input name="due_on" type="date" :label="$def->dueLabel" :value="$record->due_on?->toDateString()" />
                        @endif
                        @if($branches->count() > 1)
                            <x-form.select name="branch_id" label="Branch" :options="$branches" :value="$record->branch_id" placeholder="—" />
                        @endif
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <a href="{{ $backUrl }}" class="btn btn-white">Cancel</a>
                        <button class="btn btn-primary"><x-icon name="check" /> {{ $record->exists ? 'Save changes' : 'Save '.strtolower($def->label) }}</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
