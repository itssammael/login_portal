<?php

namespace App\Services\FormImport\Parsers;

use Exception;

class PdfParser
{
    /**
     * Parse text-based PDF file and extract text paragraphs.
     *
     * @return array{paragraphs: array<int, array{text: string, location: string, type: string}>, tables: array<int, array{rows: array<int, array<int, string>>, location: string}>}
     *
     * @throws Exception
     */
    public function parse(string $filePath): array
    {
        $rawContent = file_get_contents($filePath);
        if ($rawContent === false || strlen($rawContent) === 0) {
            throw new Exception('Unable to read PDF file.');
        }

        if (! str_starts_with($rawContent, '%PDF')) {
            throw new Exception('File is not a valid PDF document.');
        }

        $extractedText = $this->extractTextFromPdfStreams($rawContent);
        if (trim($extractedText) === '') {
            // Document might be a scanned image-only PDF
            throw new Exception('No selectable text could be extracted from this PDF. It may be a scanned image document. Please upload a Word (.docx), Excel (.xlsx), or text document instead.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $extractedText);
        $paragraphs = [];
        $pCount = 1;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $paragraphs[] = [
                    'text' => $trimmed,
                    'location' => 'PDF Line #'.$pCount,
                    'type' => 'paragraph',
                ];
                $pCount++;
            }
        }

        return [
            'paragraphs' => $paragraphs,
            'tables' => [],
        ];
    }

    /**
     * Locate and extract text from all PDF streams.
     */
    protected function extractTextFromPdfStreams(string $content): string
    {
        $allText = '';

        // Extract any raw text from text blocks directly if present
        $directText = $this->extractTextFromStreamData($content);
        if ($directText !== '') {
            $allText .= $directText."\n";
        }

        // Match all stream ... endstream blocks
        if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]*endstream/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $matchInfo) {
                $streamData = $matchInfo[0];
                $streamOffset = $matchInfo[1];

                // Check dictionary immediately preceding stream
                $precedingHeader = substr($content, max(0, $streamOffset - 300), min(300, $streamOffset));
                $isFlate = str_contains($precedingHeader, 'FlateDecode');

                if ($isFlate) {
                    $decompressed = @gzuncompress($streamData);
                    if ($decompressed === false) {
                        $decompressed = @gzinflate($streamData);
                    }
                    if ($decompressed === false) {
                        $decompressed = @gzinflate(substr($streamData, 2));
                    }
                    if ($decompressed !== false) {
                        $streamText = $this->extractTextFromStreamData($decompressed);
                        if ($streamText !== '') {
                            $allText .= $streamText."\n";
                        }
                    }
                } else {
                    $streamText = $this->extractTextFromStreamData($streamData);
                    if ($streamText !== '') {
                        $allText .= $streamText."\n";
                    }
                }
            }
        }

        return trim($allText);
    }

    /**
     * Extract text strings using PDF text operators (BT...ET, Tj, TJ, ', ").
     */
    protected function extractTextFromStreamData(string $data): string
    {
        $text = '';

        // Extract text between BT (Begin Text) and ET (End Text)
        preg_match_all('/BT(.*?)ET/s', $data, $btMatches);

        foreach ($btMatches[1] as $block) {
            // Match TJ array operator: [(Part 1) 20 (Part 2)] TJ
            preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjMatches);
            foreach ($tjMatches[1] as $tj) {
                preg_match_all('/\((.*?)\)/s', $tj, $strMatches);
                $combined = '';
                foreach ($strMatches[1] as $str) {
                    $combined .= $this->unescapePdfString($str);
                }
                if ($combined !== '') {
                    $text .= $combined."\n";
                }
            }

            // Match simple string operators: (Text) Tj or (Text) ' or (Text) "
            preg_match_all('/\((.*?)\)\s*(?:Tj|\'|\")/s', $block, $tjSimpleMatches);
            foreach ($tjSimpleMatches[1] as $str) {
                $cleaned = $this->unescapePdfString($str);
                if ($cleaned !== '') {
                    $text .= $cleaned."\n";
                }
            }
        }

        return $text;
    }

    /**
     * Unescape standard PDF octal and backslash escapes.
     */
    protected function unescapePdfString(string $str): string
    {
        $str = str_replace(
            ['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'],
            ['(', ')', '\\', "\n", "\r", "\t"],
            $str
        );

        // Convert octal escapes (\ddd)
        $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
            return chr(octdec($m[1]));
        }, $str) ?? $str;

        return trim($str);
    }
}
