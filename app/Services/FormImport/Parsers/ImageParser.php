<?php

namespace App\Services\FormImport\Parsers;

use Exception;

class ImageParser
{
    /**
     * Parse an image file using OCR if available, or throw a descriptive exception.
     *
     * @return array{paragraphs: array<int, array{text: string, location: string, type: string}>, tables: array<int, array{rows: array<int, array<int, string>>, location: string}>}
     *
     * @throws Exception
     */
    public function parse(string $filePath): array
    {
        $tesseract = config('services.ocr.tesseract_path', 'tesseract');

        // Check if tesseract is available
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $checkCmd = $isWindows ? "where {$tesseract} 2>NUL" : "which {$tesseract} 2>/dev/null";

        $output = [];
        $returnVar = -1;
        @exec($checkCmd, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new Exception('OCR engine is not configured on this server to read scanned images. Please upload an editable Word (.docx), Excel (.xlsx/.csv), or text-based PDF document instead.');
        }

        // Run tesseract
        $escapedFile = escapeshellarg($filePath);
        $ocrOutput = [];
        $ocrStatus = -1;
        @exec("{$tesseract} {$escapedFile} stdout 2>NUL", $ocrOutput, $ocrStatus);

        if ($ocrStatus !== 0 || empty($ocrOutput)) {
            throw new Exception('We could not reliably extract text from this image. Please upload a clear digital Word or spreadsheet document instead.');
        }

        $paragraphs = [];
        $pCount = 1;
        foreach ($ocrOutput as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $paragraphs[] = [
                    'text' => $trimmed,
                    'location' => 'Image OCR Line #'.$pCount,
                    'type' => 'ocr',
                ];
                $pCount++;
            }
        }

        return [
            'paragraphs' => $paragraphs,
            'tables' => [],
        ];
    }
}
