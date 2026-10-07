<?php

namespace App\Services\FormImport\Detectors;

class FieldTypeDetector
{
    /**
     * Infer the most suitable field type based on question text and detected options.
     *
     * @param  array<int, mixed>  $options
     */
    public function detectType(string $questionText, array $options = []): string
    {
        $hasOptions = ! empty($options);
        $lower = strtolower($questionText);

        if ($hasOptions) {
            // Check for multi-select indicators
            if (preg_match('/\b(select all that apply|check all that apply|choose all|multiple selections?|may choose more than one|tick all)\b/i', $lower)) {
                return 'checkbox';
            }

            // If 8 or more options, suggest select (dropdown), otherwise radio
            if (count($options) >= 8) {
                return 'select';
            }

            return 'radio';
        }

        // No options: examine question text for numbers or long-form textarea
        if (preg_match('/\b(how many|number of|total number|quantity|count of|rate on a scale|score \(?\d|years of|age)\b/i', $lower)) {
            return 'number';
        }

        if (preg_match('/\b(comments?|suggestions?|remarks?|feedback|recommendations?|explain|describe|elaborate|details|notes?|narrative)\b/i', $lower)) {
            return 'textarea';
        }

        // Multiple underscore fill-in blanks e.g. "_________\n_________"
        if (preg_match('/_{10,}.*_{10,}/s', $questionText)) {
            return 'textarea';
        }

        return 'text';
    }
}
