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

    protected static function render(string $name, string $class): string
    {
        try {
            return svg('lucide-'.$name, $class)->toHtml();
        } catch (\Throwable) {
            return svg('lucide-circle', $class)->toHtml();
        }
    }
}
