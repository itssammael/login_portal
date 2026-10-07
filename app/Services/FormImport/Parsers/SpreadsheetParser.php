<?php

namespace App\Services\FormImport\Parsers;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use ZipArchive;

class SpreadsheetParser
{
    /**
     * Parse spreadsheet file (XLSX or CSV).
     *
     * @return array{paragraphs: array<int, array{text: string, location: string, type: string}>, tables: array<int, array{rows: array<int, array<int, string>>, location: string}>}
     *
     * @throws Exception
     */
    public function parse(string $filePath, string $extension): array
    {
        $ext = strtolower($extension);

        if ($ext === 'csv' || $ext === 'txt') {
            return $this->parseCsv($filePath);
        }

        if ($ext === 'xlsx' || $ext === 'xls') {
            return $this->parseXlsx($filePath);
        }

        throw new Exception("Unsupported spreadsheet format: .{$extension}");
    }

    /**
     * Parse CSV file with automatic delimiter detection.
     */
    protected function parseCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new Exception('Unable to open CSV file.');
        }

        // Detect delimiter from first line
        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = ',';
        if ($firstLine !== false) {
            $delimiters = [',', ';', "\t", '|'];
            $maxCount = 0;
            foreach ($delimiters as $delim) {
                $count = substr_count($firstLine, $delim);
                if ($count > $maxCount) {
                    $maxCount = $count;
                    $delimiter = $delim;
                }
            }
        }

        $rows = [];
        while (($row = fgetcsv($handle, 4096, $delimiter)) !== false) {
            $cleaned = array_map(fn ($cell) => trim((string) $cell), $row);
            if (! empty(array_filter($cleaned, fn ($c) => $c !== ''))) {
                $rows[] = $cleaned;
            }
        }
        fclose($handle);

        return [
            'paragraphs' => [],
            'tables' => [
                [
                    'rows' => $rows,
                    'location' => 'CSV Document',
                ],
            ],
        ];
    }

    /**
     * Parse XLSX file directly via ZipArchive and XML extraction.
     */
    protected function parseXlsx(string $filePath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($filePath) !== true) {
            // If opening as zip fails, attempt CSV fallback
            return $this->parseCsv($filePath);
        }

        // 1. Read shared strings
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false && trim($sharedXml) !== '') {
            $sharedStrings = $this->parseSharedStrings($sharedXml);
        }

        // 2. Discover worksheets
        $tables = [];
        for ($i = 1; $i <= 10; $i++) {
            $sheetPath = "xl/worksheets/sheet{$i}.xml";
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                break;
            }

            $rows = $this->parseWorksheet($sheetXml, $sharedStrings);
            if (! empty($rows)) {
                $tables[] = [
                    'rows' => $rows,
                    'location' => "Sheet #{$i}",
                ];
            }
        }

        $zip->close();

        return [
            'paragraphs' => [],
            'tables' => $tables,
        ];
    }

    /**
     * Parse shared strings table.
     *
     * @return array<int, string>
     */
    protected function parseSharedStrings(string $xmlContent): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadXML($xmlContent, LIBXML_NOENT | LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];
        $siNodes = $xpath->query('//s:si | //si');
        if ($siNodes) {
            foreach ($siNodes as $si) {
                if (! $si instanceof DOMElement) {
                    continue;
                }
                $tNodes = $xpath->query('.//s:t | .//t', $si);
                $str = '';
                if ($tNodes) {
                    foreach ($tNodes as $t) {
                        $str .= $t->nodeValue;
                    }
                }
                $strings[] = $str;
            }
        }

        return $strings;
    }

    /**
     * Parse an individual worksheet XML.
     *
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    protected function parseWorksheet(string $xmlContent, array $sharedStrings): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadXML($xmlContent, LIBXML_NOENT | LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        $rowNodes = $xpath->query('//s:row | //row');
        if (! $rowNodes) {
            return [];
        }

        foreach ($rowNodes as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }

            $cells = [];
            $cellNodes = $xpath->query('.//s:c | .//c', $rowNode);
            if ($cellNodes) {
                foreach ($cellNodes as $cellNode) {
                    if (! $cellNode instanceof DOMElement) {
                        continue;
                    }

                    $type = $cellNode->getAttribute('t');
                    $valNode = $xpath->query('.//s:v | .//v', $cellNode)->item(0);
                    $val = $valNode ? $valNode->nodeValue : '';

                    if ($type === 's' && is_numeric($val)) {
                        $idx = (int) $val;
                        $cellText = $sharedStrings[$idx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $isNode = $xpath->query('.//s:is/s:t | .//is/t', $cellNode)->item(0);
                        $cellText = $isNode ? $isNode->nodeValue : '';
                    } else {
                        $cellText = $val;
                    }

                    $cells[] = trim((string) $cellText);
                }
            }

            if (! empty(array_filter($cells, fn ($c) => $c !== ''))) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }
}
