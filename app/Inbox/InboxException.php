<?php

namespace App\Inbox;

use RuntimeException;

/** A channel refused a reply or could not be reached. */
class InboxException extends RuntimeException
{
    /** Retrying cannot help (bad keys, blocked recipient), so the reply is failed straight away. */
    public function __construct(string $message, public readonly bool $permanent = true, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
