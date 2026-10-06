<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFbFunctionRequest extends FormRequest
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
            'function' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:1000'],
            'event_ids' => ['nullable', 'array'],
            'event_ids.*' => ['integer', 'exists:fb_events,id'],
            'event_id' => ['nullable', 'integer', 'exists:fb_events,id'],
        ];
    }
}
