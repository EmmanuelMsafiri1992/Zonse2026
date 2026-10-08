<?php

namespace App\Assistant;

use RuntimeException;

/** The provider refused a request or could not be reached. */
class AssistantException extends RuntimeException
{
    /** Retrying cannot help (bad key, unknown model), so the question is failed straight away. */
    public function __construct(string $message, public readonly bool $permanent = true, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
