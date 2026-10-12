@props(['label', 'value' => null, 'saved' => true])
{{-- A value the app works out itself, shown in place of an input. --}}
<div class="mb-3">
    <div class="form-label">{{ $label }}</div>
    <div class="form-control bg-body-tertiary">{{ filled($value) ? $value : ($saved ? '—' : 'Worked out once saved') }}</div>
    <div class="form-text">Worked out by the app.</div>
</div>
