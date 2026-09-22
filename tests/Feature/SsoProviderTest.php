<?php

namespace Tests\Feature;

use App\Models\SsoAuthorizationCode;
use App\Models\SsoClient;
use App\Models\SsoUserBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SsoProviderTest extends TestCase
{
    use RefreshDatabase;

    protected SsoClient $client;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = SsoClient::create([
            'name' => 'Test Client',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'testuser@example.com',
        ]);
    }

    public function test_authorization_requires_client_id_and_redirect_uri(): void
    {
        $response = $this->get('/sso/authorize');
        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_request']);
    }

    public function test_authorization_rejects_invalid_client(): void
    {
        $response = $this->get('/sso/authorize?client_id=unknown&redirect_uri=http://127.0.0.1:8001/sso/callback');
        $response->assertStatus(400);
        $response->assertJson(['error' => 'unauthorized_client']);
    }

    public function test_authorization_rejects_invalid_redirect_uri(): void
    {
        $response = $this->get('/sso/authorize?client_id=test_client_id&redirect_uri=http://evil.com/callback');
        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_redirect_uri']);
    }

    public function test_authorization_redirects_unauthenticated_user_to_login(): void
    {
        $response = $this->get('/sso/authorize?client_id=test_client_id&redirect_uri=http://127.0.0.1:8001/sso/callback&state=test_state');
        $response->assertRedirect(route('login'));
        $this->assertTrue(session()->has('sso_authorize_params'));
    }

    public function test_unbound_user_is_redirected_to_connected_systems_page(): void
    {
        $response = $this->actingAs($this->user)->get('/sso/authorize?'.http_build_query([
            'client_id' => 'test_client_id',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'response_type' => 'code',
            'state' => 'test_state',
        ]));

        $response->assertRedirect(route('sso.connected-systems'));
        $this->assertEquals('test_client_id', session('sso_pending_bind_client'));

        // Follow redirect to connected-systems and ensure it loads (200 OK) without infinite redirect
        $connectedSystemsResponse = $this->actingAs($this->user)->get(route('sso.connected-systems'));
        $connectedSystemsResponse->assertStatus(200);
    }

    public function test_bound_user_issues_authorization_code(): void
    {
        SsoUserBinding::create([
            'user_id' => $this->user->id,
            'client_id' => 'test_client_id',
            'external_user_id' => 'ext_123',
            'external_username' => 'testuser@example.com',
            'is_verified' => true,
        ]);

        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $response = $this->actingAs($this->user)->get('/sso/authorize?'.http_build_query([
            'client_id' => 'test_client_id',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'response_type' => 'code',
            'state' => 'test_state_123',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]));

        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        $this->assertStringContainsString('http://127.0.0.1:8001/sso/callback', $targetUrl);
        $this->assertStringContainsString('code=', $targetUrl);
        $this->assertStringContainsString('state=test_state_123', $targetUrl);

        $this->assertDatabaseHas('sso_authorization_codes', [
            'client_id' => 'test_client_id',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_token_endpoint_exchanges_code_for_identity_token(): void
    {
        SsoUserBinding::create([
            'user_id' => $this->user->id,
            'client_id' => 'test_client_id',
            'external_user_id' => 'ext_123',
            'external_username' => 'testuser@example.com',
            'is_verified' => true,
        ]);

        $rawCode = Str::random(40);
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        SsoAuthorizationCode::create([
            'code' => hash('sha256', $rawCode),
            'client_id' => 'test_client_id',
            'user_id' => $this->user->id,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'expires_at' => now()->addMinutes(2),
        ]);

        $response = $this->postJson('/api/sso/token', [
            'grant_type' => 'authorization_code',
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'code' => $rawCode,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'code_verifier' => $codeVerifier,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'token_type',
            'access_token',
            'expires_in',
            'user' => ['id', 'bound_user_id', 'bound_username', 'name', 'email'],
        ]);

        $this->assertEquals('ext_123', $response->json('user.bound_user_id'));
    }

    public function test_user_can_bind_system_account(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/sso/verify-credentials' => Http::response([
                'success' => true,
                'user' => [
                    'id' => 'ext_999',
                    'username' => 'lfews_user',
                    'email' => 'lfews_user@example.com',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->post(route('sso.connected-systems.bind', $this->client->client_id), [
            'username' => 'lfews_user',
            'password' => 'target_password',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('sso_user_bindings', [
            'user_id' => $this->user->id,
            'client_id' => $this->client->client_id,
            'external_user_id' => 'ext_999',
            'external_username' => 'lfews_user',
        ]);
    }

    public function test_user_can_unbind_system_account(): void
    {
        SsoUserBinding::create([
            'user_id' => $this->user->id,
            'client_id' => 'test_client_id',
            'external_user_id' => 'ext_123',
            'external_username' => 'testuser@example.com',
            'is_verified' => true,
        ]);

        $response = $this->actingAs($this->user)->delete(route('sso.connected-systems.unbind', 'test_client_id'));

        $response->assertRedirect();
        $this->assertDatabaseMissing('sso_user_bindings', [
            'user_id' => $this->user->id,
            'client_id' => 'test_client_id',
        ]);
    }
}
