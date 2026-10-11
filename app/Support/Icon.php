<?php

namespace App\Support;

/**
 * Lucide SVG markup, memoised per name and class. The `<x-icon>` component goes through this,
 * and hot loops (the sidebar can list hundreds of apps) call it directly to skip component overhead.
 */
class Icon
{
    /** @var array<string, string> */
    protected static array $cache = [];

    public static function svg(?string $name, string $class = 'zi'): string
    {
        $name = $name ?: 'circle';

        return static::$cache[$name.'|'.$class] ??= static::render($name, $class);
    }

    /**
     * A small `<use>` reference to an icon drawn once by {@see sprite()}, so a list that repeats
     * the same icons hundreds of times does not repeat their paths. Size, stroke and fill come
     * from the `.zi` styles.
     */
    public static function ref(?string $name, string $class = 'zi'): string
    {
        $name = $name ?: 'circle';
        $referenced = request()->attributes->get('icons.referenced', []);
        $referenced[$name] = true;
        request()->attributes->set('icons.referenced', $referenced);

        return '<svg class="'.e($class).'" aria-hidden="true"><use href="#zi-'.e($name).'"/></svg>';
    }

    /**
     * Hidden symbols for the icons referenced so far that no earlier sprite on this page has
     * drawn yet; render it after the references.
     */
    public static function sprite(): string
    {
        $defined = request()->attributes->get('icons.defined', []);
        $pending = array_diff_key(request()->attributes->get('icons.referenced', []), $defined);
        request()->attributes->set('icons.defined', $defined + $pending);
        request()->attributes->set('icons.referenced', []);

        if ($pending === []) {
            return '';
        }

        $symbols = collect(array_keys($pending))->map(function (string $name): string {
            $inner = preg_replace(['/^.*?<svg[^>]*>/s', '/<\/svg>\s*$/s', '/>\s+</'], ['', '', '><'], static::svg($name));

            return '<symbol id="zi-'.e($name).'" viewBox="0 0 24 24">'.trim((string) $inner).'</symbol>';
        })->implode('');

        return '<svg width="0" height="0" style="position:absolute" aria-hidden="true">'.$symbols.'</svg>';
    }

    protected static function render(string $name, string $class): string
    {
        try {
            return svg('lucide-'.$name, $class)->toHtml();
        } catch (\Throwable) {
            return svg('lucide-circle', $class)->toHtml();
        }
    }
}
