@props(['name', 'label' => null, 'options' => [], 'value' => null, 'placeholder' => null, 'help' => null, 'required' => false, 'bag' => 'default'])
@php $errs = $errors->getBag($bag); $oldKey = str_replace(["[]", "[", "]"], ["", ".", ""], $name); $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name)); $current = old($oldKey, $value); @endphp
<div class="mb-3">
    @if($label)<label for="{{ $id }}" class="form-label {{ $required ? 'required' : '' }}">{{ $label }}</label>@endif
    <select name="{{ $name }}" id="{{ $id }}" {{ $required ? 'required' : '' }}
            {{ $attributes->merge(['class' => 'form-select'.($errs->has($oldKey) ? ' is-invalid' : '')]) }}>
        @if($placeholder)<option value="">{{ $placeholder }}</option>@endif
        @foreach($options as $key => $text)
            @if(is_array($text))
                <optgroup label="{{ $key }}">
                    @foreach($text as $groupKey => $groupText)
                        <option value="{{ $groupKey }}" @selected((string) $current === (string) $groupKey)>{{ $groupText }}</option>
                    @endforeach
                </optgroup>
            @else
                <option value="{{ $key }}" @selected((string) $current === (string) $key)>{{ $text }}</option>
            @endif
        @endforeach
    </select>
    @if($errs->has($oldKey))<div class="invalid-feedback">{{ $errs->first($oldKey) }}</div>@endif
    @if($help)<div class="form-text">{{ $help }}</div>@endif
</div>
