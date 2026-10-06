<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Designation;
use App\Models\FbEvent;
use App\Models\FbFunction;
use App\Models\FbParticipant;
use App\Models\FbSubmission;
use App\Models\Feedback;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_api_requires_valid_event_api_key(): void
    {
        $response = $this->getJson(route('api.feedback.form'));
        $response->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated: Missing Event API Key. Provide via X-API-KEY header.',
            ]);

        // Query param api_key is rejected because only header/bearer token is accepted
        $event = FbEvent::factory()->create();
        $queryResponse = $this->getJson(route('api.feedback.form', ['api_key' => $event->api_key]));
        $queryResponse->assertUnauthorized();

        $invalidResponse = $this->withHeaders([
            'X-API-KEY' => 'invalid_api_key',
        ])->getJson(route('api.feedback.form'));

        $invalidResponse->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated: Invalid or inactive Event API Key.',
            ]);
    }

    public function test_api_can_fetch_form_schema_with_emoji_options(): void
    {
        $event = FbEvent::factory()->create(['name' => 'National GovTech Summit']);
        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'venue_rating',
                        'particular' => 'Rate the venue facilities',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => 'excellent', 'label' => '🏛️ Excellent Venue'],
                            ['value' => 'good', 'label' => '👍 Good Venue'],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $event->api_key,
        ])->getJson(route('api.feedback.form'));

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'event' => [
                        'id' => $event->id,
                        'name' => 'National GovTech Summit',
                    ],
                    'form' => [
                        'id' => $feedback->id,
                    ],
                ],
            ]);

        $this->assertEquals('🏛️ Excellent Venue', $response->json('data.form.schema.fields.0.options.0.label'));
    }

    public function test_api_returns_only_event_assigned_functions_and_populates_dynamic_dropdown(): void
    {
        $eventA = FbEvent::factory()->create(['name' => 'Event A']);
        $eventB = FbEvent::factory()->create(['name' => 'Event B']);

        $funcA1 = FbFunction::factory()->create(['function' => 'Incident Commander', 'details' => 'Command Lead']);
        $funcA2 = FbFunction::factory()->create(['function' => 'Safety Officer', 'details' => null]);
        $funcB = FbFunction::factory()->create(['function' => 'External Evaluator', 'details' => null]);

        $eventA->functions()->attach([$funcA1->id, $funcA2->id]);
        $eventB->functions()->attach([$funcB->id]);

        Feedback::factory()->create([
            'event_id' => $eventA->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'participant_role',
                        'particular' => 'Select your role',
                        'type' => 'select',
                        'weight' => 1,
                        'option_source' => 'fb_functions',
                        'function_ids' => [], // all event functions
                    ],
                ],
            ],
        ]);

        $response = $this->withHeaders([
            'X-API-KEY' => $eventA->api_key,
        ])->getJson(route('api.feedback.form'));

        $response->assertOk();

        // Check functions list on root data
        $functionsData = $response->json('data.functions');
        $this->assertCount(2, $functionsData);
        $funcNames = array_column($functionsData, 'function');
        $this->assertContains('Incident Commander', $funcNames);
        $this->assertContains('Safety Officer', $funcNames);
        $this->assertNotContains('External Evaluator', $funcNames);

        // Check dynamic dropdown options on schema field
        $fieldOptions = $response->json('data.form.schema.fields.0.options');
        $this->assertCount(2, $fieldOptions);
        $optionValues = array_column($fieldOptions, 'value');
        $this->assertContains((string) $funcA1->id, $optionValues);
        $this->assertContains((string) $funcA2->id, $optionValues);
        $this->assertNotContains((string) $funcB->id, $optionValues);
    }

    public function test_api_can_fetch_lookups_for_event(): void
    {
        $event = FbEvent::factory()->create();
        Agency::factory()->create(['name' => 'DICT Central Office']);
        Designation::factory()->create(['name' => 'Chief Technology Officer']);

        $response = $this->withHeaders([
            'X-API-KEY' => $event->api_key,
        ])->getJson(route('api.feedback.lookups'));

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'agencies' => ['DICT Central Office'],
                    'designations' => ['Chief Technology Officer'],
                ],
            ]);
    }

    public function test_api_can_submit_feedback_with_anonymous_participant_and_auto_created_agencies(): void
    {
        $event = FbEvent::factory()->create();
        $function = FbFunction::factory()->forEvent($event)->create(['function' => 'Participant']);
        $feedback = Feedback::factory()->create([
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
                    [
                        'id' => 'recommendations',
                        'particular' => 'Recommendations',
                        'type' => 'text',
                        'weight' => 2,
                        'options' => [],
                    ],
                ],
            ],
        ]);

        $payload = [
            'participant' => [
                'name' => null, // Anonymous participant!
                'function_id' => $function->id,
                'agency' => 'Brand New Public Agency', // Not currently in DB
                'designation' => 'Lead Systems Architect', // Not currently in DB
                'years_in_designation' => 5,
                'location' => 'BGC Taguig',
                'no_of_exercises' => 3,
            ],
            'answers' => [
                'clarity' => 'High',
                'recommendations' => 'Keep up the great work! 🚀',
            ],
        ];

        $response = $this->withHeaders([
            'X-API-KEY' => $event->api_key,
        ])->postJson(route('api.feedback.submissions'), $payload);

        $response->assertCreated();

        // Verify agency & designation auto-creation
        $this->assertDatabaseHas('agencies', ['name' => 'Brand New Public Agency']);
        $this->assertDatabaseHas('designations', ['name' => 'Lead Systems Architect']);

        // Verify participant creation
        $this->assertDatabaseHas('fb_participants', [
            'name' => null,
            'agency' => 'Brand New Public Agency',
            'designation' => 'Lead Systems Architect',
            'function_id' => $function->id,
        ]);

        $participant = FbParticipant::where('agency', 'Brand New Public Agency')->firstOrFail();
        $this->assertEquals('Anonymous Participant', $participant->display_name);

        // Verify submission creation
        $this->assertDatabaseHas('fb_submissions', [
            'feedback_id' => $feedback->id,
            'participant_id' => $participant->id,
        ]);

        $submission = FbSubmission::where('participant_id', $participant->id)->firstOrFail();
        $this->assertEquals('Keep up the great work! 🚀', $submission->data['recommendations']);
    }

    public function test_api_submission_sanitizes_mismatched_event_function(): void
    {
        $eventA = FbEvent::factory()->create();
        $feedbackA = Feedback::factory()->create([
            'event_id' => $eventA->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'feedback',
                        'particular' => 'Feedback',
                        'type' => 'text',
                        'weight' => 1,
                        'options' => [],
                    ],
                ],
            ],
        ]);
        $eventB = FbEvent::factory()->create();
        $functionB = FbFunction::factory()->forEvent($eventB)->create();

        $payload = [
            'participant' => [
                'name' => 'Jane Doe',
                'function_id' => $functionB->id, // Belongs to event B, but authenticating with Event A
                'agency' => 'Some Agency',
                'designation' => 'Director',
            ],
            'answers' => [
                'feedback' => 'Test',
            ],
        ];

        // Should process with null function_id because functionB is not in eventA
        $response = $this->withHeaders([
            'X-API-KEY' => $eventA->api_key,
        ])->postJson(route('api.feedback.submissions'), $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('fb_participants', [
            'name' => 'Jane Doe',
            'function_id' => null,
        ]);
    }

    public function test_api_submission_validates_function_sourced_dropdown_and_rejects_unassigned(): void
    {
        $event = FbEvent::factory()->create();
        $funcAssigned = FbFunction::factory()->create(['function' => 'Lead Facilitator']);
        $funcUnassigned = FbFunction::factory()->create(['function' => 'Unrelated Function']);
        $event->functions()->attach($funcAssigned->id);

        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'assigned_function',
                        'particular' => 'Assigned Function',
                        'type' => 'select',
                        'weight' => 1,
                        'option_source' => 'fb_functions',
                        'function_ids' => [],
                    ],
                ],
            ],
        ]);

        // Submit with valid assigned function ID
        $validResponse = $this->withHeaders([
            'X-API-KEY' => $event->api_key,
        ])->postJson(route('api.feedback.submissions'), [
            'answers' => [
                'assigned_function' => (string) $funcAssigned->id,
            ],
        ]);
        $validResponse->assertCreated();

        // Submit with unassigned function ID -> rejected
        $invalidResponse = $this->withHeaders([
            'X-API-KEY' => $event->api_key,
        ])->postJson(route('api.feedback.submissions'), [
            'answers' => [
                'assigned_function' => (string) $funcUnassigned->id,
            ],
        ]);
        $invalidResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['data.assigned_function']);

        // Submit with function name instead of option value ID -> rejected
        $nameResponse = $this->withHeaders([
            'X-API-KEY' => $event->api_key,
        ])->postJson(route('api.feedback.submissions'), [
            'answers' => [
                'assigned_function' => 'Lead Facilitator',
            ],
        ]);
        $nameResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['data.assigned_function']);
    }

    public function test_api_submission_preserves_static_dropdown_behavior(): void
    {
        $event = FbEvent::factory()->create();
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'rating_level',
                        'particular' => 'Rating Level',
                        'type' => 'select',
                        'weight' => 1,
                        'option_source' => 'static',
                        'options' => [
                            ['value' => 'high', 'label' => 'High'],
                            ['value' => 'medium', 'label' => 'Medium'],
                            ['value' => 'low', 'label' => 'Low'],
                        ],
                    ],
                ],
            ],
        ]);

        // Valid static select
        $responseValid = $this->withHeaders(['X-API-KEY' => $event->api_key])
            ->postJson(route('api.feedback.submissions'), [
                'answers' => ['rating_level' => 'high'],
            ]);
        $responseValid->assertCreated();

        // Invalid static select
        $responseInvalid = $this->withHeaders(['X-API-KEY' => $event->api_key])
            ->postJson(route('api.feedback.submissions'), [
                'answers' => ['rating_level' => 'non_existent_option'],
            ]);
        $responseInvalid->assertUnprocessable()
            ->assertJsonValidationErrors(['data.rating_level']);
    }

    public function test_api_submission_rejects_non_scalar_values_for_choice_fields(): void
    {
        $event = FbEvent::factory()->create();
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'rating',
                        'particular' => 'Rating',
                        'type' => 'radio',
                        'weight' => 1,
                        'options' => [
                            ['value' => 'good', 'label' => 'Good'],
                        ],
                    ],
                    [
                        'id' => 'topics',
                        'particular' => 'Topics',
                        'type' => 'checkbox',
                        'weight' => 2,
                        'options' => [
                            ['value' => 'ai', 'label' => 'AI'],
                        ],
                    ],
                ],
            ],
        ]);

        // Non-scalar radio value (e.g. array)
        $responseRadio = $this->withHeaders(['X-API-KEY' => $event->api_key])
            ->postJson(route('api.feedback.submissions'), [
                'answers' => [
                    'rating' => ['nested' => 'bad'],
                ],
            ]);
        $responseRadio->assertUnprocessable()
            ->assertJsonValidationErrors(['data.rating']);

        // Non-scalar checkbox array element (e.g. nested array)
        $responseCheckbox = $this->withHeaders(['X-API-KEY' => $event->api_key])
            ->postJson(route('api.feedback.submissions'), [
                'answers' => [
                    'topics' => [['nested' => 'bad']],
                ],
            ]);
        $responseCheckbox->assertUnprocessable()
            ->assertJsonValidationErrors(['data.topics']);
    }
}
