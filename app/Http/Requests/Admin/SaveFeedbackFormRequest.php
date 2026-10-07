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
            'schema.fields' => ['required', 'array', 'min:1'],
            'schema.fields.*.id' => ['required', 'string', 'max:100'],
            'schema.fields.*.particular' => ['required', 'string', 'max:255'],
            'schema.fields.*.type' => ['required', 'string', 'in:text,textarea,number,radio,select,checkbox'],
            'schema.fields.*.weight' => ['required', 'integer', 'min:0'],
            'schema.fields.*.required' => ['nullable', 'boolean'],
            'schema.fields.*.allow_other' => ['nullable', 'boolean'],
            'schema.fields.*.allow_custom_value' => ['nullable', 'boolean'],
            'schema.fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'schema.fields.*.min' => ['nullable', 'numeric'],
            'schema.fields.*.max' => ['nullable', 'numeric'],
            'schema.fields.*.option_source' => ['nullable', 'string', 'in:static,fb_functions,agencies,designations'],
            'schema.fields.*.function_ids' => ['nullable', 'array'],
            'schema.fields.*.function_ids.*' => ['integer'],
            'schema.fields.*.options' => ['nullable', 'array'],
        ];
    }
}
