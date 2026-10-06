<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitFeedbackRequest extends FormRequest
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
            // Participant fields (can be nested under 'participant' or provided at root)
            'name' => ['nullable', 'string', 'max:255'],
            'participant.name' => ['nullable', 'string', 'max:255'],

            'function_id' => ['nullable', 'integer', 'exists:fb_functions,id'],
            'participant.function_id' => ['nullable', 'integer', 'exists:fb_functions,id'],

            'custom_function' => ['nullable', 'string', 'max:255'],
            'participant.custom_function' => ['nullable', 'string', 'max:255'],

            'agency' => ['nullable', 'string', 'max:255'],
            'participant.agency' => ['nullable', 'string', 'max:255'],

            'designation' => ['nullable', 'string', 'max:255'],
            'participant.designation' => ['nullable', 'string', 'max:255'],

            'years_in_designation' => ['nullable', 'integer', 'min:0', 'max:100'],
            'participant.years_in_designation' => ['nullable', 'integer', 'min:0', 'max:100'],

            'location' => ['nullable', 'string', 'max:255'],
            'participant.location' => ['nullable', 'string', 'max:255'],

            'no_of_exercises' => ['nullable', 'integer', 'min:0', 'max:255'],
            'participant.no_of_exercises' => ['nullable', 'integer', 'min:0', 'max:255'],

            // Feedback answers payload
            'answers' => ['nullable', 'array'],
            'data' => ['nullable', 'array'],
        ];
    }

    /**
     * Extract normalized participant attributes from request.
     *
     * @return array<string, mixed>
     */
    public function getParticipantData(): array
    {
        $nested = $this->input('participant', []);

        return [
            'name' => $this->input('name', $nested['name'] ?? null),
            'function_id' => $this->input('function_id', $nested['function_id'] ?? null),
            'custom_function' => $this->input('custom_function', $nested['custom_function'] ?? null),
            'agency' => $this->input('agency', $nested['agency'] ?? null),
            'designation' => $this->input('designation', $nested['designation'] ?? null),
            'years_in_designation' => (int) $this->input('years_in_designation', $nested['years_in_designation'] ?? 0),
            'location' => $this->input('location', $nested['location'] ?? null),
            'no_of_exercises' => (int) $this->input('no_of_exercises', $nested['no_of_exercises'] ?? 0),
        ];
    }

    /**
     * Extract feedback answers from request.
     *
     * @return array<string, mixed>
     */
    public function getAnswers(): array
    {
        $answers = $this->input('answers');
        if ($answers === null) {
            $answers = $this->input('data');
        }

        return is_array($answers) ? $answers : [];
    }
}
