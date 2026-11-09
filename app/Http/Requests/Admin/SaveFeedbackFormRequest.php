<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveFeedbackFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'integer', 'exists:fb_events,id'],
            'status' => ['nullable', 'string', 'in:published,draft'],
            'schema' => ['required', 'array'],
            'schema.title' => ['nullable', 'string', 'max:65535'],
            'schema.description' => ['nullable', 'string', 'max:65535'],
            'schema.pagination' => ['nullable', 'array'],
            'schema.pagination.enabled' => ['nullable', 'boolean'],
            'schema.pagination.progress_bar' => ['nullable', 'boolean'],
            'schema.pagination.show_section_numbers' => ['nullable', 'boolean'],
            'schema.fields' => ['required', 'array', 'list', 'min:1'],
            'schema.fields.*.id' => ['required', 'string', 'max:100'],
            'schema.fields.*.particular' => ['required', 'string', 'max:5000'],
            'schema.fields.*.type' => ['required', 'string', 'in:text,textarea,number,date,radio,select,checkbox,photo,section,text_only'],
            'schema.fields.*.weight' => ['required', 'integer', 'min:0'],
            'schema.fields.*.required' => ['nullable', 'boolean'],
            'schema.fields.*.multiple' => ['nullable', 'boolean'],
            'schema.fields.*.allow_other' => ['nullable', 'boolean'],
            'schema.fields.*.allow_custom_value' => ['nullable', 'boolean'],
            'schema.fields.*.placeholder' => ['nullable', 'string', 'max:2000'],
            'schema.fields.*.description' => ['nullable', 'string', 'max:65535'],
            'schema.fields.*.description_type' => ['nullable', 'string', 'in:text,textarea'],
            'schema.fields.*.min' => ['nullable', 'numeric'],
            'schema.fields.*.max' => ['nullable', 'numeric'],
            'schema.fields.*.option_source' => ['nullable', 'string', 'in:static,fb_functions,agencies,designations'],
            'schema.fields.*.function_ids' => ['nullable', 'array'],
            'schema.fields.*.function_ids.*' => ['integer'],
            'schema.fields.*.section_flow' => ['nullable', 'string', 'max:350'],
            'schema.fields.*.conditions' => ['nullable', 'array'],
            'schema.fields.*.options' => ['nullable', 'array'],
            'schema.fields.*.options.*.value' => ['nullable', 'string'],
            'schema.fields.*.options.*.label' => ['nullable', 'string'],
            'schema.fields.*.options.*.is_other' => ['nullable', 'boolean'],
            'schema.fields.*.options.*.goto_section' => ['nullable', 'string', 'max:350'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_id.required' => 'Please select a target feedback event.',
            'event_id.exists' => 'The selected feedback event does not exist.',
            'status.in' => 'Form status must be either published or draft.',
            'schema.required' => 'Questionnaire structure is required.',
            'schema.title.max' => 'Form title may not exceed 2000 characters.',
            'schema.description.max' => 'Form description may not exceed 65535 characters.',
            'schema.fields.required' => 'The questionnaire must contain at least one question or section.',
            'schema.fields.min' => 'The questionnaire must contain at least one question or section.',
            'schema.fields.list' => 'The questions list must be a sequential array.',
            'schema.fields.*.id.required' => 'Question #:position identifier is required.',
            'schema.fields.*.id.max' => 'Question #:position identifier may not exceed 100 characters.',
            'schema.fields.*.particular.required' => 'Question #:position prompt/title is required.',
            'schema.fields.*.particular.max' => 'Question #:position prompt/title may not exceed 5000 characters.',
            'schema.fields.*.type.required' => 'Question #:position type is required.',
            'schema.fields.*.type.in' => 'Question #:position has an invalid question type.',
            'schema.fields.*.weight.required' => 'Question #:position order/weight is required.',
            'schema.fields.*.weight.integer' => 'Question #:position order/weight must be a whole number.',
            'schema.fields.*.weight.min' => 'Question #:position order/weight must be at least 0.',
            'schema.fields.*.placeholder.max' => 'Question #:position placeholder may not exceed 2000 characters.',
            'schema.fields.*.description.max' => 'Question #:position description may not exceed 65535 characters.',
            'schema.fields.*.description_type.in' => 'Question #:position description type must be text or textarea.',
            'schema.fields.*.option_source.in' => 'Question #:position has an invalid option source.',
            'schema.fields.*.section_flow.max' => 'Question #:position section destination may not exceed 350 characters.',
            'schema.fields.*.options.*.goto_section.max' => 'Question #:position option destination may not exceed 350 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'event_id' => 'target feedback event',
            'schema.title' => 'form title',
            'schema.description' => 'form description',
            'schema.fields' => 'form questions',
            'schema.fields.*.particular' => 'question prompt',
            'schema.fields.*.id' => 'question ID',
            'schema.fields.*.type' => 'question type',
            'schema.fields.*.weight' => 'question order',
            'schema.fields.*.options' => 'question options',
        ];
    }

    /**
     * Configure the validator instance with photo-question structural checks.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $fields = $this->input('schema.fields');
            if (!is_array($fields)) {
                return;
            }

            foreach ($fields as $index => $field) {
                if (!is_array($field)) {
                    continue;
                }

                $position = is_numeric($index) ? ((int) $index + 1) : 1;
                $particular = trim(strip_tags((string) ($field['particular'] ?? '')));

                if (isset($field['particular']) && $particular === '') {
                    $validator->errors()->add(
                        "schema.fields.{$index}.particular",
                        "Question #{$position} prompt/title is required."
                    );
                }

                if (($field['type'] ?? null) !== 'photo') {
                    continue;
                }

                if (!empty($field['options'])) {
                    $validator->errors()->add(
                        "schema.fields.{$index}.options",
                        "Question #{$position}: Photo upload questions cannot define choice options."
                    );
                }

                $optionSource = $field['option_source'] ?? 'static';
                if ($optionSource !== null && $optionSource !== '' && $optionSource !== 'static') {
                    $validator->errors()->add(
                        "schema.fields.{$index}.option_source",
                        "Question #{$position}: Photo upload questions cannot use a dynamic option source."
                    );
                }

                if (!empty($field['allow_other']) || !empty($field['allow_custom_value'])) {
                    $validator->errors()->add(
                        "schema.fields.{$index}.allow_other",
                        "Question #{$position}: Photo upload questions do not support custom/other option inputs."
                    );
                }

                if (!empty($field['multiple'])) {
                    $validator->errors()->add(
                        "schema.fields.{$index}.multiple",
                        "Question #{$position}: Photo upload questions accept only one photo per answer."
                    );
                }

                if (($field['min'] ?? null) !== null || ($field['max'] ?? null) !== null) {
                    $validator->errors()->add(
                        "schema.fields.{$index}.min",
                        "Question #{$position}: Photo upload questions cannot define numeric scale bounds."
                    );
                }
            }
        });
    }
}
