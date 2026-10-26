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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveFeedbackFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'app.url' => 'http://loginportal',
            'services.turnstile.key' => '0x4AAAAAAFgvUmyPPMRyS5WR',
            'services.turnstile.secret' => '1x0000000000000000000000000000000AA',
            'services.turnstile.hostnames' => 'loginportal,localhost,127.0.0.1',
            'services.turnstile.action' => 'feedback_submit',
        ]);
    }

    protected function fakeValidTurnstile(string $hostname = 'loginportal', string $action = 'feedback_submit'): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'hostname' => $hostname,
                'action' => $action,
                'challenge_ts' => now()->toIso8601String(),
                'error-codes' => [],
            ], 200),
        ]);
    }

    public function test_guest_can_access_live_deployed_feedback_form_without_authentication(): void
    {
        $event = FbEvent::factory()->create([
            'name' => 'Disaster Preparedness Summit 2026',
            'details' => 'Annual evaluation of local contingency procedures.',
        ]);

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'title' => 'Summit Feedback Questionnaire',
                'description' => 'Please provide feedback on the session.',
                'fields' => [
                    [
                        'id' => 'preparedness_score',
                        'particular' => 'Preparedness Score',
                        'type' => 'radio',
                        'weight' => 1,
                        'required' => true,
                        'options' => [
                            ['value' => 'excellent', 'label' => 'Excellent'],
                            ['value' => 'good', 'label' => 'Good'],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('forms.live', $event->api_key));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Live')
            ->where('event.name', 'Disaster Preparedness Summit 2026')
            ->where('event.details', 'Annual evaluation of local contingency procedures.')
            ->where('form.id', $feedback->id)
            ->has('form.schema.fields', 1)
            ->where('form.schema.fields.0.id', 'preparedness_score')
            ->where('apiKey', $event->api_key)
        );
    }

    public function test_correct_questionnaire_is_rendered_for_supplied_api_key(): void
    {
        $event1 = FbEvent::factory()->create(['name' => 'Event One']);
        $event2 = FbEvent::factory()->create(['name' => 'Event Two']);

        Feedback::factory()->create([
            'event_id' => $event1->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'question_one',
                        'particular' => 'Question for Event 1',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        Feedback::factory()->create([
            'event_id' => $event2->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'question_two',
                        'particular' => 'Question for Event 2',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('forms.live', $event2->api_key));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Live')
            ->where('event.name', 'Event Two')
            ->where('form.schema.fields.0.id', 'question_two')
        );
    }

    public function test_unknown_api_key_returns_404(): void
    {
        $response = $this->get(route('forms.live', 'fb_non_existent_key_12345678901234567890'));
        $response->assertNotFound();
    }

    public function test_invalid_api_key_structure_returns_404(): void
    {
        // Malformed key with spaces or forbidden characters
        $response = $this->get('/forms/e/'.urlencode('invalid key with spaces!@#$%'));
        $response->assertNotFound();
    }

    public function test_soft_deleted_inactive_event_cannot_be_accessed(): void
    {
        $event = FbEvent::factory()->create(['name' => 'Archived Event']);
        Feedback::factory()->create(['event_id' => $event->id]);

        $apiKey = $event->api_key;
        $event->delete(); // Soft delete

        $this->assertSoftDeleted('fb_events', ['id' => $event->id]);

        $response = $this->get(route('forms.live', $apiKey));
        $response->assertNotFound();
    }

    public function test_event_without_configured_questionnaire_returns_404(): void
    {
        $event = FbEvent::factory()->create(['name' => 'Event Without Form']);
        // No Feedback record created

        $response = $this->get(route('forms.live', $event->api_key));
        $response->assertNotFound();
    }

    public function test_event_with_empty_or_invalid_schema_returns_404(): void
    {
        $event = FbEvent::factory()->create(['name' => 'Event With Empty Schema']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => ['fields' => []],
        ]);

        $response = $this->get(route('forms.live', $event->api_key));
        $response->assertNotFound();
    }

    public function test_rendered_page_does_not_expose_secrets_or_administrative_data(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::factory()->create([
            'name' => 'Secure Public Event',
            'created_by' => $user->id,
        ]);

        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'rating',
                        'particular' => 'Rating',
                        'type' => 'number',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('forms.live', $event->api_key));

        $response->assertOk();
        $inertiaProps = $response->viewData('page')['props'];

        // Must not expose embed client secrets, user hashes, creator info, or Turnstile secret
        $this->assertArrayNotHasKey('client_secret', $inertiaProps);
        $this->assertArrayNotHasKey('embed', $inertiaProps['event']);
        $this->assertArrayNotHasKey('created_by', $inertiaProps['event']);
        $this->assertArrayNotHasKey('creator', $inertiaProps['event']);
        $this->assertSame('0x4AAAAAAFgvUmyPPMRyS5WR', $inertiaProps['turnstile']['site_key'] ?? null);
        $this->assertSame('0x4AAAAAAFgvUmyPPMRyS5WR', $inertiaProps['turnstileSiteKey'] ?? null);
        $this->assertArrayNotHasKey('secret', $inertiaProps['turnstile'] ?? []);
        $this->assertStringNotContainsString('1x0000000000000000000000000000000AA', $response->getContent());
    }

    public function test_dynamic_options_are_resolved_for_event_functions_and_lookups(): void
    {
        $event = FbEvent::factory()->create(['name' => 'Simulation Exercise']);
        $func1 = FbFunction::factory()->create(['function' => 'Triage Officer']);
        $func2 = FbFunction::factory()->create(['function' => 'Incident Commander']);
        $event->functions()->attach([$func1->id, $func2->id]);

        Agency::factory()->create(['name' => 'City Disaster Office']);
        Designation::factory()->create(['name' => 'Chief Operations Specialist']);

        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'function_select',
                        'particular' => 'Select Function',
                        'type' => 'select',
                        'weight' => 1,
                        'option_source' => 'fb_functions',
                    ],
                    [
                        'id' => 'agency_select',
                        'particular' => 'Select Agency',
                        'type' => 'select',
                        'weight' => 2,
                        'option_source' => 'agencies',
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('forms.live', $event->api_key));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Live')
            ->has('functions', 2)
            ->has('lookups.agencies')
            ->has('lookups.designations')
            ->where('form.schema.fields.0.options.0.label', 'Incident Commander')
            ->where('form.schema.fields.1.options.0.value', 'City Disaster Office')
        );
    }

    public function test_guest_can_submit_live_deployed_feedback_form_successfully(): void
    {
        $this->fakeValidTurnstile();

        $event = FbEvent::factory()->create(['name' => 'Community Drill']);
        $func = FbFunction::factory()->create(['function' => 'Logistics Officer']);
        $event->functions()->attach($func->id);

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'satisfaction',
                        'particular' => 'Satisfaction Level',
                        'type' => 'radio',
                        'weight' => 1,
                        'required' => true,
                        'options' => [
                            ['value' => 'high', 'label' => 'High'],
                            ['value' => 'low', 'label' => 'Low'],
                        ],
                    ],
                    [
                        'id' => 'comments',
                        'particular' => 'General Comments',
                        'type' => 'textarea',
                        'weight' => 2,
                        'required' => false,
                    ],
                ],
            ],
        ]);

        $payload = [
            'name' => 'Juan dela Cruz',
            'agency' => 'Barangay Health Center',
            'designation' => 'Nurse II',
            'function_id' => $func->id,
            'years_in_designation' => 4,
            'location' => 'Zone 4, Central',
            'no_of_exercises' => 2,
            'turnstile_token' => 'valid-turnstile-token-001',
            'answers' => [
                'satisfaction' => 'high',
                'comments' => 'Organized and on time.',
            ],
        ];

        $response = $this->postJson(route('forms.live.submit', $event->api_key), $payload);

        $response->assertCreated();
        $response->assertJsonStructure([
            'id',
            'event_id',
            'feedback_id',
            'participant' => [
                'id',
                'name',
                'agency',
                'designation',
            ],
            'data',
            'created_at',
        ]);

        $this->assertDatabaseHas('fb_participants', [
            'name' => 'Juan dela Cruz',
            'agency' => 'Barangay Health Center',
            'designation' => 'Nurse II',
            'function_id' => $func->id,
        ]);

        $this->assertDatabaseHas('fb_submissions', [
            'feedback_id' => $feedback->id,
        ]);

        $submission = FbSubmission::latest()->first();
        $this->assertSame('high', $submission->data['satisfaction']);
        $this->assertSame('Organized and on time.', $submission->data['comments']);
        $this->assertArrayNotHasKey('turnstile_token', $submission->data);
        $this->assertArrayNotHasKey('cf-turnstile-response', $submission->data);
    }

    public function test_live_submission_supports_anonymous_respondents(): void
    {
        $this->fakeValidTurnstile();

        $event = FbEvent::factory()->create(['name' => 'Anonymous Drill']);
        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'feedback_notes',
                        'particular' => 'Feedback Notes',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $payload = [
            'turnstile_token' => 'valid-turnstile-token-anon',
            'answers' => [
                'feedback_notes' => 'Anonymous feedback response',
            ],
        ];

        $response = $this->postJson(route('forms.live.submit', $event->api_key), $payload);

        $response->assertCreated();

        $participant = FbParticipant::latest()->first();
        $this->assertNull($participant->name);

        $submission = FbSubmission::latest()->first();
        $this->assertSame('Anonymous feedback response', $submission->data['feedback_notes']);
    }

    public function test_live_submission_validates_required_fields_against_schema(): void
    {
        $this->fakeValidTurnstile();

        $event = FbEvent::factory()->create(['name' => 'Validation Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'mandatory_q',
                        'particular' => 'Mandatory Question',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'turnstile_token' => 'valid-turnstile-token-schema-check',
            'answers' => [
                'mandatory_q' => '',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['answers.mandatory_q']);
        $this->assertSame(0, FbSubmission::count());
    }

    public function test_submission_cannot_be_redirected_to_another_event_or_form(): void
    {
        $this->fakeValidTurnstile();

        $eventA = FbEvent::factory()->create(['name' => 'Event Alpha']);
        $eventB = FbEvent::factory()->create(['name' => 'Event Beta']);

        $feedbackA = Feedback::factory()->create([
            'event_id' => $eventA->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q_alpha',
                        'particular' => 'Question Alpha',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        $feedbackB = Feedback::factory()->create([
            'event_id' => $eventB->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q_beta',
                        'particular' => 'Question Beta',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        // Attempt to post to Event A's live route but injecting Event B's event_id
        $responseTamperedEvent = $this->postJson(route('forms.live.submit', $eventA->api_key), [
            'event_id' => $eventB->id,
            'turnstile_token' => 'valid-token-tamper-1',
            'answers' => [
                'q_alpha' => 'Test answer',
            ],
        ]);

        $responseTamperedEvent->assertStatus(422);

        // Attempt to post to Event A's live route but injecting Event B's feedback_id
        $responseTamperedForm = $this->postJson(route('forms.live.submit', $eventA->api_key), [
            'feedback_id' => $feedbackB->id,
            'turnstile_token' => 'valid-token-tamper-2',
            'answers' => [
                'q_alpha' => 'Test answer',
            ],
        ]);

        $responseTamperedForm->assertStatus(422);

        // Verify no submissions were mistakenly saved under Feedback B
        $this->assertSame(0, FbSubmission::where('feedback_id', $feedbackB->id)->count());
    }

    public function test_submission_to_unknown_api_key_returns_404(): void
    {
        $response = $this->postJson(route('forms.live.submit', 'fb_non_existent_key_1234567890'), [
            'answers' => [],
        ]);

        $response->assertNotFound();
    }

    public function test_live_submission_accepts_standard_cf_turnstile_response_field(): void
    {
        $this->fakeValidTurnstile();

        $event = FbEvent::factory()->create(['name' => 'CF Standard Field Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'note',
                        'particular' => 'Note',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'cf-turnstile-response' => 'valid-cf-standard-token',
            'answers' => [
                'note' => 'Submitted via cf-turnstile-response',
            ],
        ]);

        $response->assertCreated();
        $this->assertSame(1, FbSubmission::count());
    }

    public function test_live_submission_rejects_missing_turnstile_token_and_does_not_persist_feedback(): void
    {
        Http::fake();

        $event = FbEvent::factory()->create(['name' => 'Missing Token Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'note',
                        'particular' => 'Note',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'answers' => [
                'note' => 'Bypass attempt without token',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['turnstile_token']);
        $this->assertSame(0, FbSubmission::count());
        $this->assertSame(0, FbParticipant::count());
    }

    public function test_live_submission_rejects_invalid_turnstile_token_when_siteverify_returns_false(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ], 200),
        ]);

        $event = FbEvent::factory()->create(['name' => 'Invalid Token Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'note',
                        'particular' => 'Note',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'turnstile_token' => 'XXXX.DUMMY.TOKEN.XXXX',
            'answers' => [
                'note' => 'Should not be saved',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['turnstile_token']);
        $this->assertSame(0, FbSubmission::count());
        $this->assertSame(0, FbParticipant::count());
    }

    public function test_live_submission_rejects_expired_or_previously_redeemed_token_from_siteverify(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => false,
                'error-codes' => ['timeout-or-duplicate'],
            ], 200),
        ]);

        $event = FbEvent::factory()->create(['name' => 'Expired Token Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'note',
                        'particular' => 'Note',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'turnstile_token' => 'expired-or-used-token',
            'answers' => [
                'note' => 'Should not be saved',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['turnstile_token']);
        $this->assertSame(0, FbSubmission::count());
    }

    public function test_live_submission_rejects_token_replay_on_second_attempt(): void
    {
        $this->fakeValidTurnstile();

        $event = FbEvent::factory()->create(['name' => 'Replay Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'note',
                        'particular' => 'Note',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $payload = [
            'turnstile_token' => 'single-use-token-xyz',
            'answers' => [
                'note' => 'First submission',
            ],
        ];

        $firstResponse = $this->postJson(route('forms.live.submit', $event->api_key), $payload);
        $firstResponse->assertCreated();
        $this->assertSame(1, FbSubmission::count());

        // Replay the exact same token a second time
        $replayResponse = $this->postJson(route('forms.live.submit', $event->api_key), $payload);
        $replayResponse->assertStatus(422);
        $replayResponse->assertJsonValidationErrors(['turnstile_token']);
        $this->assertSame(1, FbSubmission::count());
    }

    public function test_live_submission_fails_closed_on_cloudflare_http_error(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response('Bad Gateway', 502),
        ]);

        $event = FbEvent::factory()->create(['name' => 'HTTP Error Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'note',
                        'particular' => 'Note',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'turnstile_token' => 'token-during-outage',
            'answers' => [
                'note' => 'Should fail closed',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['turnstile_token']);
        $this->assertSame(0, FbSubmission::count());
    }

    public function test_live_submission_fails_closed_on_network_timeout_or_exception(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $event = FbEvent::factory()->create(['name' => 'Timeout Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'note',
                        'particular' => 'Note',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'turnstile_token' => 'token-during-timeout',
            'answers' => [
                'note' => 'Should fail closed',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['turnstile_token']);
        $this->assertSame(0, FbSubmission::count());
    }

    public function test_live_submission_fails_closed_when_turnstile_secret_is_missing(): void
    {
        config(['services.turnstile.secret' => '']);
        Http::fake();

        $event = FbEvent::factory()->create(['name' => 'Missing Secret Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'note',
                        'particular' => 'Note',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'turnstile_token' => 'any-token',
            'answers' => [
                'note' => 'Should fail closed without secret',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['turnstile_token']);
        $this->assertSame(0, FbSubmission::count());
        Http::assertNothingSent();
    }

    public function test_live_submission_rejects_unexpected_hostname_and_action(): void
    {
        $event = FbEvent::factory()->create(['name' => 'Hostname & Action Check Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'note',
                        'particular' => 'Note',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        // 1. Unexpected hostname
        $this->fakeValidTurnstile(hostname: 'malicious.example.com', action: 'feedback_submit');

        $badHostResponse = $this->postJson(route('forms.live.submit', $event->api_key), [
            'turnstile_token' => 'token-wrong-host',
            'answers' => ['note' => 'Wrong host test'],
        ]);

        $badHostResponse->assertStatus(422);
        $badHostResponse->assertJsonValidationErrors(['turnstile_token']);
        $this->assertSame(0, FbSubmission::count());

        // 2. Unexpected action
        $this->fakeValidTurnstile(hostname: 'loginportal', action: 'login');

        $badActionResponse = $this->postJson(route('forms.live.submit', $event->api_key), [
            'turnstile_token' => 'token-wrong-action',
            'answers' => ['note' => 'Wrong action test'],
        ]);

        $badActionResponse->assertStatus(422);
        $badActionResponse->assertJsonValidationErrors(['turnstile_token']);
        $this->assertSame(0, FbSubmission::count());
    }

    public function test_production_environment_rejects_localhost_turnstile_hostname(): void
    {
        config([
            'app.env' => 'production',
            'services.turnstile.hostnames' => 'portal.lgu.gov.ph,localhost,127.0.0.1',
        ]);

        $this->fakeValidTurnstile(hostname: 'localhost', action: 'feedback_submit');

        $event = FbEvent::factory()->create(['name' => 'Prod Hostname Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'note',
                        'particular' => 'Note',
                        'type' => 'text',
                        'weight' => 1,
                        'required' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->postJson(route('forms.live.submit', $event->api_key), [
            'turnstile_token' => 'token-localhost-in-prod',
            'answers' => ['note' => 'Localhost in prod should fail'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['turnstile_token']);
        $this->assertSame(0, FbSubmission::count());
    }

    public function test_live_deployment_page_loads_explicit_turnstile_script_once_and_provides_site_key(): void
    {
        $event = FbEvent::factory()->create(['name' => 'Turnstile Script Check Event']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q1',
                        'particular' => 'Question 1',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('forms.live', $event->api_key));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit')
        );
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Live')
            ->where('turnstileSiteKey', '0x4AAAAAAFgvUmyPPMRyS5WR')
            ->where('turnstile.site_key', '0x4AAAAAAFgvUmyPPMRyS5WR')
        );
    }

    public function test_guest_visiting_draft_live_form_sees_not_available_page_without_form_fields(): void
    {
        $event = FbEvent::factory()->create(['name' => 'Unpublished Conference']);
        Feedback::factory()->draft()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q1',
                        'particular' => 'Draft Question',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        $response = $this->get(route('forms.live', $event->api_key));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Live')
            ->where('event.name', 'Unpublished Conference')
            ->where('isAvailable', false)
            ->where('unavailableReason', 'draft')
            ->where('form', null)
        );
    }

    public function test_admin_can_preview_draft_feedback_form(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create(['name' => 'Admin Preview Event']);
        Feedback::factory()->draft()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q1',
                        'particular' => 'Draft Question',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('forms.live', $event->api_key));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Live')
            ->where('form.is_draft', true)
            ->where('form.status', 'draft')
        );
    }

    public function test_guest_cannot_submit_to_draft_feedback_form(): void
    {
        $this->fakeValidTurnstile();
        $event = FbEvent::factory()->create(['name' => 'Draft Form Submit Protection']);
        Feedback::factory()->draft()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q1',
                        'particular' => 'Draft Question',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        $response = $this->post(route('forms.live.submit', $event->api_key), [
            'q1' => 'Trying to submit to draft form',
            'cf-turnstile-response' => 'valid-turnstile-token',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, FbSubmission::count());
    }

    public function test_non_admin_only_sees_published_forms_in_feedback_index(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->admin()->create();

        $publishedEvent = FbEvent::factory()->create(['name' => 'Published Form Event']);
        $draftEvent = FbEvent::factory()->create(['name' => 'Draft Form Event']);

        $publishedForm = Feedback::factory()->published()->create([
            'event_id' => $publishedEvent->id,
            'schema' => ['fields' => []],
        ]);

        $draftForm = Feedback::factory()->draft()->create([
            'event_id' => $draftEvent->id,
            'schema' => ['fields' => []],
        ]);

        // Regular non-admin user
        $response = $this->actingAs($user)->get(route('feedback.form'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Form')
            ->has('forms', 1)
            ->where('forms.0.id', $publishedForm->id)
        );

        // Admin user
        $adminResponse = $this->actingAs($admin)->get(route('feedback.form'));
        $adminResponse->assertOk();
        $adminResponse->assertInertia(fn ($page) => $page
            ->component('Feedback/Form')
            ->has('forms', 2)
        );
    }

    public function test_non_admin_visiting_feedback_page_with_active_event_draft_form_does_not_crash(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $event = FbEvent::factory()->create(['name' => 'Active Event with Draft Form']);
        Feedback::factory()->draft()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    ['id' => 'q1', 'particular' => 'Draft Question', 'type' => 'text'],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        $response = $this->actingAs($user)->get(route('feedback.form'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Form')
            ->where('form', null)
        );
    }

    public function test_user_cannot_submit_to_internal_draft_feedback_form(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::factory()->create(['name' => 'Internal Event with Draft Form']);
        $feedback = Feedback::factory()->draft()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    ['id' => 'q1', 'particular' => 'Draft Question', 'type' => 'text'],
                ],
            ],
        ]);

        config(['services.feedback.api_key' => $event->api_key]);

        $response = $this->actingAs($user)->postJson(route('feedback.submit'), [
            'event_id' => $event->id,
            'feedback_id' => $feedback->id,
            'answers' => [
                'q1' => 'Trying to submit to internal draft form',
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'message' => 'This feedback form is currently in draft mode and is not accepting responses.',
        ]);
        $this->assertSame(0, FbSubmission::count());
    }
}
