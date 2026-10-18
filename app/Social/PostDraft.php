<?php

namespace App\Social;

/**
 * A post ready to publish: the text that goes with it and what the branded card shows.
 * Kind + key identify it across runs, so a post is never published twice.
 */
class PostDraft
{
    /**
     * @param  list<string>  $bullets  short lines drawn on the card (at most five are shown)
     */
    public function __construct(
        public string $kind,
        public string $key,
        public string $eyebrow,
        public string $title,
        public string $message,
        public array $bullets = [],
    ) {}

    public function id(): string
    {
        return $this->kind.':'.$this->key;
    }
}
