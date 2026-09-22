<?php

namespace Tests\Feature;

use App\Models\SsoAuthorizationCode;
use App\Models\SsoClient;
use App\Models\SsoUserBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SsoSecurityAndBindingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $user;

    protected SsoClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create([
            'email' => 'regularuser@example.com',
            'password' => Hash::make('MyPortalPassword123!'),
            'is_admin' => false,
        ]);

        $this->client = SsoClient::create([
            'name' => 'TeamTracker LGU',
            'client_id' => 'teamtracker-client-id',
            'client_secret' => 'teamtracker-secret-64-bytes-random-string-secure-key',
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'api_url' => 'http://127.0.0.1:8001',
            'is_active' => true,
        ]);
    }

    public function test_client_secrets_are_never_exposed_in_listing_inertia_props(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.sso.index'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $clients = $page->toArray()['props']['clients'];
            $this->assertNotEmpty($clients);
            foreach ($clients as $clientItem) {
                $this->assertArrayNotHasKey('client_secret', $clientItem);
            }
        });
    }

    public function test_open_redirect_is_blocked_even_with_matching_callback_path(): void
    {
        // Malicious domain with matching /sso/callback path
        $maliciousUri = 'http://evil.com/sso/callback';

        $response = $this->actingAs($this->user)->get('/sso/authorize?'.http_build_query([
            'client_id' => $this->client->client_id,
            'redirect_uri' => $maliciousUri,
            'response_type' => 'code',
            'state' => 'csrf_protection_state',
        ]));

        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_redirect_uri']);
    }

    public function test_disabled_client_cannot_authorize(): void
    {
        $this->client->update(['is_active' => false]);

        $response = $this->actingAs($this->user)->get('/sso/authorize?'.http_build_query([
            'client_id' => $this->client->client_id,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'response_type' => 'code',
            'state' => 'csrf_state',
        ]));

        $response->assertStatus(400);
        $response->assertJson(['error' => 'unauthorized_client']);
    }

    public function test_authorization_code_cannot_be_replayed(): void
    {
        SsoUserBinding::create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->client_id,
            'external_user_id' => 'tt_99',
            'external_username' => 'regularuser',
            'is_verified' => true,
        ]);

        $rawCode = Str::random(40);
        SsoAuthorizationCode::create([
            'code' => hash('sha256', $rawCode),
            'client_id' => $this->client->client_id,
            'user_id' => $this->user->id,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'expires_at' => now()->addMinutes(2),
        ]);

        // First exchange -> Success
        $firstResponse = $this->postJson('/sso/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->client_id,
            'client_secret' => 'teamtracker-secret-64-bytes-random-string-secure-key',
            'code' => $rawCode,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
        ]);

        $firstResponse->assertOk();
        $firstResponse->assertJsonStructure(['access_token', 'user']);

        // Second exchange -> Replay attack blocked
        $secondResponse = $this->postJson('/sso/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->client_id,
            'client_secret' => 'teamtracker-secret-64-bytes-random-string-secure-key',
            'code' => $rawCode,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
        ]);

        $secondResponse->assertStatus(400);
        $secondResponse->assertJson(['error' => 'invalid_grant']);
        $this->assertStringContainsString('already been used', $secondResponse->json('error_description'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'replayed_authorization_code',
        ]);
    }

    public function test_expired_authorization_code_is_rejected(): void
    {
        $rawCode = Str::random(40);
        SsoAuthorizationCode::create([
            'code' => hash('sha256', $rawCode),
            'client_id' => $this->client->client_id,
            'user_id' => $this->user->id,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->postJson('/sso/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->client_id,
            'client_secret' => 'teamtracker-secret-64-bytes-random-string-secure-key',
            'code' => $rawCode,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_grant']);
        $this->assertStringContainsString('expired', $response->json('error_description'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'expired_authorization_code',
        ]);
    }

    public function test_authorization_code_bound_to_wrong_client_is_rejected(): void
    {
        $otherClient = SsoClient::create([
            'name' => 'GIS Portal',
            'client_id' => 'gis-portal-client',
            'client_secret' => 'gis-secret-key-12345',
            'redirect_uri' => 'http://127.0.0.1:8002/sso/callback',
            'is_active' => true,
        ]);

        $rawCode = Str::random(40);
        SsoAuthorizationCode::create([
            'code' => hash('sha256', $rawCode),
            'client_id' => $this->client->client_id, // Code belongs to TeamTracker
            'user_id' => $this->user->id,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'expires_at' => now()->addMinutes(2),
        ]);

        // GIS client tries to use TeamTracker's code
        $response = $this->postJson('/sso/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $otherClient->client_id,
            'client_secret' => 'gis-secret-key-12345',
            'code' => $rawCode,
            'redirect_uri' => 'http://127.0.0.1:8002/sso/callback',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_grant']);
    }

    public function test_token_exchange_rejects_invalid_client_secret(): void
    {
        $rawCode = Str::random(40);
        SsoAuthorizationCode::create([
            'code' => hash('sha256', $rawCode),
            'client_id' => $this->client->client_id,
            'user_id' => $this->user->id,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'expires_at' => now()->addMinutes(2),
        ]);

        $response = $this->postJson('/sso/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->client_id,
            'client_secret' => 'WRONG_SECRET',
            'code' => $rawCode,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'invalid_client']);
    }

    public function test_user_cannot_bind_with_invalid_target_credentials(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/sso/verify-credentials' => Http::response([
                'success' => false,
                'message' => 'Invalid username or password.',
            ], 400),
            'http://127.0.0.1:8001/sso/verify-credentials' => Http::response([
                'success' => false,
                'message' => 'Invalid username or password.',
            ], 400),
        ]);

        $response = $this->actingAs($this->user)->post(route('sso.connected-systems.bind', $this->client->client_id), [
            'username' => 'wrong_user',
            'password' => 'wrong_password',
        ]);

        $response->assertSessionHasErrors(['password']);
        $this->assertDatabaseMissing('sso_user_bindings', [
            'user_id' => $this->user->id,
            'client_id' => $this->client->client_id,
        ]);
    }

    public function test_user_can_bind_with_valid_target_credentials(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/sso/verify-credentials' => Http::response([
                'success' => true,
                'user' => ['id' => 'ext_555', 'username' => 'valid_user', 'email' => 'valid@example.com'],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->post(route('sso.connected-systems.bind', $this->client->client_id), [
            'username' => 'valid_user',
            'password' => 'target_password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('sso_user_bindings', [
            'user_id' => $this->user->id,
            'client_id' => $this->client->client_id,
            'external_user_id' => 'ext_555',
            'external_username' => 'valid_user',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $this->user->id,
            'action' => 'sso_user_binding_created',
        ]);
    }

    public function test_user_cannot_bind_account_already_bound_to_another_portal_user(): void
    {
        $otherUser = User::factory()->create();

        SsoUserBinding::create([
            'user_id' => $otherUser->id,
            'client_id' => $this->client->client_id,
            'external_user_id' => 'ext_claimed',
            'external_username' => 'claimed_user',
            'is_verified' => true,
        ]);

        Http::fake([
            'http://127.0.0.1:8001/api/sso/verify-credentials' => Http::response([
                'success' => true,
                'user' => ['id' => 'ext_claimed', 'username' => 'claimed_user', 'email' => 'claimed@example.com'],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->post(route('sso.connected-systems.bind', $this->client->client_id), [
            'username' => 'claimed_user',
            'password' => 'claimed_password',
        ]);

        $response->assertSessionHasErrors(['username']);
        $this->assertDatabaseMissing('sso_user_bindings', [
            'user_id' => $this->user->id,
            'client_id' => $this->client->client_id,
            'external_user_id' => 'ext_claimed',
        ]);
    }

    public function test_user_cannot_unbind_another_users_binding(): void
    {
        $otherUser = User::factory()->create();

        SsoUserBinding::create([
            'user_id' => $otherUser->id,
            'client_id' => $this->client->client_id,
            'external_user_id' => 'other_ext',
            'external_username' => 'other_user',
            'is_verified' => true,
        ]);

        // Acting as $this->user, attempt to unbind $this->client->client_id
        $response = $this->actingAs($this->user)->delete(route('sso.connected-systems.unbind', $this->client->client_id));

        $response->assertRedirect();

        // Other user's binding MUST remain intact
        $this->assertDatabaseHas('sso_user_bindings', [
            'user_id' => $otherUser->id,
            'client_id' => $this->client->client_id,
            'external_user_id' => 'other_ext',
        ]);
    }

    public function test_pending_sso_request_resumes_after_account_binding(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/api/sso/verify-credentials' => Http::response([
                'success' => true,
                'user' => ['id' => 'ext_777', 'username' => 'resumed_user', 'email' => 'resumed@example.com'],
            ], 200),
        ]);

        // Simulate pending SSO authorization in session
        $ssoParams = [
            'client_id' => $this->client->client_id,
            'redirect_uri' => 'http://127.0.0.1:8001/sso/callback',
            'response_type' => 'code',
            'state' => 'resumed_state_xyz',
        ];

        session([
            'sso_pending_bind_client' => $this->client->client_id,
            'sso_authorize_params' => $ssoParams,
        ]);

        $response = $this->actingAs($this->user)->post(route('sso.connected-systems.bind', $this->client->client_id), [
            'username' => 'resumed_user',
            'password' => 'target_pass',
        ]);

        // Must redirect to sso.authorize to resume flow!
        $response->assertRedirect(route('sso.authorize', $ssoParams));
    }

    public function test_admin_can_enable_and_disable_sso_client_via_endpoints(): void
    {
        $this->assertTrue((bool) $this->client->is_active);

        // Disable
        $disableResponse = $this->actingAs($this->admin)->post(route('admin.sso-clients.disable', $this->client->id));
        $disableResponse->assertRedirect();
        $this->assertFalse((bool) $this->client->fresh()->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $this->admin->id,
            'action' => 'disabled_sso_client',
        ]);

        // Enable
        $enableResponse = $this->actingAs($this->admin)->post(route('admin.sso-clients.enable', $this->client->id));
        $enableResponse->assertRedirect();
        $this->assertTrue((bool) $this->client->fresh()->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $this->admin->id,
            'action' => 'enabled_sso_client',
        ]);
    }
}
