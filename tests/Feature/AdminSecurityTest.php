<?php

namespace Tests\Feature;

use App\Models\SecurityBlockedIp;
use App\Models\SecurityThreatLog;
use App\Models\SecurityWhitelistedIp;
use App\Models\SsoAuthorizationCode;
use App\Models\User;
use App\Services\Security\SecuritySelfHealingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_security_dashboard(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.security.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Security/Index')
            ->has('stats')
            ->has('timeline')
            ->has('categories')
            ->has('topProbedUrls')
            ->has('topOffendingIps')
            ->has('threatLogs')
            ->has('blockedIps')
            ->has('whitelistedIps')
            ->has('recentHealingLogs')
            ->has('diagnosticFindings')
        );
    }

    public function test_non_admin_cannot_access_security_dashboard(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
        ]);

        $response = $this->actingAs($user)->get(route('admin.security.index'));

        $response->assertStatus(403);
    }

    public function test_404_probe_for_sensitive_paths_records_security_threat(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->get('/.env');

        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '198.51.100.77',
            'threat_type' => '404_probe',
            'severity' => 'critical',
        ]);
    }

    public function test_quarantined_ip_is_denied_access(): void
    {
        /** @var SecuritySelfHealingService $healingService */
        $healingService = app(SecuritySelfHealingService::class);
        $healingService->quarantineIp('203.0.113.99', 'Exploit probing', 60);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->get('/login');

        $response->assertStatus(403);
    }

    public function test_admin_can_manually_block_and_unblock_ip(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Block IP
        $response = $this->actingAs($admin)->post(route('admin.security.block-ip'), [
            'ip_address' => '192.0.2.45',
            'reason' => 'Suspicious manual quarantine test',
            'duration_minutes' => 30,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('security_blocked_ips', [
            'ip_address' => '192.0.2.45',
            'is_active' => true,
        ]);

        $blockedRecord = SecurityBlockedIp::where('ip_address', '192.0.2.45')->first();
        $this->assertNotNull($blockedRecord);

        // Unblock IP
        $unblockResponse = $this->actingAs($admin)->delete(route('admin.security.unblock-ip', $blockedRecord));

        $unblockResponse->assertRedirect();
        $this->assertDatabaseHas('security_blocked_ips', [
            'ip_address' => '192.0.2.45',
            'is_active' => false,
        ]);
    }

    public function test_self_healing_audit_executes_and_cleans_stale_codes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Create an expired SSO code to verify cleanup
        SsoAuthorizationCode::create([
            'code' => hash('sha256', Str::random(40)),
            'client_id' => 'test_client',
            'user_id' => $admin->id,
            'redirect_uri' => 'http://127.0.0.1/callback',
            'expires_at' => now()->subHours(2),
            'used_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.security.self-heal'));

        $response->assertRedirect();
        $this->assertDatabaseHas('security_healing_logs', [
            'action_type' => 'cleanup_stale_tokens',
            'status' => 'applied',
        ]);
    }

    public function test_admin_can_resolve_threat_incident(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $threat = SecurityThreatLog::create([
            'ip_address' => '198.51.100.12',
            'threat_type' => '404_probe',
            'severity' => 'medium',
            'url' => 'http://localhost/adminer.php',
            'method' => 'GET',
            'action_taken' => 'logged',
            'is_resolved' => false,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.security.resolve', $threat));

        $response->assertRedirect();
        $this->assertDatabaseHas('security_threat_logs', [
            'id' => $threat->id,
            'is_resolved' => true,
        ]);
    }

    public function test_admin_can_toggle_auto_defense_engine(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.security.toggle-auto-defense'));

        $response->assertRedirect();
        $this->assertDatabaseHas('system_rules', [
            'key' => 'security_auto_defense_enabled',
        ]);
    }

    public function test_threat_detection_captures_both_client_ipv4_and_ipv6(): void
    {
        $this->withHeaders([
            'CF-Connecting-IP' => '2a06:98c0:3600::103',
            'CF-Pseudo-IPv4' => '203.0.113.195',
        ])->get('/wp-admin/install.php?step=1');

        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '203.0.113.195',
            'client_ipv4' => '203.0.113.195',
            'client_ipv6' => '2a06:98c0:3600::103',
            'threat_type' => '404_probe',
        ]);
    }

    public function test_threat_detection_extracts_ipv4_from_forwarded_for_when_origin_is_ipv6(): void
    {
        $this->withHeaders([
            'CF-Connecting-IP' => '2a06:98c0:3600::103',
            'X-Forwarded-For' => '198.51.100.42, 2a06:98c0:3600::103',
        ])->get('/.git/config');

        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '198.51.100.42',
            'client_ipv4' => '198.51.100.42',
            'client_ipv6' => '2a06:98c0:3600::103',
            'threat_type' => '404_probe',
        ]);
    }

    public function test_quarantined_client_ipv4_is_blocked_even_when_request_comes_through_ipv6_proxy(): void
    {
        /** @var SecuritySelfHealingService $healingService */
        $healingService = app(SecuritySelfHealingService::class);
        $healingService->quarantineIp('198.51.100.88', 'Exploit probing', 60);

        $response = $this->withHeaders([
            'CF-Connecting-IP' => '2a06:98c0:3600::103',
            'CF-Pseudo-IPv4' => '198.51.100.88',
        ])->get('/login');

        $response->assertStatus(403);
    }

    public function test_security_dashboard_timeline_includes_recent_threats_in_current_bucket(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        SecurityThreatLog::create([
            'ip_address' => '198.51.100.99',
            'client_ipv4' => '198.51.100.99',
            'threat_type' => '404_probe',
            'severity' => 'high',
            'url' => 'http://localhost/test-probe',
            'method' => 'GET',
            'action_taken' => 'logged',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.security.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Security/Index')
            ->has('timeline', 12)
            ->where('timeline.11.probes', fn ($count) => $count >= 1)
            ->where('timeline.11.total', fn ($total) => $total >= 1)
        );
    }

    public function test_wp_admin_exploit_probe_triggers_critical_auto_block_and_403(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.55'])
            ->get('/wp-admin/install.php?step=1');

        $response->assertStatus(403);
        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '198.51.100.55',
            'threat_type' => '404_probe',
            'severity' => 'critical',
            'action_taken' => 'auto_blocked',
        ]);
        $this->assertDatabaseHas('security_blocked_ips', [
            'ip_address' => '198.51.100.55',
            'is_active' => true,
        ]);
    }

    public function test_self_healing_audit_quarantines_unresolved_critical_probes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        SecurityThreatLog::create([
            'ip_address' => '198.51.100.66',
            'threat_type' => '404_probe',
            'severity' => 'critical',
            'url' => 'http://localhost/adminer.php',
            'method' => 'GET',
            'action_taken' => 'logged',
            'is_resolved' => false,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.security.self-heal'));

        $response->assertRedirect();
        $this->assertDatabaseHas('security_blocked_ips', [
            'ip_address' => '198.51.100.66',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_whitelist_ip_and_automatically_unblocks_active_quarantine(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        /** @var SecuritySelfHealingService $healingService */
        $healingService = app(SecuritySelfHealingService::class);
        $healingService->quarantineIp('198.51.100.201', 'Exploit probing test', 60);

        $this->assertDatabaseHas('security_blocked_ips', [
            'ip_address' => '198.51.100.201',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.security.whitelist-ip'), [
            'ip_address' => '198.51.100.201',
            'label' => 'Internal Test Server',
            'description' => 'Trusted server unblocked and immune',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('security_whitelisted_ips', [
            'ip_address' => '198.51.100.201',
            'label' => 'Internal Test Server',
            'is_active' => true,
        ]);

        // Quarantine must be automatically unblocked and deactivated
        $this->assertDatabaseHas('security_blocked_ips', [
            'ip_address' => '198.51.100.201',
            'is_active' => false,
        ]);
    }

    public function test_whitelisted_ip_is_exempt_from_auto_quarantine_on_critical_exploit_probe(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.security.whitelist-ip'), [
            'ip_address' => '198.51.100.202',
            'label' => 'Penetration Tester Workstation',
        ]);

        // Probe critical exploit URL from whitelisted IP
        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.202'])
            ->get('/wp-admin/install.php?step=1');

        // Should return 404 Not Found (not 403 Forbidden quarantine)
        $response->assertStatus(404);

        // Security threat log must mark action_taken as whitelisted_allowed
        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '198.51.100.202',
            'threat_type' => '404_probe',
            'action_taken' => 'whitelisted_allowed',
        ]);

        // No active quarantine should be created
        $this->assertDatabaseMissing('security_blocked_ips', [
            'ip_address' => '198.51.100.202',
            'is_active' => true,
        ]);
    }

    public function test_whitelisted_cidr_range_protects_entire_subnet_from_auto_quarantine(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.security.whitelist-ip'), [
            'ip_address' => '10.50.0.0/16',
            'label' => 'Internal Corporate Network Subnet',
        ]);

        // Probe from an IP in the whitelisted CIDR range
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.50.44.88'])
            ->get('/wp-admin/install.php?step=1');

        $response->assertStatus(404);

        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '10.50.44.88',
            'action_taken' => 'whitelisted_allowed',
        ]);

        $this->assertDatabaseMissing('security_blocked_ips', [
            'ip_address' => '10.50.44.88',
            'is_active' => true,
        ]);
    }

    public function test_admin_cannot_manually_quarantine_a_whitelisted_ip(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.security.whitelist-ip'), [
            'ip_address' => '198.51.100.203',
            'label' => 'Protected Gateway',
        ]);

        // Attempt manual quarantine
        $response = $this->actingAs($admin)->post(route('admin.security.block-ip'), [
            'ip_address' => '198.51.100.203',
            'reason' => 'Accidental block attempt',
            'duration_minutes' => 60,
        ]);

        $response->assertSessionHasErrors(['ip_address']);

        $this->assertDatabaseMissing('security_blocked_ips', [
            'ip_address' => '198.51.100.203',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_toggle_and_remove_whitelisted_ip(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $whitelisted = SecurityWhitelistedIp::create([
            'ip_address' => '198.51.100.204',
            'label' => 'Temporary Whitelist',
            'added_by' => $admin->name,
            'is_active' => true,
        ]);

        // Toggle to inactive
        $toggleResponse = $this->actingAs($admin)->post(route('admin.security.toggle-whitelist-ip', $whitelisted));
        $toggleResponse->assertRedirect();
        $this->assertDatabaseHas('security_whitelisted_ips', [
            'id' => $whitelisted->id,
            'is_active' => false,
        ]);

        // Remove from whitelist
        $removeResponse = $this->actingAs($admin)->delete(route('admin.security.remove-whitelist-ip', $whitelisted));
        $removeResponse->assertRedirect();
        $this->assertDatabaseMissing('security_whitelisted_ips', [
            'id' => $whitelisted->id,
        ]);
    }

    public function test_self_healing_audit_does_not_quarantine_whitelisted_ips(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        SecurityWhitelistedIp::create([
            'ip_address' => '198.51.100.205',
            'label' => 'Whitelisted Server',
            'added_by' => $admin->name,
            'is_active' => true,
        ]);

        SecurityThreatLog::create([
            'ip_address' => '198.51.100.205',
            'threat_type' => '404_probe',
            'severity' => 'critical',
            'url' => 'http://localhost/adminer.php',
            'method' => 'GET',
            'action_taken' => 'logged',
            'is_resolved' => false,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.security.self-heal'));

        $response->assertRedirect();
        $this->assertDatabaseMissing('security_blocked_ips', [
            'ip_address' => '198.51.100.205',
            'is_active' => true,
        ]);
    }
}
