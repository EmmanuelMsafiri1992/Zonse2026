@props(['icon' => 'inbox', 'title' => 'Nothing here yet', 'text' => null])
<div {{ $attributes->merge(['class' => 'z-empty']) }}>
    <x-icon :name="$icon" />
    <h5>{{ $title }}</h5>
    @if($text)<p class="mb-3">{{ $text }}</p>@endif
    {{ $slot }}
</div>
