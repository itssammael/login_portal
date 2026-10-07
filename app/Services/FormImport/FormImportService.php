<?php

namespace App\Services\FormImport;

use App\Services\FormImport\Detectors\QuestionDetector;
use App\Services\FormImport\Parsers\ImageParser;
use App\Services\FormImport\Parsers\PdfParser;
use App\Services\FormImport\Parsers\SpreadsheetParser;
use App\Services\FormImport\Parsers\WordDocumentParser;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class FormImportService
{
    public function __construct(
        protected WordDocumentParser $wordParser,
        protected SpreadsheetParser $spreadsheetParser,
        protected PdfParser $pdfParser,
        protected ImageParser $imageParser,
        protected QuestionDetector $questionDetector,
        protected SchemaCandidateMapper $candidateMapper,
    ) {}

    /**
     * Analyze an uploaded form/questionnaire document and extract schema candidates.
     *
     * @return array{status: string, filename: string, file_type: string, file_size: int, summary: array<string, int>, candidates: array<int, mixed>, warnings: array<int, string>}
     *
     * @throws Exception
     */
    public function analyzeDocument(UploadedFile|string $file, ?string $originalName = null): array
    {
        $filePath = is_string($file) ? $file : $file->getRealPath();
        $filename = $originalName ?? (is_string($file) ? basename($file) : $file->getClientOriginalName());
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $fileSize = is_string($file) ? (filesize($file) ?: 0) : $file->getSize();

        if (empty($extension)) {
            $extension = is_string($file) ? 'bin' : ($file->guessExtension() ?? 'bin');
        }

        $warnings = [];

        try {
            // 1. Parse raw structure from document
            $parsed = match ($extension) {
                'docx' => $this->wordParser->parse($filePath),
                'xlsx', 'xls', 'csv', 'txt' => $this->spreadsheetParser->parse($filePath, $extension),
                'pdf' => $this->pdfParser->parse($filePath),
                'png', 'jpg', 'jpeg', 'webp' => $this->imageParser->parse($filePath),
                default => throw new Exception("Unsupported file format: .{$extension}"),
            };

            $paragraphs = $parsed['paragraphs'] ?? [];
            $tables = $parsed['tables'] ?? [];

            // 2. Detect candidate questions from tables and paragraphs
            $tableCandidates = $this->questionDetector->detectFromTables($tables);
            $paragraphCandidates = $this->questionDetector->detectFromParagraphs($paragraphs);

            $allDetected = array_merge($tableCandidates, $paragraphCandidates);

            if (empty($allDetected)) {
                throw new Exception('No usable questions or evaluation fields were detected in this document. Please check the document format or create fields manually.');
            }

            // 3. Map into normalized application schema candidates
            $candidates = $this->candidateMapper->mapToCandidates($allDetected);

            // 4. Summarize confidence stats
            $highCount = 0;
            $mediumCount = 0;
            $lowCount = 0;

            foreach ($candidates as $candidate) {
                $level = $candidate['confidence']['level'] ?? 'medium';
                if ($level === 'high') {
                    $highCount++;
                } elseif ($level === 'low') {
                    $lowCount++;
                } else {
                    $mediumCount++;
                }
            }

            if ($lowCount > 0) {
                $warnings[] = "{$lowCount} field(s) were detected with lower confidence. Please review them carefully before importing.";
            }

            return [
                'status' => 'success',
                'filename' => $filename,
                'file_type' => $extension,
                'file_size' => $fileSize,
                'summary' => [
                    'total_detected' => count($candidates),
                    'high_confidence' => $highCount,
                    'medium_confidence' => $mediumCount,
                    'low_confidence' => $lowCount,
                ],
                'candidates' => $candidates,
                'warnings' => $warnings,
            ];
        } catch (Exception $e) {
            Log::warning('Form import document analysis failed', [
                'filename' => $filename,
                'extension' => $extension,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
