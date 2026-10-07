<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateEmbedSessionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
            'respondent_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'user_defaults' => ['nullable', 'array'],
        ];
    }

    /**
     * Get client ID from request (supports request body or X-Client-Id header).
     */
    public function getClientId(): string
    {
        return (string) $this->input('client_id', $this->header('X-Client-Id', ''));
    }

    /**
     * Get client secret from request (supports request body or X-Client-Secret header).
     */
    public function getClientSecret(): string
    {
        return (string) $this->input('client_secret', $this->header('X-Client-Secret', ''));
    }
}
