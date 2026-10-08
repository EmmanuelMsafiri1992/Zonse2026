@props(['name', 'label', 'checked' => false, 'value' => 1, 'help' => null, 'switch' => false])
@php $oldKey = str_replace(["[]", "[", "]"], ["", ".", ""], $name); $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name)); @endphp
<div class="form-check {{ $switch ? 'form-switch' : '' }} mb-3">
    <input type="hidden" name="{{ $name }}" value="0">
    <input type="checkbox" name="{{ $name }}" id="{{ $id }}" value="{{ $value }}" @checked(old($oldKey, $checked)) {{ $attributes->merge(['class' => 'form-check-input']) }}>
    <label class="form-check-label" for="{{ $id }}">{{ $label }}</label>
    @if($help)<div class="form-text">{{ $help }}</div>@endif
</div>
