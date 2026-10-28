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
            // Event / Form identifiers (optional for API token auth, used by web feedback form)
            'event_id' => ['nullable', 'integer'],
            'feedback_id' => ['nullable', 'integer'],
            'form_id' => ['nullable', 'integer'],

            // Participant fields (can be nested under 'participant', provided at root, or inside 'answers'/'data')
            'name' => ['nullable', 'string', 'max:255'],
            'participant.name' => ['nullable', 'string', 'max:255'],
            'answers.name' => ['nullable', 'string', 'max:255'],
            'data.name' => ['nullable', 'string', 'max:255'],

            'function_id' => ['nullable', 'integer', 'exists:fb_functions,id'],
            'participant.function_id' => ['nullable', 'integer', 'exists:fb_functions,id'],
            'answers.function_id' => ['nullable', 'integer', 'exists:fb_functions,id'],
            'data.function_id' => ['nullable', 'integer', 'exists:fb_functions,id'],

            'custom_function' => ['nullable', 'string', 'max:255'],
            'participant.custom_function' => ['nullable', 'string', 'max:255'],
            'answers.custom_function' => ['nullable', 'string', 'max:255'],
            'answers.function_other' => ['nullable', 'string', 'max:255'],
            'data.custom_function' => ['nullable', 'string', 'max:255'],
            'data.function_other' => ['nullable', 'string', 'max:255'],

            'agency' => ['nullable', 'string', 'max:255'],
            'participant.agency' => ['nullable', 'string', 'max:255'],
            'answers.agency' => ['nullable', 'string', 'max:255'],
            'data.agency' => ['nullable', 'string', 'max:255'],

            'designation' => ['nullable', 'string', 'max:255'],
            'participant.designation' => ['nullable', 'string', 'max:255'],
            'answers.designation' => ['nullable', 'string', 'max:255'],
            'data.designation' => ['nullable', 'string', 'max:255'],

            'years_in_designation' => ['nullable', 'integer', 'min:0', 'max:100'],
            'participant.years_in_designation' => ['nullable', 'integer', 'min:0', 'max:100'],
            'answers.years_in_designation' => ['nullable', 'integer', 'min:0', 'max:100'],
            'data.years_in_designation' => ['nullable', 'integer', 'min:0', 'max:100'],

            'location' => ['nullable', 'string', 'max:255'],
            'participant.location' => ['nullable', 'string', 'max:255'],
            'answers.location' => ['nullable', 'string', 'max:255'],
            'data.location' => ['nullable', 'string', 'max:255'],

            'no_of_exercises' => ['nullable', 'integer', 'min:0', 'max:255'],
            'participant.no_of_exercises' => ['nullable', 'integer', 'min:0', 'max:255'],
            'answers.no_of_exercises' => ['nullable', 'integer', 'min:0', 'max:255'],
            'data.no_of_exercises' => ['nullable', 'integer', 'min:0', 'max:255'],

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
        $answers = $this->getAnswers();

        $rawName = $this->input('name', $nested['name'] ?? ($answers['name'] ?? null));
        $rawFunctionId = $this->input('function_id', $nested['function_id'] ?? ($answers['function_id'] ?? ($answers['function'] ?? null)));
        $rawCustomFunction = $this->input('custom_function', $nested['custom_function'] ?? ($answers['custom_function'] ?? ($answers['function_other'] ?? null)));
        $rawAgency = $this->input('agency', $nested['agency'] ?? ($answers['agency'] ?? null));
        $rawDesignation = $this->input('designation', $nested['designation'] ?? ($answers['designation'] ?? null));
        $rawYears = $this->input('years_in_designation', $nested['years_in_designation'] ?? ($answers['years_in_designation'] ?? 0));
        $rawLocation = $this->input('location', $nested['location'] ?? ($answers['location'] ?? null));
        $rawExercises = $this->input('no_of_exercises', $nested['no_of_exercises'] ?? ($answers['no_of_exercises'] ?? 0));

        $name = is_string($rawName) ? substr(trim($rawName), 0, 255) : null;
        if ($name === '') {
            $name = null;
        }

        $functionId = is_numeric($rawFunctionId) ? (int) $rawFunctionId : (is_string($rawFunctionId) ? substr(trim($rawFunctionId), 0, 100) : null);
        $customFunction = is_string($rawCustomFunction) ? substr(trim($rawCustomFunction), 0, 255) : null;
        $agency = is_string($rawAgency) ? substr(trim($rawAgency), 0, 255) : null;
        $designation = is_string($rawDesignation) ? substr(trim($rawDesignation), 0, 255) : null;
        $location = is_string($rawLocation) ? substr(trim($rawLocation), 0, 255) : null;
        $years = is_numeric($rawYears) ? max(0, min(100, (int) $rawYears)) : 0;
        $exercises = is_numeric($rawExercises) ? max(0, min(255, (int) $rawExercises)) : 0;

        return [
            'name' => $name,
            'function_id' => $functionId,
            'custom_function' => $customFunction,
            'agency' => $agency,
            'designation' => $designation,
            'years_in_designation' => $years,
            'location' => $location,
            'no_of_exercises' => $exercises,
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
