<?php

namespace App\Services;

use App\Http\Requests\SaveFeedbackAnalyticsReportRequest;
use App\Models\Feedback;
use App\Models\FeedbackAnalyticsReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FeedbackAnalyticsService
{
    /** @return array<string, array<string, mixed>> */
    public function questions(Feedback $feedback): array
    {
        return collect($feedback->schema['fields'] ?? [])
            ->filter(fn (array $field): bool => in_array($field['type'] ?? '', ['radio', 'select', 'checkbox', 'number'], true))
            ->keyBy('id')->all();
    }

    /** @param array<string, mixed> $field */
    public function sensitive(array $field): bool
    {
        return (bool) preg_match('/agency|designation|function|location|gender|sex|\bage\b|age_group|demograph|ethnic|religio/i', ($field['id'] ?? '').' '.($field['particular'] ?? ''));
    }

    /** @param array<string, mixed> $field */
    public function identifying(array $field): bool
    {
        $id = (string) ($field['id'] ?? '');
        $particular = (string) ($field['particular'] ?? '');

        if (preg_match('/(^|_)(name|email|phone|address|identifier|id|password|secret|token)($|_)/i', $id)) {
            return true;
        }

        if (preg_match('/\b(?:to\s+address|addressing|address\s+(?:significant|relevant|the|a|an|problems?|concerns?|issues?|needs?|challenges?))\b/i', $particular)) {
            $particular = (string) preg_replace('/\baddress\b/i', '', $particular);
        }

        return (bool) preg_match('/\b(name|email|phone|address|identifier|password|secret|token)\b/i', $particular)
            || (bool) preg_match('/(^|\s)id(\s|$)/i', $particular);
    }

    /** @param array<string, mixed> $configuration */
    public function validate(Feedback $feedback, array $configuration, bool $publishing = false, bool $approveSensitive = false): void
    {
        Validator::make(['configuration' => $configuration], (new SaveFeedbackAnalyticsReportRequest)->rules())->validate();
        if (! empty($configuration['period_start']) && ! empty($configuration['period_end']) && $configuration['period_end'] < $configuration['period_start']) {
            throw ValidationException::withMessages(['configuration.period_end' => 'The reporting period must end on or after its start date.']);
        }
        $questions = $this->questions($feedback);
        if ($publishing && empty($configuration['charts'])) {
            throw ValidationException::withMessages(['configuration.charts' => 'Add at least one chart before publishing.']);
        }
        foreach ($configuration['charts'] as $index => $chart) {
            if (($chart['kind'] ?? 'standard') === 'custom') {
                $rows = $chart['rows'] ?? [];
                if (empty($rows)) {
                    throw ValidationException::withMessages(["configuration.charts.$index.rows" => 'Add at least one row to the custom graph data table.']);
                }
                foreach ($rows as $rowIndex => $row) {
                    if (($row['source'] ?? 'question') === 'question') {
                        $questionId = $row['question'] ?? '';
                        $field = $questions[$questionId] ?? null;
                        $error = null;
                        if (! $field) {
                            $error = 'Select a valid structured question from this form. Free-text responses cannot be published.';
                        } elseif ($this->identifying($field)) {
                            $error = 'Identifying and credential fields cannot be included in analytics.';
                        } elseif (($field['option_source'] ?? 'static') !== 'static' || ! empty($field['allow_custom_value'])) {
                            $error = 'Dynamic participant lookups and custom response values cannot be published.';
                        } elseif ($publishing && $this->sensitive($field) && ! $approveSensitive) {
                            $error = 'An administrator must explicitly approve this sensitive breakdown.';
                        } elseif ($field['type'] !== 'number' && empty($field['options'])) {
                            $error = 'This question has no fixed answer options.';
                        }
                        if ($error !== null) {
                            throw ValidationException::withMessages(["configuration.charts.$index.rows.$rowIndex.question" => $error]);
                        }
                    }
                }

                continue;
            }

            $field = $questions[$chart['question'] ?? ''] ?? null;
            $error = null;
            if (! $field) {
                $error = 'Select a valid structured question from this form. Free-text responses cannot be published.';
            } elseif ($this->identifying($field)) {
                $error = 'Identifying and credential fields cannot be included in analytics.';
            } elseif (! in_array($chart['aggregation'], ['count', 'percentage'], true) && $field['type'] !== 'number') {
                $error = 'Statistical calculations require a numeric question.';
            } elseif (($field['option_source'] ?? 'static') !== 'static' || ! empty($field['allow_custom_value'])) {
                $error = 'Dynamic participant lookups and custom response values cannot be published.';
            } elseif ($publishing && $this->sensitive($field) && ! $approveSensitive) {
                $error = 'An administrator must explicitly approve this sensitive breakdown.';
            } elseif ($field['type'] === 'number' && in_array($chart['aggregation'], ['count', 'percentage'], true)) {
                $error = 'Numeric questions require a statistical calculation, not individual-value categories.';
            } elseif ($field['type'] !== 'number' && empty($field['options'])) {
                $error = 'This question has no fixed answer options.';
            }
            if ($error !== null) {
                throw ValidationException::withMessages(["configuration.charts.$index.question" => $error]);
            }
        }
    }

    /** @param array<string, mixed> $configuration
     * @return array<string, string>
     */
    public function mappings(Feedback $feedback, array $configuration): array
    {
        $questions = $this->questions($feedback);
        $mappings = [];
        foreach ($configuration['charts'] as $chart) {
            if (($chart['kind'] ?? 'standard') === 'custom') {
                foreach ($chart['rows'] ?? [] as $row) {
                    if (($row['source'] ?? 'question') === 'question' && ! empty($row['question'])) {
                        $qId = $row['question'];
                        $mappings[$qId] = hash('sha256', json_encode($questions[$qId] ?? null));
                    }
                }
            } elseif (! empty($chart['question'])) {
                $mappings[$chart['question']] = hash('sha256', json_encode($questions[$chart['question']] ?? null));
            }
        }

        return $mappings;
    }

    public function available(Feedback $feedback, ?FeedbackAnalyticsReport $report): bool
    {
        if (! $report || $report->trashed() || $report->status !== 'published' || $report->visibility !== 'public'
            || (int) $report->feedback_id !== (int) $feedback->id || ! $report->published_at || ! $report->published_configuration) {
            return false;
        }
        $snapshot = $report->published_configuration;

        return ($snapshot['mappings'] ?? null) === $this->mappings($feedback, $snapshot['report']);
    }

    /** @return array{has_published_report: bool, public_url: ?string} */
    public function publication(Feedback $feedback): array
    {
        $available = $feedback->event !== null && $this->available($feedback, $feedback->publishedAnalyticsReport);

        return [
            'has_published_report' => $available,
            'public_url' => $available ? route('forms.analytics.public', ['api_key' => $feedback->event->api_key], false) : null,
        ];
    }

    /** @param array<string, mixed> $field
     * @return array<string, float>
     */
    private function optionScoreMap(array $field): array
    {
        $options = array_values($field['options'] ?? []);
        $count = count($options);
        if ($count === 0) {
            return [];
        }

        $firstLabel = strtolower(trim((string) ($options[0]['label'] ?? '')));
        $lastLabel = strtolower(trim((string) ($options[$count - 1]['label'] ?? '')));
        $descending = (bool) preg_match('/excellent|very\s+good|outstanding|superior|best|strongly\s+agree|always|very\s+clear|very\s+satisfied|high/i', $firstLabel)
            && ! preg_match('/strongly\s+agree|excellent|outstanding|best/i', $lastLabel);

        $map = [];
        foreach ($options as $index => $option) {
            $val = (string) ($option['value'] ?? '');
            $label = trim((string) ($option['label'] ?? ''));
            if (is_numeric($label)) {
                $map[$val] = (float) $label;
            } elseif (is_numeric($val)) {
                $map[$val] = (float) $val;
            } elseif (preg_match('/^option_(\d+)$/i', $val, $m) && ! $descending) {
                $map[$val] = (float) $m[1];
            } else {
                $map[$val] = (float) ($descending ? ($count - $index) : ($index + 1));
            }
        }

        return $map;
    }

    /** @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    public function aggregate(Feedback $feedback, array $configuration, bool $public = false): array
    {
        $query = $feedback->submissions();
        if (! empty($configuration['period_start'])) {
            $query->where('created_at', '>=', $configuration['period_start'].' 00:00:00');
        }
        if (! empty($configuration['period_end'])) {
            $query->where('created_at', '<=', $configuration['period_end'].' 23:59:59');
        }
        $questions = $this->questions($feedback);
        $scoreMaps = [];
        foreach ($questions as $qId => $qField) {
            if ($qField['type'] !== 'number') {
                $scoreMaps[$qId] = $this->optionScoreMap($qField);
            }
        }

        $states = [];
        $questionStats = [];
        foreach ($configuration['charts'] as $chart) {
            if (($chart['kind'] ?? 'standard') === 'custom') {
                foreach ($chart['rows'] ?? [] as $row) {
                    if (($row['source'] ?? 'question') === 'question' && ! empty($row['question']) && isset($questions[$row['question']])) {
                        $questionStats[$row['question']] ??= ['values' => [], 'answered' => 0];
                    }
                }
            } else {
                $states[$chart['id']] = ['values' => [], 'counts' => [], 'answered' => 0];
            }
        }

        $count = 0;
        foreach ($query->select(['id', 'data'])->lazyById(500) as $submission) {
            $count++;
            foreach ($configuration['charts'] as $chart) {
                if (($chart['kind'] ?? 'standard') === 'custom') {
                    continue;
                }
                $field = $questions[$chart['question']];
                $value = $submission->data[$chart['question']] ?? null;
                $state = &$states[$chart['id']];
                if ($field['type'] === 'number') {
                    if (is_numeric($value) && is_finite((float) $value)) {
                        $state['values'][] = (float) $value;
                        $state['answered']++;
                    }
                } else {
                    $allowed = array_map(fn (array $option): string => (string) $option['value'], $field['options']);
                    $values = is_array($value) ? $value : [$value];
                    $values = array_unique(array_filter($values, fn (mixed $item): bool => is_scalar($item) && in_array((string) $item, $allowed, true)));
                    if ($values !== []) {
                        $state['answered']++;
                    }
                    foreach ($values as $item) {
                        $state['counts'][(string) $item] = ($state['counts'][(string) $item] ?? 0) + 1;
                    }
                }
                unset($state);
            }

            foreach ($questionStats as $qId => &$qState) {
                $field = $questions[$qId];
                $value = $submission->data[$qId] ?? null;
                if ($field['type'] === 'number') {
                    if (is_numeric($value) && is_finite((float) $value)) {
                        $qState['values'][] = (float) $value;
                        $qState['answered']++;
                    }
                } else {
                    $scoreMap = $scoreMaps[$qId] ?? [];
                    $values = is_array($value) ? $value : [$value];
                    $values = array_unique(array_filter($values, fn (mixed $item): bool => is_scalar($item) && array_key_exists((string) $item, $scoreMap)));
                    if ($values !== []) {
                        $qState['answered']++;
                        $rowScore = 0.0;
                        foreach ($values as $item) {
                            $rowScore += $scoreMap[(string) $item];
                        }
                        $qState['values'][] = $rowScore;
                    }
                }
            }
            unset($qState);
        }

        $charts = [];
        foreach ($configuration['charts'] as $chart) {
            if (($chart['kind'] ?? 'standard') === 'custom') {
                $customRows = $chart['rows'] ?? [];
                $hasQuestionRows = false;
                $maxAnswered = 0;
                $suppressed = false;

                foreach ($customRows as $row) {
                    if (($row['source'] ?? 'question') === 'question' && ! empty($row['question'])) {
                        $hasQuestionRows = true;
                        $qAns = $questionStats[$row['question']]['answered'] ?? 0;
                        if ($qAns > $maxAnswered) {
                            $maxAnswered = $qAns;
                        }
                        if ($public && $qAns < 5) {
                            $suppressed = true;
                        }
                    }
                }

                $rows = [];
                if (! $suppressed) {
                    foreach ($customRows as $row) {
                        $label = (string) ($row['label'] ?? 'Row');
                        if (($row['source'] ?? 'question') === 'manual') {
                            $rows[] = [
                                'label' => $label,
                                'value' => round((float) ($row['value'] ?? 0), 2),
                            ];
                        } else {
                            $qId = $row['question'] ?? '';
                            $qState = $questionStats[$qId] ?? ['values' => [], 'answered' => 0];
                            $op = strtolower((string) ($row['operation'] ?? 'average'));
                            $vals = $qState['values'];
                            $n = count($vals);
                            if ($n === 0) {
                                $computed = 0;
                            } elseif ($op === 'sum') {
                                $computed = round(array_sum($vals), 2);
                            } elseif ($op === 'count') {
                                $computed = $qState['answered'];
                            } else {
                                $computed = round(array_sum($vals) / $n, 2);
                            }
                            $rows[] = [
                                'label' => $label,
                                'value' => $computed,
                            ];
                        }
                    }
                }

                $charts[$chart['id']] = [
                    'rows' => $rows,
                    'suppressed' => $suppressed,
                    'answered' => $suppressed ? null : ($hasQuestionRows ? $maxAnswered : $count),
                ];

                continue;
            }

            $field = $questions[$chart['question']];
            $state = $states[$chart['id']];
            $suppressed = $public && ($state['answered'] < 5 || collect($state['counts'])->contains(fn (int $value): bool => $value > 0 && $value < 5));
            $rows = [];
            if (! $suppressed && $state['answered'] > 0) {
                if ($field['type'] === 'number') {
                    $values = $state['values'];
                    sort($values, SORT_NUMERIC);
                    $n = count($values);
                    $mean = array_sum($values) / $n;
                    $result = match ($chart['aggregation']) {
                        'sum' => array_sum($values),
                        'median' => ($values[(int) floor(($n - 1) / 2)] + $values[(int) floor($n / 2)]) / 2,
                        'min' => min($values),
                        'max' => max($values),
                        'stddev' => sqrt(array_sum(array_map(fn (float $value): float => ($value - $mean) ** 2, $values)) / $n),
                        default => $mean,
                    };
                    $rows[] = ['label' => $chart['title'], 'value' => round($result, 2)];
                } else {
                    foreach ($field['options'] as $option) {
                        $value = $state['counts'][(string) $option['value']] ?? 0;
                        $rows[] = ['label' => (string) $option['label'], 'value' => $chart['aggregation'] === 'percentage' ? round($value / $state['answered'] * 100, 2) : $value];
                    }
                }
            }
            $charts[$chart['id']] = ['rows' => $rows, 'suppressed' => $suppressed, 'answered' => $suppressed ? null : $state['answered']];
        }

        return ['submission_count' => $configuration['show_submission_count'] && (! $public || $count >= 5) ? $count : null, 'charts' => $charts, 'refreshed_at' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    public function publicData(Feedback $feedback, FeedbackAnalyticsReport $report): array
    {
        $configuration = $report->published_configuration['report'];
        $key = 'feedback-analytics:'.$report->id.':'.hash('sha256', json_encode($report->published_configuration));

        return Cache::remember($key, $configuration['refresh_seconds'], fn (): array => $this->aggregate($feedback, $configuration, true));
    }
}
