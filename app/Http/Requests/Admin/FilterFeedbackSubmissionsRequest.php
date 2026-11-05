<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FilterFeedbackSubmissionsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->canAccessAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $requiresEvent = $this->isExportRequest() && $this->resolvedTab() === 'compiled';

        return [
            'event_id' => $requiresEvent
                ? ['required', 'integer', 'min:1']
                : ['nullable', 'integer', 'min:1'],
            'function_id' => ['nullable', 'integer', 'min:1'],
            'agency' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'tab' => ['nullable', 'string', 'max:50'],
            'fields' => ['nullable'],
        ];
    }

    /**
     * Validate that an explicit `fields` parameter on an export request yields at least one syntactically valid token.
     */
    public function withValidator(Validator $validator): void
    {
        if (! $this->isExportRequest() || ! $this->has('fields')) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            $tokens = $this->selectedFieldTokens();
            if ($tokens !== null && count($tokens) === 0) {
                $validator->errors()->add('fields', 'Please select at least one valid field to include in the export.');
            }
        });
    }

    /**
     * Get custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_id.required' => 'Please select an event before exporting compiled responses.',
        ];
    }

    /**
     * Determine whether this request targets the submissions export route.
     */
    public function isExportRequest(): bool
    {
        return $this->routeIs('admin.feedback.submissions.export');
    }

    /**
     * Resolve the active view tab using the same fallback rules as the submissions listing.
     */
    public function resolvedTab(): string
    {
        $tabParam = $this->query('tab', $this->input('tab'));

        if (in_array($tabParam, ['compiled', 'individual'], true)) {
            return $tabParam;
        }

        return $this->filled('event_id') ? 'compiled' : 'individual';
    }

    /**
     * Extract normalized filter parameters shared between listing and export queries.
     *
     * @return array{
     *     event_id: int|null,
     *     function_id: int|null,
     *     agency: string|null,
     *     designation: string|null,
     *     search: string|null,
     *     tab: string
     * }
     */
    public function normalizedFilters(): array
    {
        return [
            'event_id' => $this->filled('event_id') ? (int) $this->input('event_id') : null,
            'function_id' => $this->filled('function_id') ? (int) $this->input('function_id') : null,
            'agency' => $this->filled('agency') ? (string) $this->input('agency') : null,
            'designation' => $this->filled('designation') ? (string) $this->input('designation') : null,
            'search' => $this->filled('search') ? (string) $this->input('search') : null,
            'tab' => $this->resolvedTab(),
        ];
    }

    /**
     * Parse optional ordered export field tokens (`meta:{key}` or `q:{feedback_id}:{field_id}`).
     * Returns `null` when `fields` was omitted so exports default to including all available fields.
     *
     * @return array<int, string>|null
     */
    public function selectedFieldTokens(): ?array
    {
        if (! $this->has('fields')) {
            return null;
        }

        $raw = $this->input('fields');
        $candidates = [];

        if (is_string($raw)) {
            $candidates = explode(',', $raw);
        } elseif (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_string($entry)) {
                    foreach (explode(',', $entry) as $part) {
                        $candidates[] = $part;
                    }
                }
            }
        }

        $tokens = [];
        foreach ($candidates as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^(meta:[a-z_]+|q:\d+:[A-Za-z0-9_-]+)$/', $trimmed) === 1) {
                $tokens[] = $trimmed;
            }
        }

        return array_values(array_unique($tokens));
    }
}
