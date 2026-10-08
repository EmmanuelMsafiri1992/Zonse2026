<?php

namespace App\Assistant;

/** One turn from the model: text for the person, and/or lookups it wants made first. */
class AssistantReply
{
    /** @param  list<array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls */
    public function __construct(
        public ?string $text,
        public array $toolCalls = [],
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?string $model = null,
    ) {}
}
