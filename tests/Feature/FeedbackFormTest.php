<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Designation;
use App\Models\FbEvent;
use App\Models\FbFunction;
use App\Models\FbParticipant;
use App\Models\FbSubmission;
use App\Models\Feedback;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_guest_cannot_access_feedback_form(): void
    {
        $response = $this->get(route('feedback.form'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_feedback_form_for_active_system_event(): void
    {
        $user = User::factory()->create(['name' => 'Maria Santos']);
        $event = FbEvent::factory()->create(['name' => 'Annual Simulation Exercise 2026']);
        $func1 = FbFunction::factory()->create(['function' => 'Operations Lead']);
        $func2 = FbFunction::factory()->create(['function' => 'Observer']);
        $event->functions()->attach([$func1->id, $func2->id]);

        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'rating',
                        'particular' => 'Overall rating',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => '5', 'label' => '⭐⭐⭐⭐⭐ Outstanding'],
                            ['value' => '4', 'label' => '⭐⭐⭐⭐ Very Good'],
                        ],
                    ],
                    [
                        'id' => 'assigned_role',
                        'particular' => 'Select your function',
                        'type' => 'select',
                        'weight' => 2,
                        'option_source' => 'fb_functions',
                        'function_ids' => [],
                    ],
                ],
            ],
        ]);

        Agency::factory()->create(['name' => 'MDRRMO Central']);
        Designation::factory()->create(['name' => 'Safety Officer']);

        config(['services.feedback.api_key' => $event->api_key]);

        $response = $this->actingAs($user)->get(route('feedback.form'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Form')
            ->where('event.id', $event->id)
            ->where('event.name', 'Annual Simulation Exercise 2026')
            ->has('functions', 2)
            ->has('form.schema.fields', 2)
            ->where('userDefaults.name', 'Maria Santos')
        );
    }

    public function test_feedback_form_displays_empty_state_when_no_active_form_configured(): void
    {
        $user = User::factory()->create();

        config(['services.feedback.api_key' => null]);

        $response = $this->actingAs($user)->get(route('feedback.form'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Form')
            ->where('event', null)
            ->where('form', null)
        );
    }

    public function test_feedback_form_resolves_exact_event_by_configured_api_key_regardless_of_order(): void
    {
        $user = User::factory()->create();
        $eventOlder = FbEvent::factory()->create(['name' => 'Older Event']);
        $eventTarget = FbEvent::factory()->create(['name' => 'Target Portal Event']);
        $eventNewer = FbEvent::factory()->create(['name' => 'Newer Event']);

        Feedback::factory()->create(['event_id' => $eventOlder->id]);
        Feedback::factory()->create(['event_id' => $eventTarget->id]);
        Feedback::factory()->create(['event_id' => $eventNewer->id]);

        // Configure to target event specifically
        config(['services.feedback.api_key' => $eventTarget->api_key]);

        $response = $this->actingAs($user)->get(route('feedback.form'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Form')
            ->where('event.id', $eventTarget->id)
            ->where('event.name', 'Target Portal Event')
        );
    }

    public function test_authenticated_user_can_submit_feedback_successfully(): void
    {
        $user = User::factory()->create(['name' => 'Juan Dela Cruz']);
        $event = FbEvent::factory()->create();
        $function = FbFunction::factory()->forEvent($event)->create(['function' => 'Facilitator']);

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'venue_quality',
                        'particular' => 'Venue Quality',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => 'good', 'label' => 'Good'],
                            ['value' => 'fair', 'label' => 'Fair'],
                        ],
                    ],
                    [
                        'id' => 'comments',
                        'particular' => 'Comments',
                        'type' => 'text',
                        'weight' => 2,
                        'options' => [],
                    ],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        $payload = [
            'event_id' => $event->id,
            'feedback_id' => $feedback->id,
            'participant' => [
                'name' => 'Juan Dela Cruz',
                'function_id' => $function->id,
                'agency' => 'Provincial Disaster Risk Reduction Office',
                'designation' => 'Logistics Officer',
                'years_in_designation' => 4,
                'location' => 'Command Center Hall',
                'no_of_exercises' => 2,
            ],
            'answers' => [
                'venue_quality' => 'good',
                'comments' => 'The simulation was very well-coordinated!',
            ],
        ];

        $response = $this->actingAs($user)->postJson(route('feedback.submit'), $payload);

        $response->assertCreated();

        // Verify agency and designation auto-created
        $this->assertDatabaseHas('agencies', ['name' => 'Provincial Disaster Risk Reduction Office']);
        $this->assertDatabaseHas('designations', ['name' => 'Logistics Officer']);

        // Verify participant created
        $this->assertDatabaseHas('fb_participants', [
            'name' => 'Juan Dela Cruz',
            'function_id' => $function->id,
            'agency' => 'Provincial Disaster Risk Reduction Office',
            'designation' => 'Logistics Officer',
        ]);

        // Verify submission created
        $this->assertDatabaseHas('fb_submissions', [
            'feedback_id' => $feedback->id,
        ]);

        $submission = FbSubmission::latest()->firstOrFail();
        $this->assertEquals('The simulation was very well-coordinated!', $submission->data['comments']);
    }

    public function test_feedback_submission_supports_anonymous_participant(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::factory()->create();
        $function = FbFunction::factory()->forEvent($event)->create(['function' => 'Volunteer']);

        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'clarity',
                        'particular' => 'Clarity',
                        'type' => 'text',
                        'weight' => 1,
                        'options' => [],
                    ],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        $payload = [
            'event_id' => $event->id,
            'participant' => [
                'name' => null, // Anonymous
                'function_id' => $function->id,
                'agency' => 'Red Cross Chapter',
                'designation' => 'Paramedic',
            ],
            'answers' => [
                'clarity' => 'Excellent directions.',
            ],
        ];

        $response = $this->actingAs($user)->postJson(route('feedback.submit'), $payload);

        $response->assertCreated();

        $this->assertDatabaseHas('fb_participants', [
            'name' => null,
            'agency' => 'Red Cross Chapter',
            'designation' => 'Paramedic',
        ]);

        $participant = FbParticipant::where('agency', 'Red Cross Chapter')->firstOrFail();
        $this->assertEquals('Anonymous Participant', $participant->display_name);
    }

    public function test_feedback_submission_validates_required_schema_fields(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::factory()->create();
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'satisfaction',
                        'particular' => 'Satisfaction',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => 'high', 'label' => 'High'],
                            ['value' => 'low', 'label' => 'Low'],
                        ],
                    ],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        // Submit invalid non-existent radio option
        $response = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event->id,
            'answers' => [
                'satisfaction' => 'invalid_choice',
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['data.satisfaction']);
    }

    public function test_feedback_submission_validates_function_sourced_dropdowns(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::factory()->create();
        $funcAssigned = FbFunction::factory()->create(['function' => 'Command Lead']);
        $funcUnassigned = FbFunction::factory()->create(['function' => 'Unrelated Function']);
        $event->functions()->attach($funcAssigned->id);

        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'chosen_role',
                        'particular' => 'Chosen Role',
                        'type' => 'select',
                        'weight' => 1,
                        'option_source' => 'fb_functions',
                        'function_ids' => [],
                    ],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        // Valid submission with assigned function ID
        $validResponse = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event->id,
            'answers' => [
                'chosen_role' => (string) $funcAssigned->id,
            ],
        ]);
        $validResponse->assertCreated();

        // Invalid submission with unassigned function ID
        $invalidResponse = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event->id,
            'answers' => [
                'chosen_role' => (string) $funcUnassigned->id,
            ],
        ]);
        $invalidResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['data.chosen_role']);
    }

    public function test_feedback_submission_persists_custom_function(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::factory()->create();
        $otherFunction = FbFunction::factory()->forEvent($event)->create(['function' => 'Others']);

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'feedback_notes',
                        'particular' => 'Notes',
                        'type' => 'text',
                        'weight' => 1,
                        'options' => [],
                    ],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        $payload = [
            'event_id' => $event->id,
            'feedback_id' => $feedback->id,
            'participant' => [
                'name' => 'Custom Role Participant',
                'function_id' => $otherFunction->id,
                'custom_function' => 'Community Volunteer Coordinator',
                'agency' => 'Barangay Disaster Brigade',
                'designation' => 'Team Captain',
            ],
            'answers' => [
                'feedback_notes' => 'Everything went smoothly.',
            ],
        ];

        $response = $this->actingAs($user)->postJson(route('feedback.submit'), $payload);

        $response->assertCreated();

        $this->assertDatabaseHas('fb_participants', [
            'name' => 'Custom Role Participant',
            'function_id' => $otherFunction->id,
            'custom_function' => 'Community Volunteer Coordinator',
            'agency' => 'Barangay Disaster Brigade',
        ]);
    }

    public function test_feedback_submission_rejects_inactive_or_mismatched_event_and_form(): void
    {
        $user = User::factory()->create();
        $event1 = FbEvent::factory()->create();
        $event2 = FbEvent::factory()->create();

        $feedback1 = Feedback::factory()->create(['event_id' => $event1->id]);
        $feedback2 = Feedback::factory()->create(['event_id' => $event2->id]);

        config(['services.feedback.api_key' => $event1->api_key]);

        // Mismatched event form (feedback2 belongs to event2)
        $responseMismatched = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event1->id,
            'feedback_id' => $feedback2->id,
            'answers' => [],
        ]);
        $responseMismatched->assertStatus(422);

        // Mismatched event ID (client submitted event2 while configured for event1)
        $responseInvalidEvent = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event2->id,
            'feedback_id' => $feedback1->id,
            'answers' => [],
        ]);
        $responseInvalidEvent->assertStatus(422);

        // Non-numeric / malformed event_id
        $responseMalformedEvent = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => 'malformed-id',
            'feedback_id' => $feedback1->id,
            'answers' => [],
        ]);
        $responseMalformedEvent->assertStatus(422);

        // Non-numeric / malformed feedback_id
        $responseMalformedForm = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event1->id,
            'feedback_id' => 'malformed-form-id',
            'answers' => [],
        ]);
        $responseMalformedForm->assertStatus(422);
    }

    public function test_arbitrary_schema_fields_render_and_sort_by_weight(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::factory()->create(['name' => 'Custom Schema Exercise']);

        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'third_field',
                        'particular' => 'Third Field Label',
                        'type' => 'textarea',
                        'weight' => 30,
                    ],
                    [
                        'id' => 'first_field',
                        'particular' => 'First Field Label',
                        'type' => 'number',
                        'weight' => 10,
                        'min' => 1,
                        'max' => 10,
                    ],
                    [
                        'id' => 'second_field',
                        'particular' => 'Second Field Label',
                        'type' => 'text',
                        'weight' => 20,
                    ],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        $response = $this->actingAs($user)->get(route('feedback.form'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Form')
            ->where('event.name', 'Custom Schema Exercise')
            ->has('form.schema.fields', 3)
            ->where('form.schema.fields.0.id', 'third_field')
            ->where('form.schema.fields.1.id', 'first_field')
            ->where('form.schema.fields.2.id', 'second_field')
        );
    }

    public function test_renaming_field_particular_does_not_break_submission_or_behavior(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::factory()->create();

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q1_rating',
                        'particular' => 'Completely New Customized Display Label That Changed',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => 'excellent', 'label' => 'Excellent'],
                            ['value' => 'poor', 'label' => 'Poor'],
                        ],
                    ],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        $response = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event->id,
            'feedback_id' => $feedback->id,
            'answers' => [
                'q1_rating' => 'excellent',
            ],
        ]);

        $response->assertCreated();
        $submission = FbSubmission::latest()->firstOrFail();
        $this->assertEquals('excellent', $submission->data['q1_rating']);
    }

    public function test_all_generic_field_types_submit_and_validate_successfully(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::factory()->create();

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'full_feedback_text',
                        'particular' => 'Detailed Feedback',
                        'type' => 'textarea',
                        'weight' => 1,
                        'required' => true,
                    ],
                    [
                        'id' => 'rating_score',
                        'particular' => 'Score (1-10)',
                        'type' => 'number',
                        'weight' => 2,
                        'required' => true,
                        'min' => 1,
                        'max' => 10,
                    ],
                    [
                        'id' => 'preferred_topics',
                        'particular' => 'Preferred Topics',
                        'type' => 'checkbox',
                        'weight' => 3,
                        'required' => true,
                        'options' => [
                            ['value' => 'evacuation', 'label' => 'Evacuation Planning'],
                            ['value' => 'first_aid', 'label' => 'First Aid'],
                            ['value' => 'logistics', 'label' => 'Logistics Support'],
                        ],
                    ],
                    [
                        'id' => 'channel',
                        'particular' => 'Preferred Channel',
                        'type' => 'select',
                        'weight' => 4,
                        'options' => [
                            ['value' => 'email', 'label' => 'Email'],
                            ['value' => 'sms', 'label' => 'SMS'],
                        ],
                    ],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        // Submit valid values
        $response = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event->id,
            'feedback_id' => $feedback->id,
            'answers' => [
                'full_feedback_text' => 'Great organization and timely execution.',
                'rating_score' => 9,
                'preferred_topics' => ['evacuation', 'first_aid'],
                'channel' => 'email',
            ],
        ]);

        $response->assertCreated();

        $submission = FbSubmission::latest()->firstOrFail();
        $this->assertEquals('Great organization and timely execution.', $submission->data['full_feedback_text']);
        $this->assertEquals(9, $submission->data['rating_score']);
        $this->assertEquals(['evacuation', 'first_aid'], $submission->data['preferred_topics']);
        $this->assertEquals('email', $submission->data['channel']);
    }

    public function test_allow_other_and_allow_custom_value_behaviors(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::factory()->create();

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'role_selection',
                        'particular' => 'Select Your Role',
                        'type' => 'select',
                        'weight' => 1,
                        'required' => true,
                        'allow_other' => true,
                        'options' => [
                            ['value' => 'lead', 'label' => 'Team Lead', 'is_other' => false],
                            ['value' => 'other', 'label' => 'Others', 'is_other' => true],
                        ],
                    ],
                    [
                        'id' => 'custom_agency',
                        'particular' => 'Agency with Custom Input',
                        'type' => 'select',
                        'weight' => 2,
                        'allow_custom_value' => true,
                        'options' => [
                            ['value' => 'DepEd', 'label' => 'Department of Education'],
                        ],
                    ],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        // 1. Submit other value with custom text
        $response = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event->id,
            'feedback_id' => $feedback->id,
            'answers' => [
                'role_selection' => 'other',
                'role_selection_other' => 'Drone Specialist',
                'custom_agency' => 'Independent Volunteer Group',
            ],
        ]);

        $response->assertCreated();
        $submission = FbSubmission::latest()->firstOrFail();
        $this->assertEquals('other', $submission->data['role_selection']);
        $this->assertEquals('Drone Specialist', $submission->data['role_selection_other']);
        $this->assertEquals('Independent Volunteer Group', $submission->data['custom_agency']);

        // 2. Submit other without specifying _other value when required should fail
        $failResponse = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event->id,
            'feedback_id' => $feedback->id,
            'answers' => [
                'role_selection' => 'other',
                'role_selection_other' => '',
                'custom_agency' => 'DepEd',
            ],
        ]);

        $failResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['answers.role_selection_other']);
    }
}
