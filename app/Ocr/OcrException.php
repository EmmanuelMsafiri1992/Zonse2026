<?php

namespace App\Ocr;

use RuntimeException;

/** The provider could not read a document or could not be reached. */
class OcrException extends RuntimeException
{
    /** Retrying cannot help (bad key, unreadable file), so the capture is failed straight away. */
    public function __construct(string $message, public readonly bool $permanent = true, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
