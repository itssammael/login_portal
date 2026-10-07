<?php

namespace App\Services\FormImport\Detectors;

use Illuminate\Support\Str;

class OptionDetector
{
    /**
     * Common Likert and rating scales for evaluation forms.
     */
    public const KNOWN_SCALES = [
        'satisfaction_5' => [
            'Very Satisfied',
            'Satisfied',
            'Neutral',
            'Dissatisfied',
            'Very Dissatisfied',
        ],
        'agreement_5' => [
            'Strongly Agree',
            'Agree',
            'Neutral',
            'Disagree',
            'Strongly Disagree',
        ],
        'agreement_4' => [
            'Strongly Agree',
            'Agree',
            'Disagree',
            'Strongly Disagree',
        ],
        'quality_4' => [
            'Excellent',
            'Good',
            'Fair',
            'Poor',
        ],
        'quality_5' => [
            'Outstanding',
            'Very Good',
            'Good',
            'Fair',
            'Poor',
        ],
        'frequency_5' => [
            'Always',
            'Often',
            'Sometimes',
            'Rarely',
            'Never',
        ],
        'yes_no' => [
            'Yes',
            'No',
        ],
    ];

    /**
     * Bullet, checkbox, and radio symbols regex.
     */
    protected string $bulletRegex = '/^(?:[\x{2610}\x{2611}\x{2612}\x{25A1}\x{25A0}\x{25CB}\x{25CF}\x{25EF}\x{25E6}\x{2022}\x{25AA}\x{25AB}\x{2713}\x{2714}\x{2717}\x{2718}\x{1F518}\x{2B55}\x{2B58}]|\[[\s_xX]?\]|\([\s_xX]?\)|[-*•])\s*/u';

    /**
     * Numbered/lettered choice prefix regex: e.g. "a)", "(a)", "A.", "1)"
     */
    protected string $choicePrefixRegex = '/^(?:\(?[a-gA-G]\)|\(?[1-9]\)|[a-gA-G]\.)\s+/';

    /**
     * Determine if a text line appears to be an answer option.
     */
    public function isLikelyOptionLine(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match($this->bulletRegex, $trimmed)) {
            return true;
        }

        if (preg_match($this->choicePrefixRegex, $trimmed)) {
            return true;
        }

        // Single word or short phrase matching known rating words
        $lower = strtolower($trimmed);
        $scaleWords = [
            'yes', 'no', 'agree', 'disagree', 'strongly agree', 'strongly disagree',
            'excellent', 'good', 'fair', 'poor', 'very good', 'outstanding',
            'satisfied', 'dissatisfied', 'very satisfied', 'very dissatisfied', 'neutral',
            'na', 'n/a', 'not applicable', 'always', 'often', 'sometimes', 'rarely', 'never',
        ];

        return in_array($lower, $scaleWords, true);
    }

    /**
     * Clean and strip prefixes from an option label.
     */
    public function cleanOptionLabel(string $text): string
    {
        $cleaned = trim($text);

        // Strip bullet / box symbols
        $cleaned = preg_replace($this->bulletRegex, '', $cleaned) ?? $cleaned;

        // Strip choice prefixes: e.g. "A. ", "a) "
        $cleaned = preg_replace($this->choicePrefixRegex, '', $cleaned) ?? $cleaned;

        // Strip trailing punctuation like semicolons or trailing periods if short
        $cleaned = rtrim($cleaned, " \t\n\r\0\x0B;,");

        return trim($cleaned);
    }

    /**
     * Convert an array of raw option labels into structured option objects.
     *
     * @param  array<int, string>  $rawOptions
     * @return array<int, array{value: string, label: string, is_other: bool}>
     */
    public function normalizeOptions(array $rawOptions): array
    {
        $normalized = [];
        $usedValues = [];

        foreach ($rawOptions as $idx => $opt) {
            $label = $this->cleanOptionLabel((string) $opt);
            if ($label === '') {
                continue;
            }

            $lower = strtolower($label);
            $isOther = in_array($lower, ['other', 'others', 'please specify', 'other, please specify', 'other (please specify)'], true)
                || str_starts_with($lower, 'other:');

            $slug = Str::slug($label, '_');
            if (empty($slug)) {
                $slug = 'option_'.($idx + 1);
            }

            // Ensure unique value key
            $val = $slug;
            $counter = 1;
            while (in_array($val, $usedValues, true)) {
                $val = "{$slug}_{$counter}";
                $counter++;
            }
            $usedValues[] = $val;

            $normalized[] = [
                'value' => $val,
                'label' => $label,
                'is_other' => $isOther,
            ];
        }

        return $normalized;
    }

    /**
     * Detect embedded inline options in a single string (e.g. "☐ Yes  ☐ No" or "[ ] Excellent [ ] Good").
     *
     * @return array<int, string>
     */
    public function detectInlineOptions(string $text): array
    {
        // Split on multiple spaces preceding a bullet or checkbox symbol
        $parts = preg_split('/(?:\s{2,}|\t+)(?=[\x{2610}\x{2611}\x{2612}\x{25A1}\x{25A0}\x{25CB}\x{25CF}\x{25EF}\x{25E6}\x{2022}\x{25AA}\x{25AB}\x{2713}\x{2714}\x{2717}\x{2718}\x{1F518}\x{2B55}\x{2B58}]|\[[\s_xX]?\]|\([\s_xX]?\)|[-*•]|\(?[a-dA-D]\)|\(?[1-5]\))/u', trim($text));

        if (is_array($parts) && count($parts) >= 2) {
            $options = [];
            foreach ($parts as $part) {
                $cleaned = $this->cleanOptionLabel($part);
                if ($cleaned !== '') {
                    $options[] = $cleaned;
                }
            }
            if (count($options) >= 2) {
                return $options;
            }
        }

        return [];
    }
}
