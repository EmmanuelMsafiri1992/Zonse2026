@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'help' => null, 'required' => false, 'bag' => 'default'])
@php $errs = $errors->getBag($bag); $oldKey = str_replace(["[]", "[", "]"], ["", ".", ""], $name); $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name)); @endphp
<div class="mb-3">
    @if($label)<label for="{{ $id }}" class="form-label {{ $required ? 'required' : '' }}">{{ $label }}</label>@endif
    <input type="{{ $type }}" name="{{ $name }}" id="{{ $id }}"
           value="{{ $type === 'password' ? '' : old($oldKey, $value) }}"
           {{ $required ? 'required' : '' }}
           {{ $attributes->merge(['class' => 'form-control'.($errs->has($oldKey) ? ' is-invalid' : '')]) }}>
    @if($errs->has($oldKey))<div class="invalid-feedback">{{ $errs->first($oldKey) }}</div>@endif
    @if($help)<div class="form-text">{{ $help }}</div>@endif
</div>
