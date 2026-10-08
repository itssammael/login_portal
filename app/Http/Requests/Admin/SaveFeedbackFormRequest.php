<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'schema' => ['required', 'array'],
            'schema.title' => ['nullable', 'string', 'max:255'],
            'schema.description' => ['nullable', 'string', 'max:2000'],
            'schema.pagination' => ['nullable', 'array'],
            'schema.pagination.enabled' => ['nullable', 'boolean'],
            'schema.pagination.progress_bar' => ['nullable', 'boolean'],
            'schema.pagination.show_section_numbers' => ['nullable', 'boolean'],
            'schema.fields' => ['required', 'array', 'min:1'],
            'schema.fields.*.id' => ['required', 'string', 'max:100'],
            'schema.fields.*.particular' => ['required', 'string', 'max:255'],
            'schema.fields.*.type' => ['required', 'string', 'in:text,textarea,number,date,radio,select,checkbox,section'],
            'schema.fields.*.weight' => ['required', 'integer', 'min:0'],
            'schema.fields.*.required' => ['nullable', 'boolean'],
            'schema.fields.*.allow_other' => ['nullable', 'boolean'],
            'schema.fields.*.allow_custom_value' => ['nullable', 'boolean'],
            'schema.fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'schema.fields.*.description' => ['nullable', 'string', 'max:1000'],
            'schema.fields.*.min' => ['nullable', 'numeric'],
            'schema.fields.*.max' => ['nullable', 'numeric'],
            'schema.fields.*.option_source' => ['nullable', 'string', 'in:static,fb_functions,agencies,designations'],
            'schema.fields.*.function_ids' => ['nullable', 'array'],
            'schema.fields.*.function_ids.*' => ['integer'],
            'schema.fields.*.section_flow' => ['nullable', 'string', 'max:100'],
            'schema.fields.*.conditions' => ['nullable', 'array'],
            'schema.fields.*.options' => ['nullable', 'array'],
            'schema.fields.*.options.*.value' => ['nullable', 'string'],
            'schema.fields.*.options.*.label' => ['nullable', 'string'],
            'schema.fields.*.options.*.is_other' => ['nullable', 'boolean'],
            'schema.fields.*.options.*.goto_section' => ['nullable', 'string', 'max:100'],
        ];
    }
}
