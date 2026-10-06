<?php

namespace App\Services;

use App\Models\FbEvent;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FeedbackSchemaValidator
{
    /**
     * The supported field types.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_TYPES = [
        'text',
        'radio',
        'select',
        'checkbox',
    ];

    /**
     * Types that require non-empty choice options when static.
     *
     * @var array<int, string>
     */
    public const TYPES_REQUIRING_OPTIONS = [
        'radio',
        'select',
        'checkbox',
    ];

    /**
     * Validate, normalize, and sort the feedback form schema.
     *
     * @throws ValidationException
     */
    public function validateSchema(mixed $schema): array
    {
        if (is_string($schema)) {
            $decoded = json_decode($schema, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                throw ValidationException::withMessages([
                    'schema' => 'The schema must be a valid JSON object.',
                ]);
            }
            $schema = $decoded;
        }

        if (! is_array($schema) || ! isset($schema['fields']) || ! is_array($schema['fields'])) {
            throw ValidationException::withMessages([
                'schema' => 'The schema must contain a "fields" array.',
            ]);
        }

        if (empty($schema['fields'])) {
            throw ValidationException::withMessages([
                'schema' => 'The schema must contain at least one field.',
            ]);
        }

        $normalizedFields = [];
        $usedIds = [];

        foreach ($schema['fields'] as $index => $field) {
            if (! is_array($field)) {
                throw ValidationException::withMessages([
                    "fields.{$index}" => "Field at index {$index} must be an object.",
                ]);
            }

            $particular = trim((string) ($field['particular'] ?? ''));
            if ($particular === '') {
                throw ValidationException::withMessages([
                    "fields.{$index}.particular" => 'Field at position #'.($index + 1)." requires a 'particular' (question label).",
                ]);
            }

            // Generate or normalize snake_case id
            $rawId = trim((string) ($field['id'] ?? ''));
            $id = $rawId !== '' ? Str::snake($rawId) : Str::snake($particular);
            $id = preg_replace('/[^a-z0-9_]/', '', strtolower($id));

            if ($id === '') {
                $id = 'field_'.($index + 1);
            }

            if (in_array($id, $usedIds, true)) {
                throw ValidationException::withMessages([
                    "fields.{$index}.id" => "Field identifier '{$id}' is duplicated. Every field must have a unique identifier.",
                ]);
            }
            $usedIds[] = $id;

            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            if (! in_array($type, self::SUPPORTED_TYPES, true)) {
                throw ValidationException::withMessages([
                    "fields.{$index}.type" => "Field '{$particular}' has unsupported type '{$type}'. Must be one of: ".implode(', ', self::SUPPORTED_TYPES),
                ]);
            }

            $weight = isset($field['weight']) ? (int) $field['weight'] : ($index + 1);
            $optionSource = trim((string) ($field['option_source'] ?? 'static'));

            // Normalize options
            $options = [];
            $isFunctionSourced = ($type === 'select' && $optionSource === 'fb_functions');

            if ($isFunctionSourced) {
                // Function-sourced dropdown: options are resolved dynamically from event functions
                $rawFunctionIds = $field['function_ids'] ?? [];
                $functionIds = [];
                if (is_array($rawFunctionIds)) {
                    $functionIds = array_values(array_unique(array_filter(array_map('intval', $rawFunctionIds))));
                }

                $normalizedField = [
                    'id' => $id,
                    'particular' => $particular,
                    'type' => $type,
                    'weight' => $weight,
                    'option_source' => 'fb_functions',
                    'function_ids' => $functionIds,
                    'options' => [],
                ];
            } else {
                if (in_array($type, self::TYPES_REQUIRING_OPTIONS, true)) {
                    $rawOptions = $field['options'] ?? [];
                    if (! is_array($rawOptions) || empty($rawOptions)) {
                        throw ValidationException::withMessages([
                            "fields.{$index}.options" => "Field '{$particular}' ({$type}) requires at least one option.",
                        ]);
                    }

                    foreach ($rawOptions as $optIdx => $opt) {
                        if (is_array($opt)) {
                            $optVal = trim((string) ($opt['value'] ?? ''));
                            $optLabel = trim((string) ($opt['label'] ?? $optVal));
                            if ($optVal === '') {
                                $optVal = Str::slug($optLabel, '_') ?: 'opt_'.($optIdx + 1);
                            }
                        } else {
                            $optLabel = trim((string) $opt);
                            $optVal = Str::slug($optLabel, '_') ?: 'opt_'.($optIdx + 1);
                        }

                        if ($optLabel === '') {
                            continue;
                        }

                        $options[] = [
                            'value' => $optVal,
                            'label' => $optLabel,
                        ];
                    }

                    if (empty($options)) {
                        throw ValidationException::withMessages([
                            "fields.{$index}.options" => "Field '{$particular}' ({$type}) requires at least one valid option with a display label.",
                        ]);
                    }
                }

                $normalizedField = [
                    'id' => $id,
                    'particular' => $particular,
                    'type' => $type,
                    'weight' => $weight,
                    'options' => $options,
                ];
            }

            $normalizedFields[] = $normalizedField;
        }

        // Sort fields by weight ascending
        usort($normalizedFields, fn ($a, $b) => $a['weight'] <=> $b['weight']);

        return [
            'fields' => array_values($normalizedFields),
        ];
    }

    /**
     * Dynamically populate event-specific function options for function-sourced fields.
     */
    public function resolveSchemaForEvent(array $schema, ?FbEvent $event = null): array
    {
        if (! isset($schema['fields']) || ! is_array($schema['fields'])) {
            return $schema;
        }

        $fields = $schema['fields'];
        foreach ($fields as &$field) {
            if (($field['type'] ?? '') === 'select' && ($field['option_source'] ?? '') === 'fb_functions') {
                if ($event) {
                    $query = $event->functions()->orderBy('function');
                    if (! empty($field['function_ids']) && is_array($field['function_ids'])) {
                        $query->whereIn('fb_functions.id', $field['function_ids']);
                    }
                    $functions = $query->get();

                    $field['options'] = $functions->map(function ($fn) {
                        $label = $fn->function;
                        if (! empty($fn->details)) {
                            $label .= " ({$fn->details})";
                        }

                        return [
                            'value' => (string) $fn->id,
                            'label' => $label,
                        ];
                    })->values()->all();
                } else {
                    $field['options'] = [];
                }
            }
        }

        return ['fields' => $fields];
    }

    /**
     * Validate submission answers against a validated feedback schema.
     *
     * @throws ValidationException
     */
    public function validateSubmissionData(array $schema, mixed $data, ?FbEvent $event = null): array
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $data = $decoded;
            }
        }

        if (! is_array($data)) {
            throw ValidationException::withMessages([
                'data' => 'Submission data must be a valid key-value object.',
            ]);
        }

        // Dynamically resolve options if schema has function-sourced fields
        $resolvedSchema = $this->resolveSchemaForEvent($schema, $event);
        $fields = $resolvedSchema['fields'] ?? [];
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[$field['id']] = $field;
        }

        $errors = [];

        // Check for unknown fields
        foreach ($data as $key => $val) {
            if (! isset($fieldMap[$key])) {
                $errors["data.{$key}"] = "Unknown field '{$key}' is not part of this feedback form.";
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $sanitized = [];

        foreach ($fields as $field) {
            $id = $field['id'];
            $particular = $field['particular'];
            $type = $field['type'];
            $options = $field['options'] ?? [];

            $allowedValues = array_column($options, 'value');

            if (! array_key_exists($id, $data)) {
                $sanitized[$id] = null;

                continue;
            }

            $value = $data[$id];

            if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
                $sanitized[$id] = null;

                continue;
            }

            switch ($type) {
                case 'text':
                    if (! is_string($value) && ! is_numeric($value)) {
                        $errors["data.{$id}"] = "The '{$particular}' field must be text.";
                    } else {
                        $sanitized[$id] = (string) $value;
                    }
                    break;

                case 'radio':
                case 'select':
                    if (! is_scalar($value)) {
                        $errors["data.{$id}"] = "The selected option for '{$particular}' is invalid.";
                    } else {
                        $strVal = (string) $value;
                        if (! in_array($strVal, $allowedValues, true)) {
                            $errors["data.{$id}"] = "The selected option for '{$particular}' is invalid.";
                        } else {
                            $sanitized[$id] = $strVal;
                        }
                    }
                    break;

                case 'checkbox':
                    if (! is_array($value)) {
                        $errors["data.{$id}"] = "The '{$particular}' field must be an array of selected options.";
                    } else {
                        $hasNonScalar = false;
                        foreach ($value as $item) {
                            if (! is_scalar($item)) {
                                $hasNonScalar = true;
                                break;
                            }
                        }

                        if ($hasNonScalar) {
                            $errors["data.{$id}"] = "The selected options for '{$particular}' contain invalid values.";
                        } else {
                            $invalidVals = array_diff(array_map('strval', $value), $allowedValues);
                            if (! empty($invalidVals)) {
                                $errors["data.{$id}"] = "The selected options for '{$particular}' contain invalid values.";
                            } else {
                                $sanitized[$id] = array_values(array_map('strval', $value));
                            }
                        }
                    }
                    break;
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $sanitized;
    }
}
