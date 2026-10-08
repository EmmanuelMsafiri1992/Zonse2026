<?php

namespace App\Ocr;

use Illuminate\Support\Carbon;

/**
 * Picks the useful fields out of the plain text of a receipt, supplier invoice or ID document.
 * Works the same whichever provider read the text, so even a plain text-only OCR service gives
 * a pre-filled form to check.
 */
class FieldParser
{
    /** A money amount with two decimals, e.g. 62.00, 1,234.56 or 1 234,56; never a percentage. */
    protected const AMOUNT = '/(?<![\d.,])(\d{1,3}(?:[ ,.]\d{3})+[.,]\d{2}|\d+[.,]\d{2})(?![\d%]|\s*%)/';

    protected const MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    /** @var array<string, string> symbols and local spellings mapped to ISO codes */
    protected const CURRENCIES = [
        'US$' => 'USD', 'USD' => 'USD', 'ZWG' => 'ZWG', 'ZIG' => 'ZWG', 'ZWL' => 'ZWL', 'ZAR' => 'ZAR', 'KES' => 'KES', 'KSH' => 'KES',
        'ZMW' => 'ZMW', 'BWP' => 'BWP', 'NGN' => 'NGN', '₦' => 'NGN', 'GHS' => 'GHS', 'TZS' => 'TZS', 'UGX' => 'UGX', 'MWK' => 'MWK',
        'MZN' => 'MZN', 'NAD' => 'NAD', 'EUR' => 'EUR', '€' => 'EUR', 'GBP' => 'GBP', '£' => 'GBP', '$' => 'USD',
    ];

    /** @var array<string, list<string>> words on a receipt that point to an expense category */
    protected const CATEGORY_WORDS = [
        'fuel' => ['fuel', 'petrol', 'diesel', 'unleaded', 'blend', 'zuva', 'puma', 'engen', 'total energies', 'service station', 'filling station', 'litres', ' ltr'],
        'airtime' => ['airtime', 'data bundle', 'econet', 'netone', 'telecel', 'safaricom', 'airtel', 'mtn', 'vodacom', 'recharge'],
        'utilities' => ['zesa', 'electricity', 'prepaid power', 'kplc', 'eskom', 'water bill', 'council', 'rates', 'internet', 'liquid', 'telone', 'fibre'],
        'meals' => ['restaurant', 'cafe', 'café', 'coffee', 'chicken inn', 'pizza', 'burger', 'takeaway', 'take away', 'meal', 'lunch', 'kfc', 'nando', 'food', 'bakery', 'eatery', 'diner', 'grill'],
        'travel' => ['hotel', 'lodge', 'guest house', 'bus fare', 'flight', 'airline', 'airways', 'taxi', 'uber', 'bolt', 'toll', 'parking', 'accommodation'],
        'repairs' => ['repair', 'hardware', 'spares', 'spare parts', 'tyre', 'tire', 'mechanic', 'service kit', 'plumbing', 'electrical'],
        'office' => ['stationery', 'office', 'printing', 'paper', 'toner', 'ink', 'cartridge', 'pens', 'files'],
    ];

    /** Lines at the top of a receipt that are headings, not the shop's name. */
    protected const NOT_A_NAME = '/^(tax\s+)?(invoice|receipt|cash\s+sale|sales?\s+slip|till\s+slip|welcome|thank|copy|original|duplicate|customer\s+copy|merchant\s+copy|fiscal|tel|phone|cell|vat|tin|date|address|www|e-?mail|bp\s)/i';

    /**
     * @return array<string, string|null>
     */
    public static function parse(string $text, string $type): array
    {
        $lines = self::lines($text);

        return $type === 'id_document' ? self::identity($lines, $text) : self::bill($lines, $text, $type);
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string|null>
     */
    protected static function bill(array $lines, string $text, string $type): array
    {
        [$total, $totalLine] = self::total($lines);
        $fields = [
            'merchant' => self::merchant($lines),
            'date' => $type === 'invoice'
                ? (self::dateAfter($lines, '/(invoice|issue|tax\s+point)\s*date|date\s+of\s+(issue|invoice)/i') ?? self::dateAfter($lines, '/\bdate\b(?!.*\bdue\b)/i', '/\bdue\b/i'))
                : self::dateAfter($lines, '/\bdate\b/i', '/\b(due|expir|valid)/i'),
            'total' => $total,
            'tax' => self::tax($lines, $total),
            'currency' => self::currency($totalLine ?? '') ?? self::currency($text),
            'number' => self::documentNumber($lines, $type),
            'tax_number' => self::taxNumber($text),
            'category' => self::category($text),
        ];
        $fields['date'] ??= self::firstDate($text);
        if ($type === 'invoice') {
            $fields['due_date'] = self::dateAfter($lines, '/\b(due\s*date|payment\s+due|due\s+by|due\s+on|pay\s+by)\b/i');
        }

        return $fields;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string|null>
     */
    protected static function identity(array $lines, string $text): array
    {
        if ($passport = self::machineReadableZone($lines)) {
            return $passport;
        }

        $sex = self::valueAfter($lines, '/\b(sex|gender)\b/i');

        return [
            'surname' => self::name(self::valueAfter($lines, '/\b(surname|last\s*name|family\s*name)\b/i')),
            'first_names' => self::name(self::valueAfter($lines, '/\b(first\s*names?|given\s*names?|forenames?|other\s*names?)\b/i')),
            'id_number' => self::idNumber($lines, $text),
            'date_of_birth' => self::dateAfter($lines, '/(date\s*of\s*birth|birth\s*date|\bd\.?o\.?b\b|\bborn\b)/i'),
            'sex' => self::sex($sex),
            'nationality' => self::name(self::valueAfter($lines, '/\b(nationality|citizenship)\b/i')),
        ];
    }

    /** @return list<string> */
    protected static function lines(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return array_values(array_filter(array_map(fn (string $line) => trim((string) preg_replace('/[ \t]+/', ' ', $line)), $lines), fn (string $line) => $line !== ''));
    }

    /** @param  list<string>  $lines */
    protected static function merchant(array $lines): ?string
    {
        foreach (array_slice($lines, 0, 6) as $line) {
            $line = trim((string) preg_replace('/^welcome\s+to\s+/i', '', $line), " \t*-=#");
            $letters = preg_match_all('/\p{L}/u', $line);
            if ($letters < 3 || preg_match(self::NOT_A_NAME, $line) || preg_match('/@|www\.|https?:/i', $line) || $letters < mb_strlen($line) / 3) {
                continue;
            }

            return self::name($line);
        }

        return null;
    }

    /**
     * The amount on the strongest "total" line: grand total, then amount due, then a plain total.
     *
     * @param  list<string>  $lines
     * @return array{0: string|null, 1: string|null}
     */
    protected static function total(array $lines): array
    {
        $patterns = [
            '/\bgrand\s*total\b/i',
            '/\b(total\s*(due|payable|amount|to\s*pay)|amount\s*(due|payable)|balance\s*due|net\s*payable)\b/i',
            '/\btotal\b/i',
        ];
        $notTotal = '/\b(sub\s*-?\s*total|total\s*(tax|vat|discount|savings|items?|qty|quantity|excl\w*)|(tax|vat)\s*total|items?\s*total)\b/i';

        foreach ($patterns as $pattern) {
            $found = null;
            foreach ($lines as $i => $line) {
                if (! preg_match($pattern, $line) || preg_match($notTotal, $line)) {
                    continue;
                }
                $amount = self::lastAmount($line) ?? (isset($lines[$i + 1]) ? self::lastAmount($lines[$i + 1]) : null);
                if ($amount !== null) {
                    $found = [$amount, $line];
                }
            }
            if ($found) {
                return $found;
            }
        }

        $amounts = [];
        foreach ($lines as $line) {
            if (! preg_match('/\b(cash|tender\w*|change|paid|card|balance)\b/i', $line)) {
                $amounts = [...$amounts, ...self::amounts($line)];
            }
        }

        return [$amounts ? number_format(max(array_map('floatval', $amounts)), 2, '.', '') : null, null];
    }

    /** @param  list<string>  $lines */
    protected static function tax(array $lines, ?string $total): ?string
    {
        foreach ($lines as $line) {
            if (! preg_match('/\b(vat|tax|gst)\b/i', $line)
                || preg_match('/\b(vat|tax|gst|bp)\s*(reg(istration)?\.?\s*)?(no\b|number|num\b|#|id\b)|\btin\b/i', $line)
                || preg_match('/\b(incl\w*|excl\w*|before|after|exempt)\b/i', $line)) {
                continue;
            }
            $amount = self::lastAmount($line);
            if ($amount !== null && ($total === null || (float) $amount < (float) $total)) {
                return $amount;
            }
        }

        return null;
    }

    protected static function currency(string $text): ?string
    {
        if (preg_match('/(?<![A-Z])(US\$|USD|ZWG|ZiG|ZWL|ZAR|KES|KSh|ZMW|BWP|NGN|GHS|TZS|UGX|MWK|MZN|NAD|EUR|GBP)(?![A-Z])|(₦|€|£|\$)/iu', $text, $match)) {
            return self::CURRENCIES[mb_strtoupper($match[2] ?? '') ?: mb_strtoupper($match[1])] ?? null;
        }
        if (preg_match('/(?<![A-Za-z])R\s?\d+[.,]\d{2}/', $text)) {
            return 'ZAR';
        }

        return null;
    }

    /** @param  list<string>  $lines */
    protected static function documentNumber(array $lines, string $type): ?string
    {
        $labels = $type === 'invoice' ? ['invoice|inv', 'bill|document|doc|ref(?:erence)?'] : ['receipt|rcpt|slip|till', 'transaction|trans|txn|ref(?:erence)?|invoice|inv|doc'];
        foreach ([...$labels, ''] as $label) {
            $pattern = $label === ''
                ? '/(?<!vat |tax |tin |bp |tel |phone |cell )\b(no\.?|number|#)\s*[:.#]?\s*([A-Z0-9][A-Z0-9\-\/]{1,30})/i'
                : '/\b(?:'.$label.')\.?\s*(?:no\.?|number|num|#)?\s*[:.#]\s*([A-Z0-9][A-Z0-9\-\/]{1,30})|\b(?:'.$label.')\.?\s*(?:no\.?|number|num|#)\s*([A-Z0-9][A-Z0-9\-\/]{1,30})/i';
            foreach ($lines as $line) {
                if (preg_match($pattern, $line, $match)) {
                    $number = $label === '' ? $match[2] : ($match[1] ?: ($match[2] ?? ''));
                    if ($number !== '' && preg_match('/\d/', $number)) {
                        return strtoupper($number);
                    }
                }
            }
        }

        return null;
    }

    protected static function taxNumber(string $text): ?string
    {
        if (preg_match('/\b(?:vat|tax|bp|gst)\s*(?:reg(?:istration)?\.?\s*)?(?:no\.?|number|num|#|id)\s*[:.#]?\s*([A-Z0-9][A-Z0-9\-]{4,20})|\bTIN\s*(?:no\.?)?\s*[:.#]?\s*([A-Z0-9][A-Z0-9\-]{4,20})/i', $text, $match)) {
            $number = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

            return preg_match('/\d{4}/', $number) ? strtoupper($number) : null;
        }

        return null;
    }

    public static function category(string $text): string
    {
        $text = ' '.mb_strtolower($text).' ';
        foreach (self::CATEGORY_WORDS as $category => $words) {
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    return $category;
                }
            }
        }

        return 'other';
    }

    /** @param  list<string>  $lines */
    protected static function idNumber(array $lines, string $text): ?string
    {
        if (preg_match('/\b(\d{2})\s*-?\s*(\d{6,7})\s*-?\s*([A-Z])\s*-?\s*(\d{2})\b/', $text, $match)) {
            return $match[1].'-'.$match[2].$match[3].$match[4];
        }
        $labelled = self::valueAfter($lines, '/\b(id\s*(no\.?|number)|identity\s*(no\.?|number)|national\s*id|personal\s*no\.?|passport\s*(no\.?|number)|document\s*(no\.?|number))/i');
        if ($labelled && preg_match('/[A-Z0-9][A-Z0-9 \-]{4,20}/i', $labelled, $match) && preg_match('/\d{4}/', $match[0])) {
            return strtoupper(trim($match[0]));
        }
        if (preg_match('/\b\d{13}\b/', $text, $match)) {
            return $match[0];
        }

        return null;
    }

    /**
     * Passports and many newer ID cards print two machine-readable lines (ICAO 9303) that hold
     * every field in a fixed layout, which is far more reliable than the printed labels.
     *
     * @param  list<string>  $lines
     * @return array<string, string|null>|null
     */
    protected static function machineReadableZone(array $lines): ?array
    {
        $clean = array_values(array_map(fn (string $line) => strtoupper(str_replace(' ', '', $line)), $lines));
        foreach ($clean as $i => $line) {
            if (! preg_match('/^P[A-Z<]([A-Z<]{3})([A-Z<]+)$/', $line, $top) || ! isset($clean[$i + 1])) {
                continue;
            }
            if (! preg_match('/^([A-Z0-9<]{9})[\d<]([A-Z<]{3})(\d{6})[\d<]([MF<])/', $clean[$i + 1], $bottom)) {
                continue;
            }
            [$surname, $given] = array_pad(explode('<<', $top[2], 2), 2, '');
            $born = Carbon::createFromFormat('!ymd', $bottom[3]);
            if ($born && $born->isFuture()) {
                $born->subCentury();
            }

            return [
                'surname' => self::name(str_replace('<', ' ', $surname)),
                'first_names' => self::name(trim(str_replace('<', ' ', $given))),
                'id_number' => rtrim($bottom[1], '<'),
                'date_of_birth' => $born?->toDateString(),
                'sex' => self::sex($bottom[4]),
                'nationality' => rtrim($bottom[2], '<'),
            ];
        }

        return null;
    }

    /**
     * The text after a label on the same line, or on the next line when the label stands alone.
     *
     * @param  list<string>  $lines
     */
    protected static function valueAfter(array $lines, string $label): ?string
    {
        foreach ($lines as $i => $line) {
            if (preg_match($label, $line, $match, PREG_OFFSET_CAPTURE)) {
                $rest = trim(substr($line, $match[0][1] + strlen($match[0][0])), " \t:.-/");
                if ($rest === '' && isset($lines[$i + 1])) {
                    $rest = $lines[$i + 1];
                }

                return $rest === '' ? null : $rest;
            }
        }

        return null;
    }

    /** @param  list<string>  $lines */
    protected static function dateAfter(array $lines, string $label, ?string $unless = null): ?string
    {
        foreach ($lines as $i => $line) {
            if (preg_match($label, $line) && (! $unless || ! preg_match($unless, $line))) {
                $date = self::firstDate($line) ?? (isset($lines[$i + 1]) ? self::firstDate($lines[$i + 1]) : null);
                if ($date) {
                    return $date;
                }
            }
        }

        return null;
    }

    /** The first real date in the text as Y-m-d. Numeric dates are read day first unless that is impossible. */
    public static function firstDate(string $text): ?string
    {
        $months = self::MONTHS;
        $patterns = [
            '/(?<!\d)(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?!\d)/' => fn ($m) => [$m[1], $m[2], $m[3]],
            '/(?<!\d)(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})(?!\d)/' => fn ($m) => (int) $m[2] > 12 ? [$m[3], $m[1], $m[2]] : [$m[3], $m[2], $m[1]],
            '/\b(\d{1,2})(?:st|nd|rd|th)?[\s\-\/.]*('.$months.')[a-z]*[\s\-\/.,]*(\d{2,4})\b/i' => fn ($m) => [$m[3], self::month($m[2]), $m[1]],
            '/\b('.$months.')[a-z]*[\s.]+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})\b/i' => fn ($m) => [$m[3], self::month($m[1]), $m[2]],
        ];

        $best = null;
        foreach ($patterns as $pattern => $parts) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($matches as $match) {
                    [$year, $month, $day] = array_map('intval', $parts(array_map(fn ($group) => $group[0], $match)));
                    $year = $year < 100 ? 2000 + $year : $year;
                    if ($year >= 1900 && $year <= 2100 && checkdate($month, $day, $year) && ($best === null || $match[0][1] < $best[0])) {
                        $best = [$match[0][1], sprintf('%04d-%02d-%02d', $year, $month, $day)];
                    }
                }
            }
        }

        return $best[1] ?? null;
    }

    protected static function month(string $name): int
    {
        return (int) array_search(strtolower(substr($name, 0, 3)), ['', 'jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true);
    }

    /** @return list<string> */
    protected static function amounts(string $line): array
    {
        preg_match_all(self::AMOUNT, $line, $matches);

        return array_map(fn (string $amount) => self::normaliseAmount($amount), $matches[1]);
    }

    protected static function lastAmount(string $line): ?string
    {
        $amounts = self::amounts($line);

        return $amounts ? end($amounts) : null;
    }

    /** "1 234,56", "1.234,56" and "1,234.56" all become "1234.56". */
    public static function normaliseAmount(string $amount): string
    {
        $amount = str_replace(' ', '', trim($amount));
        $decimal = max(strrpos($amount, '.'), strrpos($amount, ','));
        if ($decimal !== false && strlen($amount) - $decimal - 1 === 2) {
            $amount = str_replace(['.', ','], '', substr($amount, 0, $decimal)).'.'.substr($amount, $decimal + 1);
        } else {
            $amount = str_replace(',', '', $amount);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    protected static function sex(?string $value): ?string
    {
        return match (strtoupper(substr(trim((string) $value), 0, 1))) {
            'M' => 'Male',
            'F' => 'Female',
            default => null,
        };
    }

    /** Capital letters only where they belong: "TENDAI GRACE" becomes "Tendai Grace". */
    protected static function name(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', (string) $value), " \t:.-,");
        if ($value === '') {
            return null;
        }

        return mb_strtoupper($value) === $value ? mb_convert_case(mb_strtolower($value), MB_CASE_TITLE) : $value;
    }
}
