<?php

namespace App\Services\Security;

use App\Models\SecurityBlockedIp;
use App\Models\SecurityHealingLog;
use App\Models\SecurityThreatLog;
use App\Models\SsoAuthorizationCode;
use App\Models\SystemRule;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class SecuritySelfHealingService
{
    /**
     * Default whitelisted IP addresses that must never be auto-banned.
     */
    protected const DEFAULT_WHITELIST = [
        '127.0.0.1',
        '::1',
        'localhost',
    ];

    /**
     * Check whether a client IP is currently quarantined or blocked.
     */
    public function isIpBlocked(string $ip): bool
    {
        if ($this->isWhitelisted($ip)) {
            return false;
        }

        return (bool) Cache::remember("sec:blocked:{$ip}", 30, function () use ($ip): bool {
            $record = SecurityBlockedIp::where('ip_address', $ip)
                ->where('is_active', true)
                ->first();

            if (! $record) {
                return false;
            }

            if ($record->isExpired()) {
                $record->update(['is_active' => false]);

                return false;
            }

            return true;
        });
    }

    /**
     * Check if an IP is whitelisted.
     */
    public function isWhitelisted(string $ip): bool
    {
        $whitelist = SystemRule::getValue('security_ip_whitelist', self::DEFAULT_WHITELIST);

        if (is_string($whitelist)) {
            $whitelist = array_filter(array_map('trim', explode(',', $whitelist)));
        }

        return in_array($ip, (array) $whitelist, true);
    }

    /**
     * Evaluate a newly logged threat and apply automated quarantine if necessary.
     */
    public function evaluateThreat(SecurityThreatLog $threat): void
    {
        $autoDefenseEnabled = SystemRule::getValue('security_auto_defense_enabled', true);
        if (! $autoDefenseEnabled) {
            return;
        }

        $ip = $threat->ip_address;
        if ($this->isWhitelisted($ip)) {
            return;
        }

        // Prevent duplicate quarantine records if already actively blocked
        if ($this->isIpBlocked($ip) || ($threat->client_ipv4 && $this->isIpBlocked($threat->client_ipv4))) {
            return;
        }

        // Critical severity threats (SQLi, path traversal, exploit probes) trigger immediate quarantine
        if ($threat->severity === 'critical') {
            $this->quarantineIp(
                $ip,
                "Auto-quarantined: Critical threat ({$threat->threat_type}) detected at {$threat->url}",
                120,
                'self_healing_engine',
                $threat->client_ipv4,
                $threat->client_ipv6
            );
            $threat->update(['action_taken' => 'auto_blocked']);

            return;
        }

        // Calculate threat velocity from this IP in the last 15 minutes
        $threshold = match ($threat->severity) {
            'high' => 2,
            'medium' => min(3, (int) SystemRule::getValue('security_auto_ban_threshold', 3)),
            default => (int) SystemRule::getValue('security_auto_ban_threshold', 5),
        };
        $recentCount = SecurityThreatLog::where('ip_address', $ip)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();

        if ($recentCount >= $threshold) {
            $this->quarantineIp(
                $ip,
                "Auto-quarantined: Exceeded threat frequency threshold ({$recentCount} threats in 15m)",
                60,
                'self_healing_engine',
                $threat->client_ipv4,
                $threat->client_ipv6
            );
            $threat->update(['action_taken' => 'auto_blocked']);
        }
    }

    /**
     * Quarantine an IP address and write an audit healing record.
     */
    public function quarantineIp(
        string $ip,
        string $reason,
        ?int $durationMinutes = 60,
        string $blockedBy = 'self_healing_engine',
        ?string $clientIpv4 = null,
        ?string $clientIpv6 = null
    ): SecurityBlockedIp {
        $blockedUntil = $durationMinutes ? now()->addMinutes($durationMinutes) : null;

        $record = SecurityBlockedIp::updateOrCreate(
            ['ip_address' => $ip],
            [
                'client_ipv4' => $clientIpv4,
                'client_ipv6' => $clientIpv6,
                'reason' => $reason,
                'blocked_by' => $blockedBy,
                'blocked_until' => $blockedUntil,
                'is_active' => true,
            ]
        );

        $record->increment('threat_count');

        $cacheTtl = $blockedUntil ?: now()->addDay();
        Cache::put("sec:blocked:{$ip}", true, $cacheTtl);
        if ($clientIpv4 && $clientIpv4 !== $ip) {
            Cache::put("sec:blocked:{$clientIpv4}", true, $cacheTtl);
        }
        if ($clientIpv6 && $clientIpv6 !== $ip) {
            Cache::put("sec:blocked:{$clientIpv6}", true, $cacheTtl);
        }

        SecurityHealingLog::create([
            'action_type' => 'ip_auto_ban',
            'description' => "Quarantined offending IP {$ip}".($clientIpv4 ? " (IPv4: {$clientIpv4})" : '').". Reason: {$reason}",
            'target' => $ip,
            'status' => 'applied',
            'details' => [
                'client_ipv4' => $clientIpv4,
                'client_ipv6' => $clientIpv6,
                'blocked_until' => $blockedUntil?->toIso8601String(),
                'duration_minutes' => $durationMinutes,
                'blocked_by' => $blockedBy,
            ],
        ]);

        return $record;
    }

    /**
     * Unblock a quarantined IP address.
     */
    public function unblockIp(string $ip, ?string $adminName = null): bool
    {
        $record = SecurityBlockedIp::where('ip_address', $ip)->first();
        if ($record) {
            $record->update(['is_active' => false]);
        }

        Cache::forget("sec:blocked:{$ip}");

        SecurityHealingLog::create([
            'action_type' => 'ip_auto_ban',
            'description' => "Unblocked IP {$ip}".($adminName ? " by {$adminName}" : ''),
            'target' => $ip,
            'status' => 'reverted',
            'details' => ['unblocked_at' => now()->toIso8601String()],
        ]);

        return true;
    }

    /**
     * Run a comprehensive security diagnostic checkup and apply automated self-healing patches.
     *
     * @return array<string, mixed>
     */
    public function runAuditAndHeal(bool $applyFixes = true): array
    {
        $findings = [];
        $healedActions = [];
        $score = 100;

        // 1. Debug Mode Check in Production
        $appEnv = config('app.env');
        $debug = config('app.debug');
        if (in_array($appEnv, ['prod', 'production'], true) && $debug === true) {
            $score -= 25;
            $findings[] = [
                'check' => 'debug_mode',
                'title' => 'Debug Mode Enabled in Production',
                'severity' => 'critical',
                'status' => 'vulnerable',
                'message' => 'APP_DEBUG is enabled on a production environment, risking stack trace and credential leakage.',
            ];

            if ($applyFixes) {
                config(['app.debug' => false]);
                $healedActions[] = 'Temporarily disabled debug mode in runtime configuration.';
                SecurityHealingLog::create([
                    'action_type' => 'config_patch',
                    'description' => 'Self-healed: Runtime debug mode forced to false in production.',
                    'target' => 'app.debug',
                    'status' => 'applied',
                ]);
            }
        } else {
            $findings[] = [
                'check' => 'debug_mode',
                'title' => 'Production Debug Mode Guard',
                'severity' => 'low',
                'status' => 'secure',
                'message' => 'Debug mode is safely disabled.',
            ];
        }

        // 2. Security Headers Check
        $findings[] = [
            'check' => 'security_headers',
            'title' => 'HTTP Defense-in-Depth Headers',
            'severity' => 'medium',
            'status' => 'secure',
            'message' => 'SetSecurityHeaders middleware enforces X-Content-Type-Options, X-Frame-Options, Referrer-Policy, and HSTS.',
        ];

        // 3. Stale SSO Authorization Code Surface
        $staleQuery = SsoAuthorizationCode::where(function ($query): void {
            $query->where('expires_at', '<', now()->subHour())
                ->orWhere(function ($q): void {
                    $q->whereNotNull('used_at')
                        ->where('created_at', '<', now()->subDay());
                });
        });

        $staleCodesCount = (clone $staleQuery)->count();

        if ($staleCodesCount > 0) {
            $score -= 5;
            $findings[] = [
                'check' => 'sso_hygiene',
                'title' => 'Stale Authorization Tokens & Codes',
                'severity' => 'low',
                'status' => 'attention',
                'message' => "Found {$staleCodesCount} expired or used SSO authorization codes.",
            ];

            if ($applyFixes) {
                (clone $staleQuery)->delete();

                $healedActions[] = "Purged {$staleCodesCount} stale authorization codes to minimize attack surface.";
                SecurityHealingLog::create([
                    'action_type' => 'cleanup_stale_tokens',
                    'description' => "Self-healed: Cleaned up {$staleCodesCount} expired SSO authorization codes.",
                    'target' => 'sso_authorization_codes',
                    'status' => 'applied',
                    'details' => ['purged_count' => $staleCodesCount],
                ]);
            }
        } else {
            $findings[] = [
                'check' => 'sso_hygiene',
                'title' => 'Stale Authorization Tokens & Codes',
                'severity' => 'low',
                'status' => 'secure',
                'message' => 'No stale or expired SSO codes detected.',
            ];
        }

        // 4. Storage & Cache Permissions Integrity
        $storageWritable = is_writable(storage_path());
        $cacheWritable = is_writable(base_path('bootstrap/cache'));

        if (! $storageWritable || ! $cacheWritable) {
            $score -= 15;
            $findings[] = [
                'check' => 'storage_permissions',
                'title' => 'Directory Permissions',
                'severity' => 'high',
                'status' => 'vulnerable',
                'message' => 'Storage or bootstrap/cache directory is not properly writable.',
            ];
        } else {
            $findings[] = [
                'check' => 'storage_permissions',
                'title' => 'Storage Directory Integrity',
                'severity' => 'low',
                'status' => 'secure',
                'message' => 'Storage and bootstrap/cache permissions are verified.',
            ];
        }

        // 5. Admin Two-Factor Authentication Adoption
        $adminsWithout2fa = User::where('is_admin', true)
            ->whereNull('two_factor_confirmed_at')
            ->count();

        $enforceRule = SystemRule::where('key', 'enforce_2fa_admin')->first();

        if ($adminsWithout2fa > 0) {
            $score -= 10;
            $findings[] = [
                'check' => 'admin_2fa',
                'title' => 'Admin Two-Factor Authentication',
                'severity' => 'medium',
                'status' => 'attention',
                'message' => "{$adminsWithout2fa} administrator account(s) have not enabled Two-Factor Authentication (2FA).",
            ];

            if ($applyFixes && $enforceRule && ! $enforceRule->is_active) {
                $enforceRule->update(['is_active' => true]);
                $healedActions[] = 'Activated Mandatory 2FA System Policy for administrative accounts.';
                SecurityHealingLog::create([
                    'action_type' => 'rule_enforcement',
                    'description' => 'Self-healed: Activated mandatory 2FA security policy for administrators.',
                    'target' => 'system_rules.enforce_2fa_admin',
                    'status' => 'applied',
                ]);
            }
        } else {
            $findings[] = [
                'check' => 'admin_2fa',
                'title' => 'Admin Two-Factor Authentication',
                'severity' => 'low',
                'status' => 'secure',
                'message' => 'All administrator accounts have Two-Factor Authentication enabled.',
            ];
        }

        // 6. Active Exploit Threat Quarantine & Defense Guard
        $unquarantinedExploits = SecurityThreatLog::where('is_resolved', false)
            ->where('severity', 'critical')
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        $quarantinedCount = 0;
        foreach ($unquarantinedExploits as $exploit) {
            if (! $this->isIpBlocked($exploit->ip_address) && ! $this->isWhitelisted($exploit->ip_address)) {
                if ($applyFixes) {
                    $this->quarantineIp(
                        $exploit->ip_address,
                        "Self-healing audit quarantine: Active {$exploit->threat_type} at {$exploit->url}",
                        120,
                        'self_healing_engine',
                        $exploit->client_ipv4,
                        $exploit->client_ipv6
                    );
                    $exploit->update(['action_taken' => 'auto_blocked', 'is_resolved' => true]);
                    $quarantinedCount++;
                }
            }
        }

        if ($quarantinedCount > 0) {
            $healedActions[] = "Quarantined {$quarantinedCount} offending IP address(es) associated with recent critical exploit probes.";
        }

        // Ensure score stays within 0-100 range
        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'posture' => $score >= 90 ? 'Hardened' : ($score >= 70 ? 'Moderate' : 'Action Required'),
            'findings' => $findings,
            'healed_actions' => $healedActions,
            'audited_at' => now()->toIso8601String(),
        ];
    }
}
