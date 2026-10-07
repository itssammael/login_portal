<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportFeedbackFormRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:10240', // 10MB max
                'mimes:docx,xlsx,xls,csv,pdf,png,jpg,jpeg,webp,txt',
            ],
            'event_id' => [
                'nullable',
                'integer',
                'exists:fb_events,id',
            ],
        ];
    }

    /**
     * Get custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please choose or drop a file to import.',
            'file.max' => 'The file is too large to analyze. Maximum supported file size is 10MB.',
            'file.mimes' => 'This file type is not supported. Please upload a Word (.docx), Excel (.xlsx/.csv), PDF, or image file.',
        ];
    }
}
