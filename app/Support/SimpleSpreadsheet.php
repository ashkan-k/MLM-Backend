<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * خواندن/نوشتن سادهٔ CSV و XLSX تک‌برگ بدون وابستگی خارجی.
 *
 * @phpstan-type Row list<string>
 */
final class SimpleSpreadsheet
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function writeXlsx(string $path, array $headers, array $rows): void
    {
        $all = array_merge([$headers], $rows);
        $sheetRows = '';
        foreach ($all as $rIdx => $row) {
            $rowNum = $rIdx + 1;
            $cells = '';
            foreach (array_values($row) as $cIdx => $value) {
                $col = self::columnLetter($cIdx + 1);
                $ref = $col.$rowNum;
                $text = self::xmlEscape((string) ($value ?? ''));
                $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.$text.'</t></is></c>';
            }
            $sheetRows .= '<row r="'.$rowNum.'">'.$cells.'</row>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheetRows.'</sheetData></worksheet>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="parts" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';

        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('cannot create xlsx');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();
    }

    /**
     * @return list<Row>
     */
    public static function read(string $path, string $originalName = ''): array
    {
        $ext = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            return self::readCsv($path);
        }
        if ($ext === 'xlsx') {
            return self::readXlsx($path);
        }

        // تشخیص از محتوا
        $fh = fopen($path, 'rb');
        if (! $fh) {
            throw new RuntimeException('cannot open file');
        }
        $head = fread($fh, 4) ?: '';
        fclose($fh);
        if (str_starts_with($head, 'PK')) {
            return self::readXlsx($path);
        }

        return self::readCsv($path);
    }

    /**
     * @return list<Row>
     */
    public static function readCsv(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('cannot read csv');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", $raw);
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = array_map(static fn ($v) => trim((string) $v), str_getcsv($line));
        }

        return $rows;
    }

    /**
     * @return list<Row>
     */
    public static function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('cannot open xlsx');
        }

        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $ss, $matches)) {
                foreach ($matches[1] as $si) {
                    if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tMatches)) {
                        $shared[] = html_entity_decode(implode('', $tMatches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    } else {
                        $shared[] = '';
                    }
                }
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            // بعضی فایل‌ها sheet را با نام دیگر دارند
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && str_contains($name, 'worksheets/sheet') && str_ends_with($name, '.xml')) {
                    $sheet = $zip->getFromIndex($i);
                    break;
                }
            }
        }
        $zip->close();

        if (! is_string($sheet) || $sheet === '') {
            throw new RuntimeException('worksheet missing');
        }

        $grid = [];
        if (! preg_match_all('/<c\b([^>]*)>(.*?)<\/c>|<c\b([^>]*)\/>/s', $sheet, $cells, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($cells as $cell) {
            $attrs = $cell[1] !== '' ? $cell[1] : ($cell[3] ?? '');
            $inner = $cell[2] ?? '';
            if (! preg_match('/\br="([A-Z]+)(\d+)"/', $attrs, $ref)) {
                continue;
            }
            $col = self::columnIndex($ref[1]);
            $row = (int) $ref[2];
            $type = null;
            if (preg_match('/\bt="([^"]+)"/', $attrs, $tm)) {
                $type = $tm[1];
            }

            $value = '';
            if ($type === 's') {
                if (preg_match('/<v[^>]*>(.*?)<\/v>/s', $inner, $vm)) {
                    $idx = (int) $vm[1];
                    $value = $shared[$idx] ?? '';
                }
            } elseif ($type === 'inlineStr') {
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $inner, $tm2)) {
                    $value = html_entity_decode(implode('', $tm2[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            } elseif (preg_match('/<v[^>]*>(.*?)<\/v>/s', $inner, $vm)) {
                $value = html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }

            $grid[$row][$col] = trim($value);
        }

        ksort($grid);
        $rows = [];
        foreach ($grid as $cols) {
            if ($cols === []) {
                continue;
            }
            ksort($cols);
            $max = max(array_keys($cols));
            $row = [];
            for ($i = 1; $i <= $max; $i++) {
                $row[] = $cols[$i] ?? '';
            }
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }

        return $n;
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
