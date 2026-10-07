<?php

namespace App\Services\FormImport;

use Illuminate\Support\Str;

class SchemaCandidateMapper
{
    /**
     * Map raw detected candidates into application schema candidates.
     *
     * @param  array<int, array<string, mixed>>  $detectedCandidates
     * @return array<int, array<string, mixed>>
     */
    public function mapToCandidates(array $detectedCandidates): array
    {
        $mapped = [];
        $usedIds = [];

        foreach ($detectedCandidates as $index => $candidate) {
            $particular = trim((string) ($candidate['particular'] ?? ''));
            if ($particular === '') {
                continue;
            }

            // Generate clean unique snake_case ID
            $baseId = Str::snake($particular);
            $baseId = preg_replace('/[^a-z0-9_]/', '', strtolower($baseId)) ?: 'field';
            if (mb_strlen($baseId) > 40) {
                $baseId = mb_substr($baseId, 0, 40);
                $baseId = rtrim($baseId, '_');
            }

            $id = $baseId;
            $counter = 1;
            while (in_array($id, $usedIds, true)) {
                $id = "{$baseId}_{$counter}";
                $counter++;
            }
            $usedIds[] = $id;

            $type = (string) ($candidate['type'] ?? 'text');
            $options = $candidate['options'] ?? [];

            // If radio/select/checkbox has no options, fallback to text or provide default yes/no
            if (in_array($type, ['radio', 'select', 'checkbox'], true) && empty($options)) {
                $type = 'text';
            }

            $hasOther = false;
            foreach ($options as $opt) {
                if (! empty($opt['is_other'])) {
                    $hasOther = true;
                    break;
                }
            }

            // Number limits detection if applicable
            $min = null;
            $max = null;
            if ($type === 'number') {
                if (preg_match('/\b(?:scale of|from|between)\s*([0-9]+)\s*(?:to|-)\s*([0-9]+)\b/i', $particular, $matches)) {
                    $min = (float) $matches[1];
                    $max = (float) $matches[2];
                }
            }

            $mapped[] = [
                'id' => $id,
                'particular' => $particular,
                'type' => $type,
                'weight' => $index + 1,
                'required' => (bool) ($candidate['required'] ?? false),
                'placeholder' => null,
                'min' => $min,
                'max' => $max,
                'allow_other' => $hasOther,
                'allow_custom_value' => false,
                'option_source' => 'static',
                'function_ids' => [],
                'options' => $options,
                'section' => $candidate['section'] ?? null,
                'source' => $candidate['source'] ?? [
                    'type' => 'document',
                    'location' => 'Line '.($index + 1),
                    'snippet' => mb_substr($particular, 0, 80),
                ],
                'confidence' => $candidate['confidence'] ?? [
                    'score' => 0.80,
                    'percentage' => 80,
                    'level' => 'medium',
                ],
                'include' => true, // Selected by default for import
            ];
        }

        return $mapped;
    }
}
