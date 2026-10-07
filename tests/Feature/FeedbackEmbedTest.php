<?php

namespace Tests\Feature;

use App\Models\FbEvent;
use App\Models\FbFunction;
use App\Models\FbSubmission;
use App\Models\Feedback;
use App\Models\FeedbackEmbed;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FeedbackEmbedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_external_backend_can_create_embed_session_with_valid_credentials(): void
    {
        $event = FbEvent::factory()->create(['name' => 'City Drill 2026']);
        Feedback::factory()->create(['event_id' => $event->id]);

        $plainSecret = 'super-secure-secret-12345';
        /** @var FeedbackEmbed $embed */
        $embed = $event->embed;
        $embed->update([
            'client_id' => 'emb_client_city_drill',
            'client_secret' => Hash::make($plainSecret),
            'is_active' => true,
        ]);

        $response = $this->postJson(route('api.feedback.embed.sessions'), [
            'client_id' => 'emb_client_city_drill',
            'client_secret' => $plainSecret,
            'respondent_id' => 'user_12345',
            'user_defaults' => [
                'agency' => 'City Health Office',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'status',
                'data' => [
                    'session_token',
                    'expires_at',
                    'iframe_url',
                ],
            ]);

        $this->assertDatabaseHas('fb_embed_sessions', [
            'embed_id' => $embed->id,
            'respondent_id' => 'user_12345',
            'respondent_hash' => hash('sha256', 'user_12345'),
        ]);
    }

    public function test_embed_tables_are_named_fb_embeds_and_fb_embed_sessions(): void
    {
        $event = FbEvent::factory()->create();
        $embed = $event->embed;

        $this->assertDatabaseHas('fb_embeds', [
            'id' => $embed->id,
            'event_id' => $event->id,
            'public_id' => $embed->public_id,
        ]);

        $sessionData = $embed->createSession('test_user');

        $this->assertDatabaseHas('fb_embed_sessions', [
            'embed_id' => $embed->id,
            'respondent_id' => 'test_user',
        ]);
    }

    public function test_external_backend_is_rejected_with_invalid_client_secret(): void
    {
        $event = FbEvent::factory()->create();
        Feedback::factory()->create(['event_id' => $event->id]);

        /** @var FeedbackEmbed $embed */
        $embed = $event->embed;
        $embed->update([
            'client_id' => 'emb_client_test',
            'client_secret' => Hash::make('valid_secret'),
        ]);

        $response = $this->postJson(route('api.feedback.embed.sessions'), [
            'client_id' => 'emb_client_test',
            'client_secret' => 'WRONG_SECRET',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid or inactive feedback embed credentials.',
            ]);
    }

    public function test_cannot_create_session_for_inactive_embed_or_unconfigured_event(): void
    {
        $event = FbEvent::factory()->create();

        // 1. Event without feedback form
        $embed = $event->embed;
        $embed->update([
            'client_id' => 'emb_client_test',
            'client_secret' => Hash::make('secret123'),
            'is_active' => true,
        ]);

        $noFormResponse = $this->postJson(route('api.feedback.embed.sessions'), [
            'client_id' => 'emb_client_test',
            'client_secret' => 'secret123',
        ]);

        $noFormResponse->assertStatus(422);

        // 2. Inactive embed
        Feedback::factory()->create(['event_id' => $event->id]);
        $embed->update(['is_active' => false]);

        $inactiveResponse = $this->postJson(route('api.feedback.embed.sessions'), [
            'client_id' => 'emb_client_test',
            'client_secret' => 'secret123',
        ]);

        $inactiveResponse->assertStatus(401);
    }

    public function test_iframe_page_renders_with_valid_session_token(): void
    {
        $event = FbEvent::factory()->create(['name' => 'Disaster Simulation 2026']);
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'preparedness',
                        'particular' => 'Rate preparedness',
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

        /** @var FeedbackEmbed $embed */
        $embed = $event->embed;
        $embed->update([
            'allowed_origins' => ['https://portal.lgu.gov.ph'],
            'is_active' => true,
        ]);

        $sessionData = $embed->createSession('respondent_999');
        $token = $sessionData['session_token'];

        $response = $this->get(route('feedback.embed.show', [
            'publicId' => $embed->public_id,
            'token' => $token,
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Feedback/Embed')
            ->where('event.name', 'Disaster Simulation 2026')
            ->where('publicId', $embed->public_id)
            ->where('sessionToken', $token)
            ->has('form.schema.fields', 1)
        );

        $response->assertHeader('Content-Security-Policy', "frame-ancestors 'self' https://portal.lgu.gov.ph;");
    }

    public function test_iframe_page_returns_404_for_invalid_expired_or_used_token(): void
    {
        $event = FbEvent::factory()->create();
        Feedback::factory()->create(['event_id' => $event->id]);
        /** @var FeedbackEmbed $embed */
        $embed = $event->embed;

        // 1. Missing token
        $this->get(route('feedback.embed.show', ['publicId' => $embed->public_id]))
            ->assertNotFound();

        // 2. Invalid token
        $this->get(route('feedback.embed.show', ['publicId' => $embed->public_id, 'token' => 'invalid-token-xyz']))
            ->assertNotFound();

        // 3. Expired session
        $sessionData = $embed->createSession();
        $embed->sessions()->where('token_hash', hash('sha256', $sessionData['session_token']))
            ->update(['expires_at' => now()->subMinute()]);

        $this->get(route('feedback.embed.show', ['publicId' => $embed->public_id, 'token' => $sessionData['session_token']]))
            ->assertNotFound();

        // 4. Used session
        $usedSessionData = $embed->createSession();
        $embed->sessions()->where('token_hash', hash('sha256', $usedSessionData['session_token']))
            ->update(['used_at' => now()->subMinute()]);

        $this->get(route('feedback.embed.show', ['publicId' => $embed->public_id, 'token' => $usedSessionData['session_token']]))
            ->assertNotFound();
    }

    public function test_public_user_can_submit_feedback_via_embed_route(): void
    {
        $event = FbEvent::factory()->create();
        $function = FbFunction::factory()->forEvent($event)->create(['function' => 'Safety Evaluator']);

        $feedback = Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'rating',
                        'particular' => 'Overall Rating',
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

        /** @var FeedbackEmbed $embed */
        $embed = $event->embed;
        $sessionData = $embed->createSession('user_resp_123');
        $token = $sessionData['session_token'];

        $payload = [
            'token' => $token,
            'participant' => [
                'name' => 'Dr. Jose Rizal',
                'function_id' => $function->id,
                'agency' => 'Provincial Medical Corps',
            ],
            'answers' => [
                'rating' => 'excellent',
            ],
        ];

        $response = $this->postJson(route('feedback.embed.submit', $embed->public_id), $payload);

        $response->assertCreated();

        // Verify submission in DB linked to embed and session
        $submission = FbSubmission::latest()->firstOrFail();
        $this->assertEquals($feedback->id, $submission->feedback_id);
        $this->assertEquals($embed->id, $submission->embed_id);
        $this->assertNotNull($submission->embed_session_id);
        $this->assertEquals(hash('sha256', 'user_resp_123'), $submission->respondent_hash);
        $this->assertEquals('excellent', $submission->data['rating']);

        // Verify session marked as used
        $session = $embed->sessions()->where('token_hash', hash('sha256', $token))->firstOrFail();
        $this->assertNotNull($session->used_at);
    }

    public function test_session_cannot_submit_twice(): void
    {
        $event = FbEvent::factory()->create();
        Feedback::factory()->create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'feedback_text',
                        'particular' => 'Comments',
                        'type' => 'text',
                        'weight' => 1,
                    ],
                ],
            ],
        ]);

        /** @var FeedbackEmbed $embed */
        $embed = $event->embed;
        $sessionData = $embed->createSession();
        $token = $sessionData['session_token'];

        $payload = [
            'token' => $token,
            'answers' => [
                'feedback_text' => 'First submission.',
            ],
        ];

        // 1. First submission succeeds
        $this->postJson(route('feedback.embed.submit', $embed->public_id), $payload)
            ->assertCreated();

        // 2. Second submission with the same token is rejected
        $secondResponse = $this->postJson(route('feedback.embed.submit', $embed->public_id), [
            'token' => $token,
            'answers' => [
                'feedback_text' => 'Duplicate submission attempt.',
            ],
        ]);

        $secondResponse->assertNotFound();
    }

    public function test_browser_supplied_event_identifiers_cannot_override_embed_target_event(): void
    {
        $eventReal = FbEvent::factory()->create(['name' => 'Real Event']);
        $eventAttacker = FbEvent::factory()->create(['name' => 'Attacker Event']);

        $feedbackReal = Feedback::factory()->create(['event_id' => $eventReal->id]);
        $feedbackAttacker = Feedback::factory()->create(['event_id' => $eventAttacker->id]);

        /** @var FeedbackEmbed $embedReal */
        $embedReal = $eventReal->embed;
        $sessionData = $embedReal->createSession();
        $token = $sessionData['session_token'];

        // Attacker attempts to pass attacker event_id and feedback_id
        $payload = [
            'event_id' => $eventAttacker->id,
            'feedback_id' => $feedbackAttacker->id,
            'token' => $token,
            'answers' => [],
        ];

        $response = $this->postJson(route('feedback.embed.submit', $embedReal->public_id), $payload);
        $response->assertCreated();

        $submission = FbSubmission::latest()->firstOrFail();
        $this->assertEquals($feedbackReal->id, $submission->feedback_id);
        $this->assertEquals($embedReal->id, $submission->embed_id);
    }

    public function test_regenerating_public_id_invalidates_active_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();
        Feedback::factory()->create(['event_id' => $event->id]);

        /** @var FeedbackEmbed $embed */
        $embed = $event->embed;
        $oldPublicId = $embed->public_id;

        $sessionData = $embed->createSession();
        $token = $sessionData['session_token'];

        $response = $this->actingAs($admin)->post(route('admin.feedback.events.regenerate-embed-id', $event->id));
        $response->assertRedirect();

        $embed->refresh();
        $this->assertNotEquals($oldPublicId, $embed->public_id);

        // Old public ID is dead
        $this->get(route('feedback.embed.show', ['publicId' => $oldPublicId, 'token' => $token]))
            ->assertNotFound();

        // Old session token under new public ID is also dead (invalidated)
        $this->get(route('feedback.embed.show', ['publicId' => $embed->public_id, 'token' => $token]))
            ->assertNotFound();
    }

    public function test_regenerating_client_secret_invalidates_active_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $event = FbEvent::factory()->create();
        Feedback::factory()->create(['event_id' => $event->id]);

        /** @var FeedbackEmbed $embed */
        $embed = $event->embed;
        $sessionData = $embed->createSession();
        $token = $sessionData['session_token'];

        $response = $this->actingAs($admin)->post(route('admin.feedback.events.regenerate-embed-secret', $event->id));
        $response->assertRedirect();
        $response->assertSessionHas('revealed_secret');

        // Session was invalidated
        $this->get(route('feedback.embed.show', ['publicId' => $embed->public_id, 'token' => $token]))
            ->assertNotFound();
    }

    public function test_non_admin_cannot_manage_embed_settings(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $event = FbEvent::factory()->create();

        $this->actingAs($user)->post(route('admin.feedback.events.regenerate-embed-id', $event->id))
            ->assertForbidden();

        $this->actingAs($user)->post(route('admin.feedback.events.regenerate-embed-secret', $event->id))
            ->assertForbidden();

        $this->actingAs($user)->put(route('admin.feedback.events.update-embed-origins', $event->id), [
            'allowed_origins' => ['https://malicious.com'],
        ])->assertForbidden();
    }
}
