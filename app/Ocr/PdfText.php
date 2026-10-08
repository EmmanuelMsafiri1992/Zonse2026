<?php

namespace App\Ocr;

/**
 * Pulls the text layer out of simple PDFs (ones made by accounting packages and word processors
 * with standard fonts), without any outside service. Scans and PDFs with embedded subset fonts
 * give back little or nothing; those need a real OCR provider.
 */
class PdfText
{
    /** Stop on very large files rather than tying up a worker. */
    protected const MAX_STREAMS = 200;

    public static function extract(string $pdf): string
    {
        if (! str_starts_with($pdf, '%PDF')) {
            return '';
        }

        preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches, PREG_SET_ORDER);

        $lines = [];
        foreach (array_slice($matches, 0, self::MAX_STREAMS) as [, $dictionary, $data]) {
            if (str_contains($dictionary, '/FlateDecode')) {
                $data = @gzuncompress($data) ?: (@zlib_decode($data) ?: '');
            } elseif (preg_match('#/Filter\s*/#', $dictionary)) {
                continue;
            }
            if ($data !== '' && str_contains($data, 'BT')) {
                $lines = [...$lines, ...self::textLines($data)];
            }
        }

        return implode("\n", array_filter(array_map('trim', $lines), fn (string $line) => $line !== ''));
    }

    /**
     * Read the strings shown inside BT … ET blocks, starting a new line whenever the text moves.
     *
     * @return list<string>
     */
    protected static function textLines(string $content): array
    {
        $lines = [];
        $current = '';
        preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]*>|\[|\]|[A-Za-z\'"*]+|-?[\d.]+/', $content, $tokens);

        $inArray = false;
        foreach ($tokens[0] as $token) {
            if ($token === '[') {
                $inArray = true;
            } elseif ($token === ']') {
                $inArray = false;
            } elseif ($token[0] === '(') {
                $current .= self::literal(substr($token, 1, -1));
            } elseif ($token[0] === '<') {
                $current .= self::hex(substr($token, 1, -1));
            } elseif ($inArray && is_numeric($token) && (float) $token < -200) {
                $current .= ' ';
            } elseif (in_array($token, ['Td', 'TD', 'T*', 'Tm', "'", '"', 'ET'], true)) {
                $lines[] = $current;
                $current = '';
            }
        }
        $lines[] = $current;

        return $lines;
    }

    protected static function literal(string $value): string
    {
        $value = preg_replace_callback('/\\\\([0-7]{1,3})/', fn (array $m) => chr(octdec($m[1]) & 0xFF), $value);
        $value = strtr($value, ['\\n' => ' ', '\\r' => ' ', '\\t' => ' ', '\\(' => '(', '\\)' => ')', '\\\\' => '\\']);

        return self::printable($value);
    }

    protected static function hex(string $value): string
    {
        $value = (string) preg_replace('/\s+/', '', $value);

        return self::printable((string) hex2bin(strlen($value) % 2 ? $value.'0' : $value));
    }

    /** Keep plain characters only; bytes meant for a custom font encoding would just be noise. */
    protected static function printable(string $value): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');

        return (string) preg_replace('/[^\P{C}\t]/u', '', $value);
    }
}
