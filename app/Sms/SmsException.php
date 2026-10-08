<?php

namespace App\Sms;

use RuntimeException;

/** The provider refused a message or could not be reached. */
class SmsException extends RuntimeException
{
    /** Retrying cannot help (bad credentials, invalid number), so the message is failed straight away. */
    public function __construct(string $message, public readonly bool $permanent = true, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
