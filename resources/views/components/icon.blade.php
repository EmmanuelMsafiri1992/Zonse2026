@props(['name' => 'circle'])
@php
    $class = $attributes->get('class', 'zi');
    $extra = $attributes->except('class')->getAttributes();
    if ($extra === []) {
        $svg = \App\Support\Icon::svg($name, $class);
    } else {
        try {
            $svg = svg('lucide-'.$name, $class, $extra)->toHtml();
        } catch (\Throwable $e) {
            $svg = svg('lucide-circle', $class, $extra)->toHtml();
        }
    }
@endphp
{!! $svg !!}
