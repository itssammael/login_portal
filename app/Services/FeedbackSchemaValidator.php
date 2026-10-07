<?php

namespace App\Services;

use App\Models\Agency;
use App\Models\Designation;
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
        'textarea',
        'number',
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
     * Supported dynamic option sources.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_OPTION_SOURCES = [
        'static',
        'fb_functions',
        'agencies',
        'designations',
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
            $required = (bool) ($field['required'] ?? false);
            $allowOther = (bool) ($field['allow_other'] ?? false);
            $allowCustomValue = (bool) ($field['allow_custom_value'] ?? false);
            $placeholder = isset($field['placeholder']) && is_string($field['placeholder']) ? trim($field['placeholder']) : null;
            $min = isset($field['min']) && is_numeric($field['min']) ? (float) $field['min'] : null;
            $max = isset($field['max']) && is_numeric($field['max']) ? (float) $field['max'] : null;

            $optionSource = trim((string) ($field['option_source'] ?? 'static'));
            if (! in_array($optionSource, self::SUPPORTED_OPTION_SOURCES, true)) {
                $optionSource = 'static';
            }

            // Normalize options
            $options = [];
            $isDynamicSource = in_array($optionSource, ['fb_functions', 'agencies', 'designations'], true);

            if ($isDynamicSource) {
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
                    'required' => $required,
                    'placeholder' => $placeholder,
                    'min' => $min,
                    'max' => $max,
                    'allow_other' => $allowOther,
                    'allow_custom_value' => $allowCustomValue,
                    'option_source' => $optionSource,
                    'function_ids' => $functionIds,
                    'options' => [],
                ];
            } else {
                if (in_array($type, self::TYPES_REQUIRING_OPTIONS, true) && ! $allowCustomValue) {
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
                            $isOther = (bool) ($opt['is_other'] ?? ($optVal === 'other' || strtolower($optLabel) === 'other' || strtolower($optLabel) === 'others'));
                            if ($optVal === '') {
                                $optVal = Str::slug($optLabel, '_') ?: 'opt_'.($optIdx + 1);
                            }
                        } else {
                            $optLabel = trim((string) $opt);
                            $optVal = Str::slug($optLabel, '_') ?: 'opt_'.($optIdx + 1);
                            $isOther = ($optVal === 'other' || strtolower($optLabel) === 'other' || strtolower($optLabel) === 'others');
                        }

                        if ($optLabel === '') {
                            continue;
                        }

                        $options[] = [
                            'value' => $optVal,
                            'label' => $optLabel,
                            'is_other' => $isOther,
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
                    'required' => $required,
                    'placeholder' => $placeholder,
                    'min' => $min,
                    'max' => $max,
                    'allow_other' => $allowOther,
                    'allow_custom_value' => $allowCustomValue,
                    'option_source' => 'static',
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
     * Dynamically populate event-specific function/lookup options for schema fields.
     */
    public function resolveSchemaForEvent(array $schema, ?FbEvent $event = null): array
    {
        if (! isset($schema['fields']) || ! is_array($schema['fields'])) {
            return $schema;
        }

        $fields = $schema['fields'];
        foreach ($fields as &$field) {
            $type = $field['type'] ?? 'text';
            $source = $field['option_source'] ?? 'static';
            $allowOther = (bool) ($field['allow_other'] ?? false);

            if ($source === 'fb_functions') {
                if ($event) {
                    $query = $event->functions()->orderBy('function');
                    if (! empty($field['function_ids']) && is_array($field['function_ids'])) {
                        $query->whereIn('fb_functions.id', $field['function_ids']);
                    }
                    $functions = $query->get();

                    $options = $functions->map(function ($fn) {
                        $label = $fn->function;
                        if (! empty($fn->details)) {
                            $label .= " ({$fn->details})";
                        }
                        $isOther = in_array(strtolower(trim($fn->function)), ['other', 'others'], true);

                        return [
                            'value' => (string) $fn->id,
                            'label' => $label,
                            'is_other' => $isOther,
                        ];
                    })->values()->all();

                    // If allow_other is true and no function is named Other/Others, append standard other option
                    if ($allowOther && ! collect($options)->contains('is_other', true)) {
                        $options[] = [
                            'value' => 'other',
                            'label' => 'Others',
                            'is_other' => true,
                        ];
                    }

                    $field['options'] = $options;
                } else {
                    $field['options'] = [];
                }
            } elseif ($source === 'agencies') {
                $agencies = Agency::getCachedList();
                $field['options'] = array_map(fn ($name) => [
                    'value' => $name,
                    'label' => $name,
                    'is_other' => false,
                ], $agencies);
            } elseif ($source === 'designations') {
                $designations = Designation::getCachedList();
                $field['options'] = array_map(fn ($name) => [
                    'value' => $name,
                    'label' => $name,
                    'is_other' => false,
                ], $designations);
            } else {
                // Ensure options structure has is_other flag
                $rawOptions = $field['options'] ?? [];
                $normalizedOptions = [];
                foreach ($rawOptions as $opt) {
                    if (is_array($opt)) {
                        $optVal = (string) ($opt['value'] ?? '');
                        $optLabel = (string) ($opt['label'] ?? $optVal);
                        $isOther = (bool) ($opt['is_other'] ?? ($optVal === 'other' || in_array(strtolower($optLabel), ['other', 'others'], true)));
                        $normalizedOptions[] = [
                            'value' => $optVal,
                            'label' => $optLabel,
                            'is_other' => $isOther,
                        ];
                    }
                }
                $field['options'] = $normalizedOptions;
            }

            // Ensure baseline properties exist
            $field['required'] = (bool) ($field['required'] ?? false);
            $field['allow_other'] = $allowOther;
            $field['allow_custom_value'] = (bool) ($field['allow_custom_value'] ?? false);
            $field['placeholder'] = $field['placeholder'] ?? null;
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
                'answers' => 'Submission data must be a valid key-value object.',
            ]);
        }

        // Dynamically resolve options if schema has function-sourced or lookup fields
        $resolvedSchema = $this->resolveSchemaForEvent($schema, $event);
        $fields = $resolvedSchema['fields'] ?? [];
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[$field['id']] = $field;
        }

        $errors = [];

        // Check for unknown fields (ignoring valid _other suffix keys)
        foreach ($data as $key => $val) {
            if (str_ends_with($key, '_other')) {
                $baseKey = substr($key, 0, -6);
                if (isset($fieldMap[$baseKey])) {
                    continue;
                }
            }
            if (! isset($fieldMap[$key])) {
                $errors["answers.{$key}"] = "Unknown field '{$key}' is not part of this feedback form.";
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $sanitized = [];

        foreach ($fields as $field) {
            $id = $field['id'];
            $particular = $field['particular'];
            $type = $field['type'] ?? 'text';
            $isRequired = (bool) ($field['required'] ?? false);
            $allowOther = (bool) ($field['allow_other'] ?? false);
            $allowCustomValue = (bool) ($field['allow_custom_value'] ?? false);
            $options = $field['options'] ?? [];

            $allowedValues = array_column($options, 'value');

            $value = $data[$id] ?? null;

            // Check required constraint
            if ($isRequired && ($value === null || $value === '' || (is_array($value) && count($value) === 0))) {
                $errors["answers.{$id}"] = "The '{$particular}' field is required.";
                $errors["data.{$id}"] = "The '{$particular}' field is required.";
                $sanitized[$id] = null;

                continue;
            }

            if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
                $sanitized[$id] = null;

                continue;
            }

            switch ($type) {
                case 'text':
                case 'textarea':
                    if (! is_string($value) && ! is_numeric($value)) {
                        $errors["answers.{$id}"] = "The '{$particular}' field must be text.";
                        $errors["data.{$id}"] = "The '{$particular}' field must be text.";
                    } else {
                        $sanitized[$id] = (string) $value;
                    }
                    break;

                case 'number':
                    if (! is_numeric($value)) {
                        $errors["answers.{$id}"] = "The '{$particular}' field must be a number.";
                        $errors["data.{$id}"] = "The '{$particular}' field must be a number.";
                    } else {
                        $num = is_int($value) ? (int) $value : (float) $value;
                        if (isset($field['min']) && $num < $field['min']) {
                            $errors["answers.{$id}"] = "The '{$particular}' must be at least {$field['min']}.";
                            $errors["data.{$id}"] = "The '{$particular}' must be at least {$field['min']}.";
                        }
                        if (isset($field['max']) && $num > $field['max']) {
                            $errors["answers.{$id}"] = "The '{$particular}' may not be greater than {$field['max']}.";
                            $errors["data.{$id}"] = "The '{$particular}' may not be greater than {$field['max']}.";
                        }
                        $sanitized[$id] = $num;
                    }
                    break;

                case 'radio':
                case 'select':
                    if (! is_scalar($value)) {
                        $errors["answers.{$id}"] = "The selected option for '{$particular}' is invalid.";
                        $errors["data.{$id}"] = "The selected option for '{$particular}' is invalid.";
                    } else {
                        $strVal = (string) $value;
                        $matchedOpt = collect($options)->firstWhere('value', $strVal);
                        $isOtherSelected = $allowOther && (
                            ($matchedOpt['is_other'] ?? false) ||
                            ($strVal === 'other' && in_array('other', $allowedValues, true))
                        );

                        if ($isOtherSelected) {
                            $otherVal = $data[$id.'_other'] ?? $data['custom_'.$id] ?? null;
                            $sanitized[$id] = $strVal;
                            $sanitized[$id.'_other'] = $otherVal !== null ? trim((string) $otherVal) : null;

                            if ($isRequired && empty($sanitized[$id.'_other'])) {
                                $errors["answers.{$id}_other"] = "Please specify a value for '{$particular}'.";
                                $errors["data.{$id}_other"] = "Please specify a value for '{$particular}'.";
                            }
                        } elseif ($allowCustomValue || in_array($strVal, $allowedValues, true)) {
                            $sanitized[$id] = $strVal;
                        } else {
                            $errors["answers.{$id}"] = "The selected option for '{$particular}' is invalid.";
                            $errors["data.{$id}"] = "The selected option for '{$particular}' is invalid.";
                        }
                    }
                    break;

                case 'checkbox':
                    if (! is_array($value)) {
                        $errors["answers.{$id}"] = "The '{$particular}' field must be an array of selected options.";
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
                            $errors["answers.{$id}"] = "The selected options for '{$particular}' contain invalid values.";
                            $errors["data.{$id}"] = "The selected options for '{$particular}' contain invalid values.";
                        } else {
                            $strVals = array_values(array_map('strval', $value));
                            if (! $allowCustomValue) {
                                $invalidVals = array_diff($strVals, $allowedValues);
                                if (! empty($invalidVals)) {
                                    $errors["answers.{$id}"] = "The selected options for '{$particular}' contain invalid values.";
                                    $errors["data.{$id}"] = "The selected options for '{$particular}' contain invalid values.";
                                } else {
                                    $sanitized[$id] = $strVals;
                                }
                            } else {
                                $sanitized[$id] = $strVals;
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
