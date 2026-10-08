<?php

namespace App\Support\Help;

use Illuminate\Support\Str;

/** One help article, read from resources/help/articles/{slug}.md. */
class HelpArticle
{
    /**
     * @param  list<string>  $routes
     * @param  list<string>  $keywords
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $category,
        public string $summary,
        public string $body,
        public array $routes = [],
        public array $keywords = [],
        public ?string $module = null,
        public int $order = 50,
    ) {}

    /** @param  array<string, string>  $meta */
    public static function fromFile(string $file, array $meta, string $body): self
    {
        $list = fn (?string $value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));
        $slug = pathinfo($file, PATHINFO_FILENAME);

        return new self(
            slug: $slug,
            title: $meta['title'] ?? Str::headline($slug),
            category: $meta['category'] ?? 'getting-started',
            summary: $meta['summary'] ?? Str::limit(strip_tags(Str::markdown($body)), 140),
            body: $body,
            routes: $list($meta['routes'] ?? null),
            keywords: $list($meta['keywords'] ?? null),
            module: ($meta['module'] ?? '') !== '' ? $meta['module'] : null,
            order: (int) ($meta['order'] ?? 50),
        );
    }

    public function html(): string
    {
        return app(HelpCentre::class)->render($this->body);
    }

    /** Rough reading time in minutes. */
    public function minutes(): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags($this->body)) / 200));
    }
}
