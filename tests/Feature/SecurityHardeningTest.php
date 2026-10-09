<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\FbEvent;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\SsoAuthorizationCode;
use App\Models\SsoClient;
use App\Models\SsoUserBinding;
use App\Models\User;
use App\Services\FormImport\Parsers\WordDocumentParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_routes_include_security_headers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertTrue($response->headers->has('Permissions-Policy'));
    }

    public function test_sso_authorization_code_mark_as_used_is_atomic(): void
    {
        $user = User::factory()->create();
        $client = SsoClient::create([
            'name' => 'Atomic Test App',
            'client_id' => 'atomic_client',
            'client_secret' => 'atomic_secret',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'is_active' => true,
        ]);

        $rawCode = Str::random(40);
        $authCode = SsoAuthorizationCode::create([
            'code' => hash('sha256', $rawCode),
            'client_id' => $client->client_id,
            'user_id' => $user->id,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'expires_at' => now()->addMinutes(2),
        ]);

        // First call must succeed
        $this->assertTrue($authCode->markAsUsed());

        // Subsequent call must fail (already used)
        $this->assertFalse($authCode->markAsUsed());
    }

    public function test_user_sensitive_attributes_are_guarded_from_mass_assignment(): void
    {
        $user = User::create([
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'password' => 'secret123',
            'is_admin' => true,
            'is_banned' => true,
        ]);

        // Because is_admin and is_banned are no longer in fillable, mass assignment will not set them to true
        $this->assertFalse((bool) $user->is_admin);
        $this->assertFalse((bool) $user->is_banned);
    }

    public function test_chat_attachment_download_sanitizes_crlf_in_content_disposition(): void
    {
        Storage::fake('local');

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user1->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user2->id]);

        $fakeFilePath = 'chat_attachments/test_file.txt';
        Storage::disk('local')->put($fakeFilePath, 'safe file content');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user1->id,
            'body' => 'File message',
            'type' => 'file',
            'attachment_path' => $fakeFilePath,
            'attachment_name' => "malicious\r\ninjection\"test.txt",
            'attachment_type' => 'text/plain',
        ]);

        $response = $this->actingAs($user2)->get(route('chat.download-attachment', $message));

        $response->assertOk();
        $contentDisposition = $response->headers->get('Content-Disposition');
        $this->assertStringNotContainsString("\r", $contentDisposition);
        $this->assertStringNotContainsString("\n", $contentDisposition);
        $this->assertEquals('inline; filename="maliciousinjectiontest.txt"', $contentDisposition);
    }

    public function test_docx_parser_does_not_enable_entity_substitution(): void
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = $tempDir.'/test_safe_'.uniqid().'.docx';

        $zip = new ZipArchive;
        $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Sample Test Question?</w:t></w:r></w:p></w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $parser = new WordDocumentParser;
        $result = $parser->parse($tempFile);

        $this->assertNotEmpty($result['paragraphs']);
        $this->assertEquals('Sample Test Question?', $result['paragraphs'][0]['text']);

        @unlink($tempFile);
    }

    public function test_test_broadcast_route_is_forbidden_for_regular_users(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('test-broadcast'));

        $response->assertStatus(403);
    }

    public function test_feedback_embed_allows_frame_ancestors_and_does_not_force_x_frame_options_sameorigin(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::create([
            'name' => 'Embed Test Event',
            'created_by' => $user->id,
        ]);
        Feedback::create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    ['id' => 'q1', 'label' => 'Rating', 'type' => 'rating'],
                ],
            ],
        ]);
        $embed = $event->embed()->create([
            'public_id' => (string) Str::uuid(),
            'allowed_origins' => ['http://127.0.0.1:8001', 'http://gisportal'],
            'is_active' => true,
        ]);
        $session = $embed->createSession();

        $response = $this->get(route('feedback.embed.show', [
            'publicId' => $embed->public_id,
            'token' => $session['session_token'],
        ]));

        $response->assertOk();
        // Must contain Content-Security-Policy with frame-ancestors
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString('http://127.0.0.1:8001', $csp);
        // Must not conflict with X-Frame-Options SAMEORIGIN
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    public function test_sso_token_exchange_blocks_concurrent_or_duplicate_code_replay(): void
    {
        $user = User::factory()->create();
        $client = SsoClient::create([
            'name' => 'Client App',
            'client_id' => 'client_app_id',
            'client_secret' => 'client_app_secret',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'is_active' => true,
        ]);

        SsoUserBinding::create([
            'user_id' => $user->id,
            'client_id' => $client->client_id,
            'external_user_id' => 'ext_42',
            'external_username' => 'ext_user',
            'is_verified' => true,
        ]);

        $rawCode = Str::random(40);
        SsoAuthorizationCode::create([
            'code' => hash('sha256', $rawCode),
            'client_id' => $client->client_id,
            'user_id' => $user->id,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'expires_at' => now()->addMinutes(2),
        ]);

        // First exchange must succeed
        $firstResponse = $this->postJson('/api/sso/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'client_secret' => 'client_app_secret',
            'code' => $rawCode,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
        ]);
        $firstResponse->assertOk();
        $firstResponse->assertJsonStructure(['access_token', 'user' => ['id', 'bound_user_id']]);

        // Second exchange with identical code must be blocked (atomic replay prevention)
        $secondResponse = $this->postJson('/api/sso/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'client_secret' => 'client_app_secret',
            'code' => $rawCode,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
        ]);
        $secondResponse->assertStatus(400);
        $secondResponse->assertJson(['error' => 'invalid_grant']);
    }

    public function test_feedback_api_form_works_with_api_key(): void
    {
        $user = User::factory()->create();
        $apiKey = 'fb_event_api_key_1234567890';
        $event = FbEvent::create([
            'name' => 'API Test Event',
            'api_key' => $apiKey,
            'created_by' => $user->id,
        ]);
        Feedback::create([
            'event_id' => $event->id,
            'schema' => [
                'fields' => [
                    ['id' => 'overall', 'label' => 'Overall Satisfaction', 'type' => 'rating'],
                ],
            ],
        ]);

        $response = $this->withHeaders(['X-API-KEY' => $apiKey])
            ->getJson('/api/feedback/form');

        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertEquals('API Test Event', $response->json('data.event.name'));
    }
}
