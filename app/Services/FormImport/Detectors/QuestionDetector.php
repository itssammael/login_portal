<?php

namespace App\Services\FormImport\Detectors;

class QuestionDetector
{
    public function __construct(
        protected OptionDetector $optionDetector,
        protected FieldTypeDetector $fieldTypeDetector,
        protected ConfidenceScorer $confidenceScorer,
    ) {}

    /**
     * Common document noise phrases to filter out or ignore as questions.
     */
    protected array $noisePatterns = [
        '/^page\s+\d+(\s+of\s+\d+)?$/i',
        '/^(republic of the philippines|office of the|department of)/i',
        '/^(document code|form no|rev\.|revision|series of \d{4})/i',
        '/^(instructions|directions|general instructions)[\s\:]/i',
        '/^(signature|conforme|noted by|approved by|prepared by)[\s\:]/i',
        '/^(date|time|venue)[\s\:]*$/i',
    ];

    /**
     * Check if a text line is document noise.
     */
    public function isNoise(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '' || mb_strlen($trimmed) < 2) {
            return true;
        }

        foreach ($this->noisePatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a text line is a section heading.
     */
    public function isSectionHeading(string $line): bool
    {
        $trimmed = trim($line);

        if (preg_match('/^(?:part|section|category)\s+[a-z0-9]+[\:\.\-]/i', $trimmed)) {
            return true;
        }

        if (preg_match('/^[A-Z][\.\)]\s+[A-Z0-9\s\-\/\&]{4,}$/', $trimmed) && ! str_ends_with($trimmed, '?')) {
            return true;
        }

        // Roman numerals e.g. "I. GENERAL INFORMATION"
        if (preg_match('/^(?:I|II|III|IV|V|VI|VII|VIII|IX|X)[\.\)]\s+[A-Z\s]{4,}$/', $trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * Process extracted paragraphs and document structures into candidates.
     *
     * @param  array<int, array{text: string, location?: string, page?: int, type?: string}>  $paragraphs
     * @return array<int, array<string, mixed>>
     */
    public function detectFromParagraphs(array $paragraphs): array
    {
        $candidates = [];
        $currentSection = null;
        $currentCandidate = null;

        foreach ($paragraphs as $p) {
            $text = trim((string) ($p['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            if ($this->isNoise($text)) {
                continue;
            }

            // Check if section heading
            if ($this->isSectionHeading($text)) {
                if ($currentCandidate !== null) {
                    $candidates[] = $this->finalizeCandidate($currentCandidate);
                    $currentCandidate = null;
                }
                $currentSection = $text;

                continue;
            }

            // Check if this line contains inline options: e.g. "☐ Excellent  ☐ Good  ☐ Fair"
            $inlineOptions = $this->optionDetector->detectInlineOptions($text);
            if (! empty($inlineOptions)) {
                if ($currentCandidate !== null) {
                    // Append inline options to current question
                    $currentCandidate['raw_options'] = array_merge($currentCandidate['raw_options'], $inlineOptions);

                    continue;
                }
            }

            // Check if line is an answer option: e.g. "☐ Very Satisfied" or "A. Yes"
            if ($this->optionDetector->isLikelyOptionLine($text)) {
                $cleanedOpt = $this->optionDetector->cleanOptionLabel($text);
                if ($currentCandidate !== null && $cleanedOpt !== '') {
                    $currentCandidate['raw_options'][] = $cleanedOpt;

                    continue;
                }
            }

            // Otherwise, line is considered a question candidate
            if ($currentCandidate !== null) {
                $candidates[] = $this->finalizeCandidate($currentCandidate);
            }

            $required = false;
            $cleanParticular = $text;

            // Check for required marker (*, [Required], (Mandatory))
            if (preg_match('/[\*]|(?:\(|\[)\s*(?:required|mandatory)\s*(?:\)|\])/i', $cleanParticular)) {
                $required = true;
                $cleanParticular = trim(preg_replace('/[\*]|(?:\(|\[)\s*(?:required|mandatory)\s*(?:\)|\])/i', '', $cleanParticular));
            }

            $currentCandidate = [
                'particular' => $cleanParticular,
                'raw_options' => [],
                'required' => $required,
                'section' => $currentSection,
                'source' => [
                    'type' => $p['type'] ?? 'paragraph',
                    'location' => $p['location'] ?? 'Document flow',
                    'snippet' => mb_substr($text, 0, 100),
                ],
            ];
        }

        if ($currentCandidate !== null) {
            $candidates[] = $this->finalizeCandidate($currentCandidate);
        }

        return $candidates;
    }

    /**
     * Process extracted tables into candidate questions (matrix detection).
     *
     * @param  array<int, array{rows: array<int, array<int, string>>, location?: string}>  $tables
     * @return array<int, array<string, mixed>>
     */
    public function detectFromTables(array $tables): array
    {
        $candidates = [];

        foreach ($tables as $table) {
            $rows = $table['rows'] ?? [];
            if (count($rows) < 2) {
                continue;
            }

            // Analyze header row (row 0)
            $headerRow = array_map('trim', $rows[0]);
            $numCols = count($headerRow);

            // Determine if header row defines rating options
            // e.g. [Criteria, Excellent, Good, Fair, Poor] or [No., Question, 5, 4, 3, 2, 1]
            $ratingOptions = [];
            $questionColIndex = 0;

            // Check if first column is an index (No., #) and second is question
            if (isset($headerRow[0]) && preg_match('/^(no\.?|#|item)$/i', $headerRow[0]) && isset($headerRow[1])) {
                $questionColIndex = 1;
            }

            for ($c = $questionColIndex + 1; $c < $numCols; $c++) {
                $colText = $headerRow[$c] ?? '';
                if ($colText !== '' && ! preg_match('/^(remarks?|comments?|score|total)$/i', $colText)) {
                    $ratingOptions[] = $colText;
                }
            }

            $isRatingMatrix = count($ratingOptions) >= 2;

            // Process data rows
            for ($r = 1; $r < count($rows); $r++) {
                $row = $rows[$r];
                $questionText = trim($row[$questionColIndex] ?? '');

                // If question text is empty, check first column
                if ($questionText === '' && isset($row[0])) {
                    $questionText = trim($row[0]);
                }

                if ($this->isNoise($questionText) || mb_strlen($questionText) < 2) {
                    continue;
                }

                // If table is a rating matrix, attach the column header options
                $rowOptions = $isRatingMatrix ? $ratingOptions : [];

                // If not matrix, check if this row has options in other cells
                if (! $isRatingMatrix && $numCols > $questionColIndex + 1) {
                    for ($c = $questionColIndex + 1; $c < $numCols; $c++) {
                        $cellVal = trim($row[$c] ?? '');
                        if ($cellVal !== '' && ! preg_match('/^[_\-\s\.]{2,}$/', $cellVal)) {
                            $rowOptions[] = $cellVal;
                        }
                    }
                }

                $loc = ($table['location'] ?? 'Table').', Row '.($r + 1);

                $candidates[] = $this->finalizeCandidate([
                    'particular' => $questionText,
                    'raw_options' => $rowOptions,
                    'required' => false,
                    'section' => null,
                    'source' => [
                        'type' => $isRatingMatrix ? 'table_matrix' : 'table_row',
                        'location' => $loc,
                        'snippet' => mb_substr($questionText, 0, 100),
                    ],
                ]);
            }
        }

        return $candidates;
    }

    /**
     * Finalize candidate by normalizing options, detecting field type, and scoring confidence.
     *
     * @param  array<string, mixed>  $rawCandidate
     * @return array<string, mixed>
     */
    protected function finalizeCandidate(array $rawCandidate): array
    {
        $particular = trim((string) ($rawCandidate['particular'] ?? ''));
        $rawOptions = $rawCandidate['raw_options'] ?? [];

        $normalizedOptions = $this->optionDetector->normalizeOptions($rawOptions);
        $type = $this->fieldTypeDetector->detectType($particular, $normalizedOptions);

        $candidate = [
            'particular' => $particular,
            'type' => $type,
            'required' => (bool) ($rawCandidate['required'] ?? false),
            'section' => $rawCandidate['section'] ?? null,
            'options' => $normalizedOptions,
            'source' => $rawCandidate['source'] ?? [
                'type' => 'document',
                'location' => 'Document',
                'snippet' => mb_substr($particular, 0, 100),
            ],
        ];

        $confidence = $this->confidenceScorer->score($candidate);
        $candidate['confidence'] = $confidence;

        return $candidate;
    }
}
