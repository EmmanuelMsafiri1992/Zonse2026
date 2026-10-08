<?php

namespace App\Ocr;

/**
 * A service that reads the text on a photo or PDF. Drivers talk to the provider over plain HTTP
 * and keep their credentials in the workspace's settings (secret fields encrypted).
 */
interface OcrProvider
{
    public function key(): string;

    public function label(): string;

    /** One line on what the provider reads best, shown in settings. */
    public function description(): string;

    /**
     * The settings fields this provider needs, keyed by field name.
     *
     * @return array<string, array{label: string, secret: bool, required: bool, help?: string}>
     */
    public function fields(): array;

    /**
     * Read one document.
     *
     * @param  string  $type  receipt, invoice or id_document
     * @param  array<string, string>  $credentials
     *
     * @throws OcrException when the provider refuses the file or cannot be reached
     */
    public function read(string $contents, string $mime, string $type, array $credentials): OcrResult;
}
