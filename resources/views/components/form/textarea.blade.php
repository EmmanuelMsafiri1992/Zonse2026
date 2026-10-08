@props(['name', 'label' => null, 'value' => null, 'help' => null, 'required' => false, 'rows' => 3, 'bag' => 'default'])
@php $errs = $errors->getBag($bag); $oldKey = str_replace(["[]", "[", "]"], ["", ".", ""], $name); $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name)); @endphp
<div class="mb-3">
    @if($label)<label for="{{ $id }}" class="form-label {{ $required ? 'required' : '' }}">{{ $label }}</label>@endif
    <textarea name="{{ $name }}" id="{{ $id }}" rows="{{ $rows }}" {{ $required ? 'required' : '' }}
              {{ $attributes->merge(['class' => 'form-control'.($errs->has($oldKey) ? ' is-invalid' : '')]) }}>{{ old($oldKey, $value) }}</textarea>
    @if($errs->has($oldKey))<div class="invalid-feedback">{{ $errs->first($oldKey) }}</div>@endif
    @if($help)<div class="form-text">{{ $help }}</div>@endif
</div>
