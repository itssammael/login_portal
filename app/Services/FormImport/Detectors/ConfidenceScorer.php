<?php

namespace App\Services\FormImport\Detectors;

class ConfidenceScorer
{
    /**
     * Compute confidence score and level for a detected candidate.
     *
     * @param  array<string, mixed>  $candidate
     * @return array{score: float, percentage: int, level: string}
     */
    public function score(array $candidate): array
    {
        $particular = trim((string) ($candidate['particular'] ?? ''));
        $options = $candidate['options'] ?? [];
        $type = (string) ($candidate['type'] ?? 'text');
        $sourceType = (string) ($candidate['source']['type'] ?? '');

        $score = 0.50; // Baseline

        // 1. Punctuation or Question Framing
        if (str_ends_with($particular, '?')) {
            $score += 0.20;
        }

        // 2. Question number prefix (e.g. "1. ", "Q1:", "Item 1 - ")
        if (preg_match('/^(?:q(?:uestion)?\s*\d+|item\s*\d+|\d+[\.\)\:-])/i', $particular)) {
            $score += 0.15;
        }

        // 3. Question phrasing keywords
        if (preg_match('/\b(how|what|why|when|where|which|who|rate|evaluate|assess|do you|please indicate|satisfaction|level of)\b/i', $particular)) {
            $score += 0.10;
        }

        // 4. Options Analysis
        if (in_array($type, ['radio', 'select', 'checkbox'], true)) {
            $optCount = count($options);
            if ($optCount >= 2 && $optCount <= 10) {
                $score += 0.15;
            }

            // Recognized rating scale or Yes/No gives high confidence
            $optionLabels = array_map(fn ($o) => strtolower(trim(is_array($o) ? ($o['label'] ?? '') : (string) $o)), $options);
            if ($this->hasRecognizedScale($optionLabels)) {
                $score += 0.15;
            }
        } elseif (in_array($type, ['text', 'textarea', 'number'], true)) {
            if (preg_match('/\b(comments?|suggestions?|remarks?|feedback|recommendations?|explain|specify)\b/i', $particular)) {
                $score += 0.15;
            }
        }

        // 5. Source Provenance Bonus
        if ($sourceType === 'table_matrix' || $sourceType === 'spreadsheet_row') {
            $score += 0.10;
        }

        // Deductions for noisy patterns
        if (mb_strlen($particular) < 4) {
            $score -= 0.30;
        } elseif (mb_strlen($particular) > 300) {
            $score -= 0.20;
        }

        if (preg_match('/\b(page \d+|confidential|copyright|all rights reserved|form rev|signature|date:)\b/i', $particular)) {
            $score -= 0.35;
        }

        $clamped = max(0.20, min(0.98, $score));
        $percentage = (int) round($clamped * 100);

        $level = 'low';
        if ($clamped >= 0.85) {
            $level = 'high';
        } elseif ($clamped >= 0.70) {
            $level = 'medium';
        }

        return [
            'score' => round($clamped, 2),
            'percentage' => $percentage,
            'level' => $level,
        ];
    }

    /**
     * Determine if option list represents a known evaluation scale.
     *
     * @param  array<int, string>  $labels
     */
    protected function hasRecognizedScale(array $labels): bool
    {
        $joined = implode(' ', $labels);

        $knownPatterns = [
            '/\b(yes|no)\b/i',
            '/\b(satisfied|dissatisfied)\b/i',
            '/\b(agree|disagree)\b/i',
            '/\b(excellent|good|fair|poor)\b/i',
            '/\b(always|often|sometimes|rarely|never)\b/i',
            '/\b(high|medium|low)\b/i',
        ];

        foreach ($knownPatterns as $pattern) {
            if (preg_match($pattern, $joined)) {
                return true;
            }
        }

        return false;
    }
}
