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

    /** @var array<string, true> Icons referenced through {@see ref()} that the sprite still has to define. */
    protected static array $referenced = [];

    public static function svg(?string $name, string $class = 'zi'): string
    {
        $name = $name ?: 'circle';

        return static::$cache[$name.'|'.$class] ??= static::render($name, $class);
    }

    /**
     * A small `<use>` reference to an icon drawn once by {@see sprite()}, so a list that repeats
     * the same icons hundreds of times does not repeat their paths.
     */
    public static function ref(?string $name, string $class = 'zi'): string
    {
        $name = $name ?: 'circle';
        static::$referenced[$name] = true;

        return '<svg class="'.e($class).'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><use href="#zi-'.e($name).'"/></svg>';
    }

    /** Hidden symbols for every icon referenced so far; render it after the references. */
    public static function sprite(): string
    {
        $symbols = collect(array_keys(static::$referenced))->map(function (string $name): string {
            $inner = preg_replace(['/^.*?<svg[^>]*>/s', '/<\/svg>\s*$/s'], '', static::svg($name));

            return '<symbol id="zi-'.e($name).'" viewBox="0 0 24 24">'.trim((string) $inner).'</symbol>';
        })->implode('');
        static::$referenced = [];

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
