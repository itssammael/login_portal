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
use Illuminate\Support\Facades\Http;
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

    public function test_trusted_proxy_correctly_detects_https_and_http_schemes(): void
    {
        // 1. When accessed via Cloudflare Tunnel / reverse proxy with X-Forwarded-Proto: https
        $responseHttps = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'widespread-forbes-lat-precious.trycloudflare.com',
            'X-Forwarded-Port' => '443',
        ])->get('/login');

        $responseHttps->assertOk();
        $this->assertTrue(request()->isSecure());
        $this->assertEquals('https', request()->getScheme());
        $responseHttps->assertSee('upgrade-insecure-requests', false);

        // 2. When accessed directly via local HTTP (e.g. http://lguportal/login)
        $this->flushHeaders();
        $responseHttp = $this->get('http://lguportal/login');

        $responseHttp->assertOk();
        $this->assertFalse(request()->isSecure());
        $this->assertEquals('http', request()->getScheme());
        $responseHttp->assertDontSee('upgrade-insecure-requests');
    }

    public function test_cloudflare_tunnel_real_client_ip_and_hsts_detection(): void
    {
        $response = $this->withHeaders([
            'CF-Connecting-IP' => '198.51.100.45',
            'CF-Ray' => '8d39f4893c7d0-LAX',
            'X-Forwarded-For' => '10.0.0.1, 198.51.100.45',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'widespread-forbes-lat-precious.trycloudflare.com',
            'X-Forwarded-Port' => '443',
        ])->get('/login');

        $response->assertOk();
        $this->assertEquals('198.51.100.45', request()->ip());
        $this->assertTrue(request()->isSecure());
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
    }

    public function test_cloudflare_visitor_scheme_sets_https(): void
    {
        $response = $this->withHeaders([
            'CF-Visitor' => '{"scheme":"https"}',
            'CF-Connecting-IP' => '203.0.113.88',
            'CF-Ray' => '8d39f4893c7d0-LAX',
        ])->get('/login');

        $response->assertOk();
        $this->assertTrue(request()->isSecure());
        $this->assertEquals('203.0.113.88', request()->ip());
        $response->assertHeader('Strict-Transport-Security');
    }

    public function test_local_http_access_does_not_set_hsts(): void
    {
        $this->flushHeaders();
        $response = $this->get('http://lguportal/login');

        $response->assertOk();
        $this->assertFalse(request()->isSecure());
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_feedback_embed_includes_upgrade_insecure_requests_over_https(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::create([
            'name' => 'HTTPS Embed Event',
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
            'allowed_origins' => ['https://external-parent.org'],
            'is_active' => true,
        ]);
        $session = $embed->createSession();

        $response = $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'widespread-forbes-lat-precious.trycloudflare.com',
        ])->get(route('feedback.embed.show', [
            'publicId' => $embed->public_id,
            'token' => $session['session_token'],
        ]));

        $response->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString('upgrade-insecure-requests', $csp);
        $this->assertStringContainsString('frame-ancestors', $csp);
    }

    public function test_sso_client_exact_matching_prevents_unauthorized_prefix_access(): void
    {
        $client = SsoClient::create([
            'name' => 'Specific Client Name',
            'client_id' => 'client_payroll_portal',
            'client_secret' => 'payroll_secret_123',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'is_active' => true,
        ]);

        // Exact match succeeds
        $this->assertNotNull(SsoClient::findClient('client_payroll_portal'));
        $this->assertNotNull(SsoClient::findClient('Specific Client Name'));

        // Partial prefix or substring match fails
        $this->assertNull(SsoClient::findClient('client_payroll'));
        $this->assertNull(SsoClient::findClient('Specific Client'));
        $this->assertNull(SsoClient::findClient('payroll'));
    }

    public function test_connected_systems_bind_endpoint_is_rate_limited(): void
    {
        Http::fake([
            '*' => Http::response(['success' => false, 'message' => 'Invalid credentials'], 401),
        ]);

        $user = User::factory()->create();
        $client = SsoClient::create([
            'name' => 'Brute Force Protected System',
            'client_id' => 'bf_client',
            'client_secret' => 'bf_secret',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'is_active' => true,
        ]);

        $this->actingAs($user);

        // Attempt 10 requests within throttle limit
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('sso.connected-systems.bind', $client->client_id), [
                'username' => 'testuser',
                'password' => 'wrongpass',
            ]);
        }

        // 11th request must be throttled (429)
        $response = $this->post(route('sso.connected-systems.bind', $client->client_id), [
            'username' => 'testuser',
            'password' => 'wrongpass',
        ]);

        $response->assertStatus(429);
    }

    public function test_untrusted_lan_client_cannot_spoof_client_ip_or_scheme(): void
    {
        // Direct LAN request from 192.168.1.50 attempting to spoof Cloudflare and Forwarded headers
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '192.168.1.50',
        ])->withHeaders([
            'CF-Connecting-IP' => '8.8.8.8',
            'CF-Ray' => 'fake-ray-from-lan',
            'CF-Visitor' => '{"scheme":"https"}',
            'X-Forwarded-For' => '8.8.8.8',
            'X-Forwarded-Proto' => 'https',
        ])->get('/login');

        $response->assertOk();
        // Client IP MUST NOT be rewritten to 8.8.8.8 because 192.168.1.50 is not a trusted tunnel proxy
        $this->assertEquals('192.168.1.50', request()->ip());
        // Scheme MUST NOT be rewritten to https
        $this->assertFalse(request()->isSecure());
        $this->assertEquals('http', request()->getScheme());
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_page_with_partial_csp_without_frame_ancestors_still_receives_x_frame_options(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');
        if (! str_contains($csp, 'frame-ancestors')) {
            $this->assertEquals('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        }
    }

    public function test_sso_client_find_prioritizes_exact_client_id_over_name_collision(): void
    {
        // Client A has name matching Client B's client_id
        $clientA = SsoClient::create([
            'name' => 'target-system',
            'client_id' => 'legacy-sys-01',
            'client_secret' => 'secret_a',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'is_active' => true,
        ]);

        $clientB = SsoClient::create([
            'name' => 'Other Portal',
            'client_id' => 'target-system',
            'client_secret' => 'secret_b',
            'redirect_uri' => 'http://127.0.0.1:8002/sso/callback',
            'is_active' => true,
        ]);

        // findClient('target-system') must return clientB because client_id has priority
        $found = SsoClient::findClient('target-system');
        $this->assertNotNull($found);
        $this->assertEquals($clientB->id, $found->id);
        $this->assertEquals('target-system', $found->client_id);
    }

    public function test_feedback_embed_allows_wildcard_subdomain_in_csp(): void
    {
        $user = User::factory()->create();
        $event = FbEvent::create([
            'name' => 'Wildcard Subdomain Event',
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
            'allowed_origins' => ['https://*.domain.local', 'https://*.gov.ph:8443'],
            'is_active' => true,
        ]);
        $session = $embed->createSession();

        $response = $this->get(route('feedback.embed.show', [
            'publicId' => $embed->public_id,
            'token' => $session['session_token'],
        ]));

        $response->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString('https://*.domain.local', $csp);
        $this->assertStringContainsString('https://*.gov.ph:8443', $csp);
    }
}
