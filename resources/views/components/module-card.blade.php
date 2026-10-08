@props(['module', 'selected' => false, 'recommended' => false, 'locked' => false])
<div {{ $attributes->merge(['class' => 'z-module-card'.($selected ? ' selected' : '').($locked ? ' opacity-75' : '')]) }}>
    <div class="z-module-icon"><x-icon :name="$module->icon ?: ($module->suite?->icon ?? 'box')" /></div>
    <div class="mw-0 flex-grow-1 pe-4">
        <div class="z-module-title">{{ $module->name }}</div>
        <div class="z-module-desc">{{ \Illuminate\Support\Str::limit($module->description, 90) }}</div>
        <div class="mt-2 d-flex flex-wrap gap-1">
            @if(! $module->isAvailable())<span class="z-pill z-pill-muted">Coming soon</span>
            @elseif($module->status === 'beta')<span class="z-pill z-pill-info">Beta</span>@endif
            @if($recommended)<span class="z-pill z-pill-primary">Recommended</span>@endif
            @if($locked)<span class="z-pill z-pill-warning">Upgrade</span>@endif
            @if($module->is_core)<span class="z-pill z-pill-success">Included</span>@endif
        </div>
    </div>
    <div class="z-module-check"><x-icon name="check" /></div>
</div>
