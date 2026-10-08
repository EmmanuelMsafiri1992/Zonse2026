@props(['entity', 'record', 'card' => true])
@php $fields = \App\Support\CustomFields::for($entity); @endphp
@if($fields->isNotEmpty())
    @if($card)<div class="card mb-3"><div class="card-header"><h5 class="card-title">More details</h5></div><div class="card-body">@endif
    <div class="row">
        @foreach($fields as $field)
            @php $name = 'custom['.$field->key.']'; $value = $record->customField($field->key); @endphp
            <div class="{{ in_array($field->type, ['textarea'], true) ? 'col-12' : 'col-md-6' }}">
                @switch($field->type)
                    @case('textarea')
                        <x-form.textarea :name="$name" :label="$field->label" :value="$value" :help="$field->help" :required="$field->is_required" rows="3" maxlength="5000" />
                        @break
                    @case('select')
                        <x-form.select :name="$name" :label="$field->label" :options="array_combine($field->options ?? [], $field->options ?? [])" :value="$value" :help="$field->help" :required="$field->is_required" placeholder="—" />
                        @break
                    @case('checkbox')
                        <x-form.check :name="$name" :label="$field->label" :checked="(bool) $value" :help="$field->help" switch />
                        @break
                    @default
                        <x-form.input :name="$name" :label="$field->label" :value="$value" :help="$field->help" :required="$field->is_required"
                                      :type="['number' => 'number', 'date' => 'date', 'email' => 'email', 'url' => 'url', 'phone' => 'tel'][$field->type] ?? 'text'"
                                      :step="$field->type === 'number' ? 'any' : null" :maxlength="in_array($field->type, ['number', 'date'], true) ? null : 255" />
                @endswitch
            </div>
        @endforeach
    </div>
    @if($card)</div></div>@endif
@endif
