@props(['label', 'value', 'icon' => 'activity', 'color' => 'primary', 'delta' => null, 'deltaUp' => true, 'href' => null])
<{{ $href ? 'a' : 'div' }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'z-stat z-stat-'.$color.' text-decoration-none']) }}>
    <div class="z-stat-icon"><x-icon :name="$icon" /></div>
    <div class="z-stat-body">
        <div class="z-stat-label">{{ $label }}</div>
        <div class="z-stat-value">{{ $value }}</div>
        @if($delta)<div class="z-stat-delta {{ $deltaUp ? 'up' : 'down' }}">{{ $delta }}</div>@endif
    </div>
</{{ $href ? 'a' : 'div' }}>
