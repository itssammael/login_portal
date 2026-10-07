<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Designation;
use App\Models\FbEvent;
use App\Models\FbFunction;
use App\Models\FbParticipant;
use App\Models\FbSubmission;
use App\Models\Feedback;
use App\Models\User;
use App\Services\FeedbackSchemaValidator;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_non_admin_cannot_access_feedback_setup(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('admin.feedback.setup'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_feedback_setup_page(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Cybersecurity Summit 2026']);
        Agency::factory()->create(['name' => 'Department of Science and Technology']);
        Designation::factory()->create(['name' => 'Senior Developer']);

        $response = $this->actingAs($admin)->get(route('admin.feedback.setup'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Feedback/Setup')
            ->has('events')
            ->has('functions')
            ->has('agencies')
            ->has('designations')
            ->has('feedbackForms')
        );
    }

    public function test_admin_can_view_feedback_submissions_page(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();
        $feedback = Feedback::factory()->create(['event_id' => $event->id]);
        $participant = FbParticipant::factory()->create(['name' => 'Juan Dela Cruz']);
        FbSubmission::factory()->create([
            'feedback_id' => $feedback->id,
            'participant_id' => $participant->id,
            'data' => ['satisfaction' => 5],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.feedback.submissions'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Feedback/Submissions')
            ->has('submissions.data')
            ->has('filterEvents')
        );
    }

    public function test_admin_can_create_update_and_delete_event(): void
    {
        $admin = User::factory()->admin()->create();

        // Create
        $response = $this->actingAs($admin)->post(route('admin.feedback.events.store'), [
            'name' => 'AI Governance Workshop',
            'details' => 'Annual government AI policies seminar.',
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('fb_events', [
            'name' => 'AI Governance Workshop',
            'created_by' => $admin->id,
        ]);

        $event = FbEvent::where('name', 'AI Governance Workshop')->firstOrFail();
        $this->assertNotEmpty($event->api_key);

        $auditLog = AuditLog::where('action', 'created_fb_event')->latest()->first();
        $this->assertNotNull($auditLog);
        $this->assertArrayNotHasKey('api_key', $auditLog->details);
        $this->assertEquals('AI Governance Workshop', $auditLog->details['name']);

        // Update
        $updateResponse = $this->actingAs($admin)->put(route('admin.feedback.events.update', $event), [
            'name' => 'AI Governance Workshop 2026',
            'details' => 'Updated details.',
        ]);

        $updateResponse->assertSessionHas('success');
        $this->assertDatabaseHas('fb_events', [
            'id' => $event->id,
            'name' => 'AI Governance Workshop 2026',
        ]);

        // Soft Delete
        $deleteResponse = $this->actingAs($admin)->delete(route('admin.feedback.events.destroy', $event));
        $deleteResponse->assertSessionHas('success');
        $this->assertSoftDeleted('fb_events', ['id' => $event->id]);
    }

    public function test_admin_can_assign_multiple_functions_to_an_event_and_sync(): void
    {
        $admin = User::factory()->admin()->create();
        $funcA = FbFunction::factory()->create(['function' => 'Facilitator']);
        $funcB = FbFunction::factory()->create(['function' => 'Observer']);
        $funcC = FbFunction::factory()->create(['function' => 'Technical Lead']);

        // Create event with assigned functions A and B
        $response = $this->actingAs($admin)->post(route('admin.feedback.events.store'), [
            'name' => 'Disaster Preparedness Drill',
            'details' => 'Citywide simulation.',
            'function_ids' => [$funcA->id, $funcB->id],
        ]);

        $response->assertSessionHas('success');
        $event = FbEvent::where('name', 'Disaster Preparedness Drill')->firstOrFail();

        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcA->id,
        ]);
        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcB->id,
        ]);
        $this->assertDatabaseMissing('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcC->id,
        ]);

        // Update event: synchronize to functions B and C
        $updateResponse = $this->actingAs($admin)->put(route('admin.feedback.events.update', $event), [
            'name' => 'Disaster Preparedness Drill',
            'details' => 'Citywide simulation.',
            'function_ids' => [$funcB->id, $funcC->id],
        ]);

        $updateResponse->assertSessionHas('success');
        $this->assertDatabaseMissing('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcA->id,
        ]);
        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcB->id,
        ]);
        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $funcC->id,
        ]);

        // Ensure function A was not deleted from fb_functions table
        $this->assertDatabaseHas('fb_functions', ['id' => $funcA->id]);
    }

    public function test_admin_can_assign_one_function_to_multiple_events(): void
    {
        $admin = User::factory()->admin()->create();
        $event1 = FbEvent::factory()->create(['name' => 'Event One']);
        $event2 = FbEvent::factory()->create(['name' => 'Event Two']);

        // Create function assigned to both event1 and event2
        $response = $this->actingAs($admin)->post(route('admin.feedback.functions.store'), [
            'function' => 'Keynote Speaker',
            'details' => 'Invited domain expert',
            'event_ids' => [$event1->id, $event2->id],
        ]);

        $response->assertSessionHas('success');
        $function = FbFunction::where('function', 'Keynote Speaker')->firstOrFail();

        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event1->id,
            'function_id' => $function->id,
        ]);
        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event2->id,
            'function_id' => $function->id,
        ]);

        $this->assertCount(2, $function->events);
    }

    public function test_deleting_event_does_not_delete_shared_functions(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Event to be deleted']);
        $function = FbFunction::factory()->create(['function' => 'Shared Resource']);
        $event->functions()->attach($function->id);

        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
            'function_id' => $function->id,
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.feedback.events.destroy', $event));
        $response->assertSessionHas('success');

        $this->assertSoftDeleted('fb_events', ['id' => $event->id]);
        $this->assertDatabaseHas('fb_functions', ['id' => $function->id]);
    }

    public function test_admin_can_regenerate_event_api_key(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['created_by' => $admin->id]);
        $oldKey = $event->api_key;

        $response = $this->actingAs($admin)->post(route('admin.feedback.events.regenerate-key', $event));

        $response->assertSessionHas('success');
        $event->refresh();
        $this->assertNotEquals($oldKey, $event->api_key);
        $this->assertStringStartsWith('fb_', $event->api_key);
    }

    public function test_admin_can_create_and_delete_functions(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        // Create normal function
        $response = $this->actingAs($admin)->post(route('admin.feedback.functions.store'), [
            'event_ids' => [$event->id],
            'function' => 'Speaker / Resource Person',
            'details' => null,
        ]);
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('fb_functions', [
            'function' => 'Speaker / Resource Person',
        ]);
        $this->assertDatabaseHas('fb_event_function', [
            'event_id' => $event->id,
        ]);

        // Create 'Others' function with custom details
        $othersResponse = $this->actingAs($admin)->post(route('admin.feedback.functions.store'), [
            'event_id' => $event->id,
            'function' => 'Others',
            'details' => 'Volunteer Marshall',
        ]);
        $othersResponse->assertSessionHas('success');
        $this->assertDatabaseHas('fb_functions', [
            'function' => 'Others',
            'details' => 'Volunteer Marshall',
        ]);

        $function = FbFunction::where('function', 'Speaker / Resource Person')->firstOrFail();
        $deleteResponse = $this->actingAs($admin)->delete(route('admin.feedback.functions.destroy', $function));
        $deleteResponse->assertSessionHas('success');
        $this->assertDatabaseMissing('fb_functions', ['id' => $function->id]);
        $this->assertDatabaseMissing('fb_event_function', ['function_id' => $function->id]);
    }

    public function test_admin_can_manage_agencies_and_designations(): void
    {
        $admin = User::factory()->admin()->create();

        // Agency Store & Delete
        $this->actingAs($admin)->post(route('admin.feedback.agencies.store'), [
            'name' => 'Department of Budget and Management',
        ])->assertSessionHas('success');

        $agency = Agency::where('name', 'Department of Budget and Management')->firstOrFail();

        $this->actingAs($admin)->delete(route('admin.feedback.agencies.destroy', $agency))
            ->assertSessionHas('success');
        $this->assertSoftDeleted('agencies', ['id' => $agency->id]);

        // Storing deleted agency again restores it
        $this->actingAs($admin)->post(route('admin.feedback.agencies.store'), [
            'name' => 'Department of Budget and Management',
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('agencies', ['id' => $agency->id, 'deleted_at' => null]);

        // Designation Store & Delete
        $this->actingAs($admin)->post(route('admin.feedback.designations.store'), [
            'name' => 'Project Manager',
        ])->assertSessionHas('success');

        $designation = Designation::where('name', 'Project Manager')->firstOrFail();

        $this->actingAs($admin)->delete(route('admin.feedback.designations.destroy', $designation))
            ->assertSessionHas('success');
        $this->assertSoftDeleted('designations', ['id' => $designation->id]);

        // Storing deleted designation again restores it
        $this->actingAs($admin)->post(route('admin.feedback.designations.store'), [
            'name' => 'Project Manager',
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('designations', ['id' => $designation->id, 'deleted_at' => null]);
    }

    public function test_admin_can_save_feedback_form_with_weighted_fields_and_emoji_options(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        $schema = [
            'fields' => [
                [
                    'id' => 'overall_rating',
                    'particular' => 'How satisfied are you with the event?',
                    'type' => 'radio',
                    'weight' => 1,
                    'options' => [
                        ['value' => '5', 'label' => '⭐⭐⭐⭐⭐ Outstanding'],
                        ['value' => '4', 'label' => '⭐⭐⭐⭐ Very Satisfactory'],
                        ['value' => '3', 'label' => '⭐⭐⭐ Satisfactory'],
                        ['value' => '2', 'label' => '⭐⭐ Fair'],
                        ['value' => '1', 'label' => '⭐ Poor'],
                    ],
                ],
                [
                    'id' => 'topics_interested',
                    'particular' => 'Topics for future sessions',
                    'type' => 'checkbox',
                    'weight' => 2,
                    'options' => [
                        ['value' => 'cloud_security', 'label' => '🔒 Cloud Security & DevSecOps'],
                        ['value' => 'generative_ai', 'label' => '🤖 Applied Generative AI'],
                        ['value' => 'quantum_computing', 'label' => '⚛️ Quantum Computing'],
                    ],
                ],
                [
                    'id' => 'recommendations',
                    'particular' => 'Any suggestions for improvement?',
                    'type' => 'text',
                    'weight' => 3,
                    'options' => [],
                ],
            ],
        ];

        $response = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'schema' => $schema,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('feedback', [
            'event_id' => $event->id,
        ]);

        $savedFeedback = Feedback::where('event_id', $event->id)->firstOrFail();
        $this->assertCount(3, $savedFeedback->schema['fields']);
        $this->assertEquals('⭐⭐⭐⭐⭐ Outstanding', $savedFeedback->schema['fields'][0]['options'][0]['label']);
    }

    public function test_admin_can_save_schema_dropdown_configured_with_fb_functions_source(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();
        $func1 = FbFunction::factory()->create(['function' => 'Lead Evaluator']);
        $func2 = FbFunction::factory()->create(['function' => 'Field Observer']);
        $event->functions()->attach([$func1->id, $func2->id]);

        $schema = [
            'fields' => [
                [
                    'id' => 'assigned_role',
                    'particular' => 'Participant Role / Function',
                    'type' => 'select',
                    'weight' => 1,
                    'option_source' => 'fb_functions',
                    'function_ids' => [$func1->id, $func2->id],
                ],
            ],
        ];

        $response = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'schema' => $schema,
        ]);

        $response->assertSessionHas('success');
        $savedFeedback = Feedback::where('event_id', $event->id)->firstOrFail();
        $field = $savedFeedback->schema['fields'][0];

        $this->assertEquals('select', $field['type']);
        $this->assertEquals('fb_functions', $field['option_source']);
        $this->assertEquals([$func1->id, $func2->id], $field['function_ids']);
    }

    public function test_feedback_form_rejects_invalid_schema(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        // Invalid: empty options on static radio type
        $response = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'broken_field',
                        'particular' => 'Broken Field',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [],
                    ],
                ],
            ],
        ]);

        $response->assertSessionHasErrors();
    }

    public function test_admin_can_save_schema_with_section_fields_and_receive_submissions(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        $schema = [
            'fields' => [
                [
                    'id' => 'section_1',
                    'particular' => 'Part 1: Speaker Performance',
                    'type' => 'section',
                    'weight' => 1,
                    'description' => 'Rate the overall quality and delivery of speakers.',
                ],
                [
                    'id' => 'speaker_rating',
                    'particular' => 'Speaker delivery and mastery',
                    'type' => 'radio',
                    'weight' => 2,
                    'required' => true,
                    'options' => [
                        ['value' => 'excellent', 'label' => 'Excellent'],
                        ['value' => 'good', 'label' => 'Good'],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'schema' => $schema,
        ]);

        $response->assertSessionHas('success');

        $savedFeedback = Feedback::where('event_id', $event->id)->firstOrFail();
        $this->assertCount(2, $savedFeedback->schema['fields']);
        $this->assertEquals('section', $savedFeedback->schema['fields'][0]['type']);
        $this->assertEquals('Part 1: Speaker Performance', $savedFeedback->schema['fields'][0]['particular']);
        $this->assertEquals('Rate the overall quality and delivery of speakers.', $savedFeedback->schema['fields'][0]['description']);

        // Test submission with section field in schema: respondent answers required question, no section answer needed
        $participant = FbParticipant::factory()->create();
        $validator = app(FeedbackSchemaValidator::class);
        $sanitized = $validator->validateSubmissionData($savedFeedback->schema, [
            'speaker_rating' => 'excellent',
        ], $event);

        $this->assertEquals(['speaker_rating' => 'excellent'], $sanitized);
    }

    public function test_admin_can_save_paginated_questionnaire_schema_with_conditional_sections_and_branching(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();

        $schema = [
            'title' => 'Training Evaluation Survey',
            'description' => 'Please share your feedback across workshop sessions.',
            'pagination' => [
                'enabled' => true,
                'progress_bar' => true,
                'show_section_numbers' => true,
            ],
            'fields' => [
                [
                    'id' => 'section_intro',
                    'particular' => 'Section 1: General Info',
                    'type' => 'section',
                    'weight' => 1,
                    'section_flow' => 'next',
                ],
                [
                    'id' => 'attended_workshop',
                    'particular' => 'Did you attend Workshop B?',
                    'type' => 'radio',
                    'weight' => 2,
                    'required' => true,
                    'options' => [
                        [
                            'value' => 'yes',
                            'label' => 'Yes, attended Workshop B',
                            'goto_section' => 'section_workshop_b',
                        ],
                        [
                            'value' => 'no',
                            'label' => 'No, skipped Workshop B',
                            'goto_section' => 'submit',
                        ],
                    ],
                ],
                [
                    'id' => 'section_workshop_b',
                    'particular' => 'Section 2: Workshop B Feedback',
                    'type' => 'section',
                    'weight' => 3,
                    'section_flow' => 'submit',
                    'conditions' => [
                        [
                            'field_id' => 'attended_workshop',
                            'operator' => 'equals',
                            'value' => 'yes',
                        ],
                    ],
                ],
                [
                    'id' => 'workshop_b_rating',
                    'particular' => 'Workshop B Rating',
                    'type' => 'radio',
                    'weight' => 4,
                    'required' => true,
                    'options' => [
                        ['value' => 'excellent', 'label' => 'Excellent'],
                        ['value' => 'good', 'label' => 'Good'],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($admin)->post(route('admin.feedback.forms.save'), [
            'event_id' => $event->id,
            'schema' => $schema,
        ]);

        $response->assertSessionHas('success');

        $savedFeedback = Feedback::where('event_id', $event->id)->firstOrFail();
        $this->assertTrue($savedFeedback->schema['pagination']['enabled']);
        $this->assertEquals('Training Evaluation Survey', $savedFeedback->schema['title']);
        $this->assertCount(4, $savedFeedback->schema['fields']);
        $this->assertEquals('section_workshop_b', $savedFeedback->schema['fields'][1]['options'][0]['goto_section']);
        $this->assertEquals('submit', $savedFeedback->schema['fields'][1]['options'][1]['goto_section']);
        $this->assertEquals('attended_workshop', $savedFeedback->schema['fields'][2]['conditions'][0]['field_id']);

        // Test validator with submission that chose 'no' -> skipped section 2's required field
        $validator = app(FeedbackSchemaValidator::class);
        $sanitizedNo = $validator->validateSubmissionData($savedFeedback->schema, [
            'attended_workshop' => 'no',
        ], $event);
        $this->assertEquals('no', $sanitizedNo['attended_workshop']);
        $this->assertNull($sanitizedNo['workshop_b_rating']);

        // Test validator with submission that chose 'yes' -> section 2 is active and required
        $sanitizedYes = $validator->validateSubmissionData($savedFeedback->schema, [
            'attended_workshop' => 'yes',
            'workshop_b_rating' => 'excellent',
        ], $event);
        $this->assertEquals('yes', $sanitizedYes['attended_workshop']);
        $this->assertEquals('excellent', $sanitizedYes['workshop_b_rating']);
    }

    public function test_validator_evaluates_field_level_conditions_and_operators(): void
    {
        $event = FbEvent::factory()->create();
        $validator = app(FeedbackSchemaValidator::class);

        $schema = [
            'fields' => [
                [
                    'id' => 'feedback_type',
                    'particular' => 'Feedback Category',
                    'type' => 'text',
                    'weight' => 1,
                    'required' => true,
                ],
                [
                    'id' => 'critical_details',
                    'particular' => 'Please explain the critical issue',
                    'type' => 'textarea',
                    'weight' => 2,
                    'required' => true,
                    'conditions' => [
                        [
                            'field_id' => 'feedback_type',
                            'operator' => 'contains',
                            'value' => 'urgent',
                        ],
                    ],
                ],
                [
                    'id' => 'regular_comment',
                    'particular' => 'General Comment',
                    'type' => 'textarea',
                    'weight' => 3,
                    'required' => true,
                    'conditions' => [
                        [
                            'field_id' => 'feedback_type',
                            'operator' => 'not_contains',
                            'value' => 'urgent',
                        ],
                    ],
                ],
            ],
        ];

        // Case 1: 'feedback_type' does NOT contain 'urgent' -> 'critical_details' is skipped, 'regular_comment' is required
        $sanitized = $validator->validateSubmissionData($schema, [
            'feedback_type' => 'general feedback',
            'regular_comment' => 'Great session!',
        ], $event);

        $this->assertEquals('general feedback', $sanitized['feedback_type']);
        $this->assertNull($sanitized['critical_details']);
        $this->assertEquals('Great session!', $sanitized['regular_comment']);

        // Case 2: 'feedback_type' contains 'urgent' -> 'critical_details' is required, 'regular_comment' is skipped
        $sanitizedUrgent = $validator->validateSubmissionData($schema, [
            'feedback_type' => 'urgent issue report',
            'critical_details' => 'Server disconnected during plenary',
        ], $event);

        $this->assertEquals('urgent issue report', $sanitizedUrgent['feedback_type']);
        $this->assertEquals('Server disconnected during plenary', $sanitizedUrgent['critical_details']);
        $this->assertNull($sanitizedUrgent['regular_comment']);

        // Case 3: 'feedback_type' is 'urgent' but 'critical_details' is missing -> throws ValidationException
        $this->expectException(ValidationException::class);
        $validator->validateSubmissionData($schema, [
            'feedback_type' => 'urgent issue report',
        ], $event);
    }
}
