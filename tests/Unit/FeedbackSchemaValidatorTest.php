<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\SaveFeedbackFormRequest;
use App\Services\FeedbackSchemaValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FeedbackSchemaValidatorTest extends TestCase
{
    public function test_section_description_type_normalizes_and_preserves_text_and_textarea(): void
    {
        $validator = new FeedbackSchemaValidator;

        $normalized = $validator->validateSchema([
            'fields' => [
                [
                    'id' => 'section_1',
                    'particular' => 'Section 1',
                    'type' => 'section',
                    'weight' => 1,
                    'description' => 'Short description',
                    'description_type' => 'text',
                ],
                [
                    'id' => 'section_2',
                    'particular' => 'Section 2',
                    'type' => 'section',
                    'weight' => 2,
                    'description' => "Line 1\nLine 2",
                    'description_type' => 'textarea',
                ],
                [
                    'id' => 'section_3',
                    'particular' => 'Section 3',
                    'type' => 'section',
                    'weight' => 3,
                    'description' => "Auto inferred\nmultiline paragraph",
                ],
            ],
        ]);

        $this->assertSame('text', $normalized['fields'][0]['description_type']);
        $this->assertSame('Short description', $normalized['fields'][0]['description']);

        $this->assertSame('textarea', $normalized['fields'][1]['description_type']);
        $this->assertSame("Line 1\nLine 2", $normalized['fields'][1]['description']);

        $this->assertSame('textarea', $normalized['fields'][2]['description_type']);
    }

    public function test_save_feedback_form_request_rules_allow_section_description_type(): void
    {
        $request = new SaveFeedbackFormRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('schema.fields.*.description_type', $rules);
        $this->assertSame(['nullable', 'string', 'in:text,textarea'], $rules['schema.fields.*.description_type']);
        $this->assertSame(['nullable', 'string', 'max:65535'], $rules['schema.fields.*.description']);
        $this->assertSame(['required', 'string', 'max:5000'], $rules['schema.fields.*.particular']);
    }

    public function test_text_only_field_is_supported_and_not_required(): void
    {
        $validator = new FeedbackSchemaValidator;

        $normalized = $validator->validateSchema([
            'fields' => [
                [
                    'id' => 'instructional_notes',
                    'particular' => 'General Guidelines',
                    'type' => 'text_only',
                    'weight' => 1,
                    'required' => true, // should be forced to false
                    'description' => 'Please read carefully before proceeding.',
                ],
                [
                    'id' => 'question_1',
                    'particular' => 'Question 1',
                    'type' => 'text',
                    'weight' => 2,
                    'required' => true,
                ],
            ],
        ]);

        $this->assertSame('text_only', $normalized['fields'][0]['type']);
        $this->assertFalse($normalized['fields'][0]['required']);
        $this->assertSame('textarea', $normalized['fields'][0]['description_type']);

        // Submission with only question_1 answered passes (text_only requires no answer)
        $submission = $validator->validateSubmissionData($normalized, [
            'question_1' => 'My answer',
        ]);

        $this->assertSame(['question_1' => 'My answer'], $submission);
    }

    public function test_schema_validator_preserves_rich_text_html_in_particular_and_description(): void
    {
        $validator = new FeedbackSchemaValidator;

        $tableHtml = '<table class="form-rich-table"><thead><tr><th>Header 1</th></tr></thead><tbody><tr><td>Data 1</td></tr></tbody></table>';
        $richParticular = '<span style="text-align: center"><b>Rate The Overall Experience</b></span>';
        $richDescription = "<p>Observe <b>Critical Tasks</b>:</p><ul><li>Task 1</li><li>Task 2</li></ul>{$tableHtml}";

        $normalized = $validator->validateSchema([
            'fields' => [
                [
                    'id' => '',
                    'particular' => $richParticular,
                    'type' => 'text',
                    'weight' => 1,
                    'required' => true,
                ],
                [
                    'id' => 'instruction_block',
                    'particular' => 'Instructions',
                    'type' => 'text_only',
                    'weight' => 2,
                    'description' => $richDescription,
                ],
            ],
        ]);

        // Id was derived by stripping html tags
        $this->assertSame('rate_the_overall_experience', $normalized['fields'][0]['id']);
        $this->assertSame($richParticular, $normalized['fields'][0]['particular']);
        $this->assertSame($richDescription, $normalized['fields'][1]['description']);

        // Test submission validation strips HTML in error messages
        try {
            $validator->validateSubmissionData($normalized, []);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('answers.rate_the_overall_experience', $errors);
            $this->assertSame(
                "The 'Rate The Overall Experience' field is required.",
                $errors['answers.rate_the_overall_experience'][0]
            );
        }
    }

    public function test_validate_schema_handles_string_keyed_fields_without_type_error(): void
    {
        $validator = new FeedbackSchemaValidator;

        // String keys instead of sequential integers
        try {
            $validator->validateSchema([
                'fields' => [
                    'question_one' => [
                        'id' => 'q1',
                        'particular' => 'Question One',
                        'type' => 'radio',
                        'options' => [], // triggers line 196
                    ],
                ],
            ]);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('schema.fields.question_one.options', $errors);
            $this->assertSame(
                "Question #1 ('Question One'): Multiple choice and dropdown questions require at least one option.",
                $errors['schema.fields.question_one.options'][0]
            );
        }
    }
}
