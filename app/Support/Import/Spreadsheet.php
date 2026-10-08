<?php

namespace App\Support\Import;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the first sheet of a CSV or Excel (.xlsx) file into rows of text, and writes simple .xlsx
 * files for the import templates. Uses only PHP's zip and XML extensions.
 */
class Spreadsheet
{
    protected const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    protected const RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    protected const PACKAGE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * Every non-empty row with its line number in the file.
     *
     * @return list<array{line: int, cells: list<string>}>
     *
     * @throws RuntimeException when the file cannot be read
     */
    public static function read(string $path, string $extension): array
    {
        $rows = strtolower($extension) === 'xlsx' ? self::readXlsx($path) : self::readCsv($path);

        return array_values(array_filter($rows, fn (array $row) => implode('', $row['cells']) !== ''));
    }

    /**
     * A one-sheet .xlsx with a bold, frozen header row. Every cell is text, so codes keep their leading zeros.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public static function xlsx(array $headers, array $rows, string $sheetName = 'Import'): string
    {
        $escape = fn (string $value) => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $sheetRows = '';
        foreach ([$headers, ...$rows] as $index => $values) {
            $line = $index + 1;
            $sheetRows .= '<row r="'.$line.'">';
            foreach (array_values($values) as $column => $value) {
                $sheetRows .= '<c r="'.self::columnName($column + 1).$line.'" t="inlineStr" s="'.($index === 0 ? 1 : 2).'"><is><t xml:space="preserve">'.$escape((string) $value).'</t></is></c>';
            }
            $sheetRows .= '</row>';
        }

        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $files = [
            '[Content_Types].xml' => $head.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => $head.'<Relationships xmlns="'.self::PACKAGE_RELATIONSHIPS.'"><Relationship Id="rId1" Type="'.self::RELATIONSHIPS.'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $head.'<workbook xmlns="'.self::MAIN.'" xmlns:r="'.self::RELATIONSHIPS.'"><sheets><sheet name="'.$escape(mb_substr($sheetName, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $head.'<Relationships xmlns="'.self::PACKAGE_RELATIONSHIPS.'">'
                .'<Relationship Id="rId1" Type="'.self::RELATIONSHIPS.'/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="'.self::RELATIONSHIPS.'/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => $head.'<styleSheet xmlns="'.self::MAIN.'"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="49" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
                .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => $head.'<worksheet xmlns="'.self::MAIN.'"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                .'<cols><col min="1" max="'.max(1, count($headers)).'" width="22" customWidth="1" style="2"/></cols><sheetData>'.$sheetRows.'</sheetData></worksheet>',
        ];

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * A UTF-8 CSV with a byte-order mark so Excel opens accented names correctly.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public static function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        foreach ([$headers, ...$rows] as $values) {
            fputcsv($handle, $values, ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".$csv;
    }

    /** @return list<array{line: int, cells: list<string>}> */
    protected static function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        if (trim($content) === '') {
            return [];
        }

        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t", '|'])->sortByDesc(fn (string $candidate) => substr_count($firstLine, $candidate))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];
        $line = 0;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            $rows[] = ['line' => $line, 'cells' => array_map(fn ($cell) => trim((string) $cell), $cells)];
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<array{line: int, cells: list<string>}> */
    protected static function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This is not a readable Excel file. Save it as .xlsx or CSV and try again.');
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (self::xml($xml)->children(self::MAIN)->si as $item) {
                $shared[] = self::text($item);
            }
        }

        $sheetPath = 'xl/worksheets/sheet1.xml';
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $relations !== false) {
            $sheet = self::xml($workbook)->children(self::MAIN)->sheets->sheet[0] ?? null;
            $relationId = $sheet ? (string) $sheet->attributes(self::RELATIONSHIPS)['id'] : '';
            foreach (self::xml($relations)->children(self::PACKAGE_RELATIONSHIPS)->Relationship as $relation) {
                if ((string) $relation->attributes()['Id'] === $relationId) {
                    $target = (string) $relation->attributes()['Target'];
                    $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                }
            }
        }

        $xml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('The Excel file has no sheet to read.');
        }

        $rows = [];
        $line = 0;
        foreach (self::xml($xml)->children(self::MAIN)->sheetData->row ?? [] as $row) {
            $line = (int) $row->attributes()['r'] ?: $line + 1;
            $cells = [];
            $column = 0;
            foreach ($row->c as $cell) {
                $column = preg_match('/^([A-Z]+)/', (string) $cell->attributes()['r'], $match) ? self::columnIndex($match[1]) : $column + 1;
                $cells[$column - 1] = trim(match ((string) $cell->attributes()['t']) {
                    's' => $shared[(int) $cell->v] ?? '',
                    'inlineStr' => self::text($cell->is),
                    'b' => (string) $cell->v === '1' ? 'TRUE' : 'FALSE',
                    default => (string) $cell->v,
                });
            }
            if ($cells !== []) {
                $cells += array_fill(0, max(array_keys($cells)) + 1, '');
                ksort($cells);
            }
            $rows[] = ['line' => $line, 'cells' => array_values($cells)];
        }

        return $rows;
    }

    protected static function xml(string $xml): SimpleXMLElement
    {
        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($element === false) {
            throw new RuntimeException('The Excel file is damaged and could not be read.');
        }

        return $element;
    }

    /** The text of a shared or inline string, joining rich-text runs. */
    protected static function text(?SimpleXMLElement $element): string
    {
        if (! $element) {
            return '';
        }
        $main = $element->children(self::MAIN);
        if (isset($main->t)) {
            return (string) $main->t;
        }
        $text = '';
        foreach ($main->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    protected static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }

    protected static function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $name = chr(65 + ($index - 1) % 26).$name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }
}
