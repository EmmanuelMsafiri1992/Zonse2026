<?php

namespace App\Ocr;

/**
 * What a provider read: the plain text, plus any fields it picked out itself. Fields it leaves
 * out are filled in from the text by FieldParser.
 */
class OcrResult
{
    /**
     * @param  array<string, string|null>  $fields
     */
    public function __construct(public string $text, public array $fields = []) {}
}
