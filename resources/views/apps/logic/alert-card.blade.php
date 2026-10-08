{{-- A warning that must not be missed (allergies, arrears). --}}
<div class="alert alert-{{ $tone ?? 'danger' }} d-flex gap-2 align-items-start mb-3" role="alert">
    <x-icon :name="$icon ?? 'triangle-alert'" class="zi flex-shrink-0 mt-1" />
    <div><strong>{{ $title }}</strong>@isset($body)<div class="fs-7">{{ $body }}</div>@endisset</div>
</div>
