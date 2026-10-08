<?php

namespace App\Support\Help;

use Illuminate\Support\Carbon;

/** One "What's new" entry, read from resources/help/releases/*.md. */
class ReleaseNote
{
    public function __construct(
        public string $version,
        public Carbon $date,
        public string $title,
        public string $body,
    ) {}

    /** @param  array<string, string>  $meta */
    public static function fromFile(string $file, array $meta, string $body): self
    {
        return new self(
            version: $meta['version'] ?? pathinfo($file, PATHINFO_FILENAME),
            date: HelpCentre::date($meta['date'] ?? null, $file),
            title: $meta['title'] ?? 'Release '.($meta['version'] ?? pathinfo($file, PATHINFO_FILENAME)),
            body: $body,
        );
    }

    /** Released after the given moment (from the start of its release day); everything is new to someone with no moment. */
    public function isNewSince(?\DateTimeInterface $since): bool
    {
        return $since === null || $this->date->copy()->startOfDay()->greaterThan($since);
    }

    public function html(): string
    {
        return app(HelpCentre::class)->render($this->body);
    }
}
