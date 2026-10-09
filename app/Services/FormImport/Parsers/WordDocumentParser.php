<?php

namespace App\Services\FormImport\Parsers;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use ZipArchive;

class WordDocumentParser
{
    /**
     * Parse a DOCX file and extract paragraphs, checkboxes, and tables.
     *
     * @return array{paragraphs: array<int, array{text: string, location: string, type: string}>, tables: array<int, array{rows: array<int, array<int, string>>, location: string}>}
     *
     * @throws Exception
     */
    public function parse(string $filePath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($filePath) !== true) {
            throw new Exception('Unable to open DOCX file archive.');
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xmlContent === false || trim($xmlContent) === '') {
            throw new Exception('Invalid DOCX document: word/document.xml is missing or empty.');
        }

        $dom = new DOMDocument;
        // Suppress warnings for malformed XML fragments
        libxml_use_internal_errors(true);
        $dom->loadXML($xmlContent, LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $paragraphs = [];
        $tables = [];

        // 1. Extract Tables
        $tableNodes = $xpath->query('//w:body/w:tbl | //w:tbl');
        if ($tableNodes) {
            foreach ($tableNodes as $tIdx => $tblNode) {
                if (! $tblNode instanceof DOMElement) {
                    continue;
                }

                $rows = [];
                $rowNodes = $xpath->query('.//w:tr', $tblNode);
                if ($rowNodes) {
                    foreach ($rowNodes as $rowNode) {
                        if (! $rowNode instanceof DOMElement) {
                            continue;
                        }
                        $cellValues = [];
                        $cellNodes = $xpath->query('.//w:tc', $rowNode);
                        if ($cellNodes) {
                            foreach ($cellNodes as $cellNode) {
                                if (! $cellNode instanceof DOMElement) {
                                    continue;
                                }
                                $cellText = $this->extractNodeText($cellNode, $xpath);
                                $cellValues[] = trim($cellText);
                            }
                        }
                        if (! empty(array_filter($cellValues, fn ($c) => $c !== ''))) {
                            $rows[] = $cellValues;
                        }
                    }
                }

                if (! empty($rows)) {
                    $tables[] = [
                        'rows' => $rows,
                        'location' => 'Word Table #'.($tIdx + 1),
                    ];
                }
            }
        }

        // 2. Extract Top-level Paragraphs (excluding paragraphs nested inside tables)
        $paragraphNodes = $xpath->query('//w:body/w:p');
        if ($paragraphNodes) {
            $pCounter = 1;
            foreach ($paragraphNodes as $pNode) {
                if (! $pNode instanceof DOMElement) {
                    continue;
                }

                $pText = $this->extractNodeText($pNode, $xpath);
                $trimmed = trim($pText);
                if ($trimmed !== '') {
                    $paragraphs[] = [
                        'text' => $trimmed,
                        'location' => 'Word Paragraph #'.$pCounter,
                        'type' => 'paragraph',
                    ];
                    $pCounter++;
                }
            }
        }

        return [
            'paragraphs' => $paragraphs,
            'tables' => $tables,
        ];
    }

    /**
     * Extract text and symbols (e.g. checkboxes) from a DOM node.
     */
    protected function extractNodeText(DOMElement $node, DOMXPath $xpath): string
    {
        $text = '';

        // Iterate over child text runs and symbol nodes
        $runNodes = $xpath->query('.//w:r | .//w:sym | .//w:checkBox | .//w:sdt', $node);
        if ($runNodes && $runNodes->length > 0) {
            foreach ($runNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }

                $localName = $child->localName;

                // Checkbox form field / content control
                if ($localName === 'checkBox' || $localName === 'sdt') {
                    if ($xpath->query('.//w:checked[@w:val="1"]', $child)->length > 0) {
                        $text .= '☑ ';
                    } else {
                        $text .= '☐ ';
                    }

                    continue;
                }

                // Wingdings symbol checkbox
                if ($localName === 'sym') {
                    $font = $child->getAttribute('w:font');
                    $char = strtolower($child->getAttribute('w:char'));
                    if (str_contains(strtolower($font), 'wingdings') || in_array($char, ['f0a8', 'f0fe', 'f0fd', '25a1'], true)) {
                        $text .= '☐ ';
                    }

                    continue;
                }

                // Standard text run
                if ($localName === 'r') {
                    // Check for embedded sym inside run
                    $syms = $xpath->query('.//w:sym', $child);
                    if ($syms && $syms->length > 0) {
                        $text .= '☐ ';
                    }

                    $tNodes = $xpath->query('.//w:t', $child);
                    if ($tNodes) {
                        foreach ($tNodes as $t) {
                            $text .= $t->nodeValue;
                        }
                    }

                    // Check for line breaks
                    if ($xpath->query('.//w:br', $child)->length > 0) {
                        $text .= "\n";
                    }
                }
            }
        } else {
            $text = $node->textContent;
        }

        return $text;
    }
}
