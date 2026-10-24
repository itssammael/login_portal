<?php

namespace App\Services\Security;

use App\Models\SecurityBlockedIp;
use App\Models\SecurityHealingLog;
use App\Models\SecurityThreatLog;
use App\Models\SecurityWhitelistedIp;
use App\Models\SsoAuthorizationCode;
use App\Models\SystemRule;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

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
     * Known PHP core runtime CVE catalog with minimum safe patched releases.
     * Future CVEs can also be registered dynamically via SystemRule 'security_cve_catalog'.
     */
    public const PHP_CVE_CATALOG = [
        'CVE-2026-109618' => [
            'cve' => 'CVE-2026-109618 / CVE-2026-109619',
            'name' => 'PHP2Shell (Out-of-Memory Corruption & Stale Global State RCE)',
            'severity' => 'critical',
            'min_safe_versions' => [
                '8.2' => '8.2.35',
                '8.3' => '8.3.36',
                '8.4' => '8.4.27',
                '8.5' => '8.5.12',
            ],
            'description' => 'Critical core runtime RCE via longjmp() state desynchronization and stale global memory references.',
            'remediation' => 'Upgrade PHP runtime binary to >= 8.3.36 (or respective branch patch) and disable session.upload_progress.enabled.',
        ],
        'CVE-2024-4577' => [
            'cve' => 'CVE-2024-4577',
            'name' => 'PHP-CGI Windows Argument Injection (Best-Fit Character Encoding RCE)',
            'severity' => 'critical',
            'windows_only' => true,
            'min_safe_versions' => [
                '8.1' => '8.1.29',
                '8.2' => '8.2.20',
                '8.3' => '8.3.8',
            ],
            'description' => 'Command injection vulnerability on Windows PHP due to Unicode Best-Fit conversion bypass.',
            'remediation' => 'Ensure running patched PHP release and block command-line argument injection in query strings.',
        ],
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
        if (in_array($ip, self::DEFAULT_WHITELIST, true)) {
            return true;
        }

        $whitelist = Cache::remember('sec:whitelist_ips', 60, function (): array {
            $dbIps = SecurityWhitelistedIp::where('is_active', true)
                ->pluck('ip_address')
                ->all();

            $ruleIps = SystemRule::getValue('security_ip_whitelist', []);
            if (is_string($ruleIps)) {
                $ruleIps = array_filter(array_map('trim', explode(',', $ruleIps)));
            }

            return array_values(array_unique(array_merge(self::DEFAULT_WHITELIST, $dbIps, (array) $ruleIps)));
        });

        if (in_array($ip, $whitelist, true)) {
            return true;
        }

        try {
            return IpUtils::checkIp($ip, $whitelist);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Invalidate cached whitelist IP entries.
     */
    public function clearWhitelistCache(): void
    {
        Cache::forget('sec:whitelist_ips');
    }

    /**
     * Add an IP to the whitelist and automatically lift any active quarantine.
     */
    public function addWhitelistedIp(
        string $ip,
        string $label,
        ?string $description = null,
        string $addedBy = 'system'
    ): SecurityWhitelistedIp {
        $whitelisted = SecurityWhitelistedIp::updateOrCreate(
            ['ip_address' => $ip],
            [
                'label' => $label,
                'description' => $description,
                'added_by' => $addedBy,
                'is_active' => true,
            ]
        );

        $this->clearWhitelistCache();

        // Release any existing quarantine on this IP
        $blocked = SecurityBlockedIp::where('ip_address', $ip)
            ->orWhere('client_ipv4', $ip)
            ->orWhere('client_ipv6', $ip)
            ->get();

        foreach ($blocked as $blockedRecord) {
            if ($blockedRecord->is_active) {
                $blockedRecord->update(['is_active' => false]);
                Cache::forget("sec:blocked:{$blockedRecord->ip_address}");
                if ($blockedRecord->client_ipv4) {
                    Cache::forget("sec:blocked:{$blockedRecord->client_ipv4}");
                }
                if ($blockedRecord->client_ipv6) {
                    Cache::forget("sec:blocked:{$blockedRecord->client_ipv6}");
                }

                SecurityHealingLog::create([
                    'action_type' => 'whitelist_quarantine_release',
                    'description' => "Quarantine released for IP {$blockedRecord->ip_address} upon addition to trusted whitelist by {$addedBy}.",
                    'target' => $blockedRecord->ip_address,
                    'status' => 'applied',
                    'details' => [
                        'ip_address' => $blockedRecord->ip_address,
                        'whitelisted_ip' => $ip,
                        'added_by' => $addedBy,
                    ],
                ]);
            }
        }

        return $whitelisted;
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
        $clientIpv4 = $threat->client_ipv4;
        $clientIpv6 = $threat->client_ipv6;

        // Never auto-quarantine whitelisted IPs
        if ($this->isWhitelisted($ip)
            || ($clientIpv4 && $this->isWhitelisted($clientIpv4))
            || ($clientIpv6 && $this->isWhitelisted($clientIpv6))
        ) {
            $threat->update(['action_taken' => 'whitelisted_allowed']);

            return;
        }

        // Prevent duplicate quarantine records if already actively blocked
        if ($this->isIpBlocked($ip) || ($clientIpv4 && $this->isIpBlocked($clientIpv4))) {
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
        if ($this->isWhitelisted($ip)
            || ($clientIpv4 && $this->isWhitelisted($clientIpv4))
            || ($clientIpv6 && $this->isWhitelisted($clientIpv6))
        ) {
            // Whitelisted IP address cannot be placed into quarantine
            return SecurityBlockedIp::firstOrNew(
                ['ip_address' => $ip],
                [
                    'reason' => $reason,
                    'is_active' => false,
                    'blocked_by' => $blockedBy,
                ]
            );
        }

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
            $isWhitelisted = $this->isWhitelisted($exploit->ip_address)
                || ($exploit->client_ipv4 && $this->isWhitelisted($exploit->client_ipv4))
                || ($exploit->client_ipv6 && $this->isWhitelisted($exploit->client_ipv6));

            if (! $this->isIpBlocked($exploit->ip_address) && ! $isWhitelisted) {
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

        // 7. Trusted IP Whitelist Armor Check
        $whitelistedCount = SecurityWhitelistedIp::where('is_active', true)->count();
        $findings[] = [
            'check' => 'ip_whitelisting',
            'title' => 'Trusted IP Whitelist Armor',
            'severity' => 'low',
            'status' => 'secure',
            'message' => "{$whitelistedCount} trusted IP address(es)/subnet(s) actively exempted from automated quarantine and velocity blocks.",
        ];

        // 8. PHP Core Runtime & CVE Security Audit (PHP2Shell, CGI Argument Injection, Runtime Hardening)
        $phpAudit = $this->auditPhpRuntimeSecurity($applyFixes);
        $score -= $phpAudit['score_penalty'];
        foreach ($phpAudit['findings'] as $phpFinding) {
            $findings[] = $phpFinding;
        }
        foreach ($phpAudit['healed_actions'] as $phpHealedAction) {
            $healedActions[] = $phpHealedAction;
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

    /**
     * Retrieve the active PHP CVE catalog combining default catalog with dynamic SystemRule entries.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPhpCveCatalog(): array
    {
        $dynamicCatalog = SystemRule::getValue('security_cve_catalog', []);
        if (is_string($dynamicCatalog)) {
            $decoded = json_decode($dynamicCatalog, true);
            $dynamicCatalog = is_array($decoded) ? $decoded : [];
        }

        return array_merge(self::PHP_CVE_CATALOG, (array) $dynamicCatalog);
    }

    /**
     * Audit PHP runtime security against known CVEs and insecure directives.
     *
     * @return array{findings: array<int, array<string, mixed>>, healed_actions: array<int, string>, score_penalty: int, is_vulnerable: bool}
     */
    public function auditPhpRuntimeSecurity(bool $applyFixes = true): array
    {
        $findings = [];
        $healedActions = [];
        $scorePenalty = 0;
        $isVulnerable = false;

        $currentPhpVersion = PHP_VERSION;
        $branch = implode('.', array_slice(explode('.', $currentPhpVersion), 0, 2));
        $catalog = $this->getPhpCveCatalog();
        $isWindows = PHP_OS_FAMILY === 'Windows';

        $detectedVulnerabilities = [];

        foreach ($catalog as $cveId => $cveInfo) {
            if (! empty($cveInfo['windows_only']) && ! $isWindows) {
                continue;
            }

            $minSafeVersions = $cveInfo['min_safe_versions'] ?? [];
            if (isset($minSafeVersions[$branch])) {
                $minSafe = $minSafeVersions[$branch];
                if (version_compare($currentPhpVersion, $minSafe, '<')) {
                    $isVulnerable = true;
                    $detectedVulnerabilities[] = [
                        'cve' => $cveInfo['cve'],
                        'name' => $cveInfo['name'],
                        'min_safe' => $minSafe,
                        'remediation' => $cveInfo['remediation'],
                    ];
                }
            } elseif (version_compare($branch, '8.2', '<')) {
                // EOL PHP branches (<= 8.1) are unpatched against newer core CVEs
                $isVulnerable = true;
                $detectedVulnerabilities[] = [
                    'cve' => $cveInfo['cve'],
                    'name' => $cveInfo['name'].' (EOL PHP Branch)',
                    'min_safe' => '8.2.35+ / 8.3.36+',
                    'remediation' => 'PHP '.PHP_VERSION.' is End-Of-Life and unpatched. Upgrade to a supported PHP release.',
                ];
            }
        }

        // 1. Core PHP Version Vulnerability Finding
        if ($isVulnerable) {
            $scorePenalty += 25;
            $vulnSummary = implode('; ', array_map(fn ($v) => "{$v['name']} [{$v['cve']}] (requires {$v['min_safe']})", $detectedVulnerabilities));
            $findings[] = [
                'check' => 'php_runtime_cve',
                'title' => 'PHP Runtime Core Vulnerabilities Detected',
                'severity' => 'critical',
                'status' => 'vulnerable',
                'message' => "Installed PHP {$currentPhpVersion} is affected by: {$vulnSummary}. Automated defense software mitigations are active, but upgrading the PHP binary is required.",
                'details' => [
                    'php_version' => $currentPhpVersion,
                    'vulnerabilities' => $detectedVulnerabilities,
                ],
            ];
        } else {
            $findings[] = [
                'check' => 'php_runtime_cve',
                'title' => 'PHP Core Runtime Patch Status',
                'severity' => 'low',
                'status' => 'secure',
                'message' => "Installed PHP {$currentPhpVersion} meets safe release baselines against known PHP core CVEs.",
            ];
        }

        // Proactive runtime defense mitigation when vulnerable
        if ($isVulnerable && $applyFixes) {
            $healedActions[] = 'Activated automated defense filter rules intercepting PHP2Shell upload progress and CGI injection vectors.';
            SecurityHealingLog::create([
                'action_type' => 'php_runtime_hardening',
                'description' => "Self-healed: Activated real-time automated defense rules mitigating PHP2Shell and CGI exploit vectors for PHP {$currentPhpVersion}.",
                'target' => 'php_automated_defense',
                'status' => 'applied',
                'details' => [
                    'php_version' => $currentPhpVersion,
                    'active_guards' => [
                        'php2shell_upload_progress_filter',
                        'stream_wrapper_injection_filter',
                        'windows_cgi_argument_filter',
                        'header_memory_exhaustion_filter',
                        'x_powered_by_suppression',
                    ],
                ],
            ]);
        }

        // 2. session.upload_progress.enabled Directive Guard
        $uploadProgressEnabled = $this->isIniEnabled('session.upload_progress.enabled');
        if ($uploadProgressEnabled) {
            $status = $isVulnerable ? 'vulnerable' : 'attention';
            $severity = $isVulnerable ? 'high' : 'medium';
            if ($isVulnerable) {
                $scorePenalty += 10;
            }

            $findings[] = [
                'check' => 'php_upload_progress',
                'title' => 'PHP Session Upload Progress Guard',
                'severity' => $severity,
                'status' => $status,
                'message' => 'session.upload_progress.enabled is currently active. This is the primary pre-authentication trigger surface for the PHP2Shell (CVE-2026-109618/19) exploit.',
            ];

            if ($applyFixes) {
                @ini_set('session.upload_progress.enabled', '0');
                $healedActions[] = 'Self-healed: Disabled session.upload_progress.enabled in runtime memory.';
                SecurityHealingLog::create([
                    'action_type' => 'php_runtime_hardening',
                    'description' => 'Self-healed: Runtime session.upload_progress.enabled forced to 0 to mitigate PHP2Shell attack vector.',
                    'target' => 'ini:session.upload_progress.enabled',
                    'status' => 'applied',
                    'details' => ['php_version' => $currentPhpVersion],
                ]);
            }
        } else {
            $findings[] = [
                'check' => 'php_upload_progress',
                'title' => 'PHP Session Upload Progress Guard',
                'severity' => 'low',
                'status' => 'secure',
                'message' => 'session.upload_progress.enabled is safely disabled, neutralizing the PHP2Shell upload progress attack surface.',
            ];
        }

        // 3. expose_php Directive Guard
        $exposePhp = (bool) ini_get('expose_php');
        if ($exposePhp) {
            $findings[] = [
                'check' => 'php_expose_php',
                'title' => 'PHP Version Disclosure Header',
                'severity' => 'medium',
                'status' => 'attention',
                'message' => 'expose_php is enabled, leaking exact PHP version in HTTP headers and facilitating version-targeted exploit reconnaissance.',
            ];

            if ($applyFixes) {
                @ini_set('expose_php', '0');
                $healedActions[] = 'Self-healed: Suppressed expose_php in runtime configuration.';
                SecurityHealingLog::create([
                    'action_type' => 'php_runtime_hardening',
                    'description' => 'Self-healed: Runtime expose_php disabled to prevent server version disclosure.',
                    'target' => 'ini:expose_php',
                    'status' => 'applied',
                ]);
            }
        } else {
            $findings[] = [
                'check' => 'php_expose_php',
                'title' => 'PHP Version Disclosure Guard',
                'severity' => 'low',
                'status' => 'secure',
                'message' => 'expose_php is disabled; server response headers do not leak PHP version info.',
            ];
        }

        // 4. allow_url_include Directive Guard
        $allowUrlInclude = (bool) ini_get('allow_url_include');
        if ($allowUrlInclude) {
            $scorePenalty += 20;
            $findings[] = [
                'check' => 'php_allow_url_include',
                'title' => 'Remote File Inclusion (allow_url_include)',
                'severity' => 'critical',
                'status' => 'vulnerable',
                'message' => 'allow_url_include is enabled, creating high risk for Remote File Inclusion (RFI) attacks.',
            ];

            if ($applyFixes) {
                @ini_set('allow_url_include', '0');
                $healedActions[] = 'Self-healed: Force-disabled allow_url_include in runtime configuration.';
                SecurityHealingLog::create([
                    'action_type' => 'php_runtime_hardening',
                    'description' => 'Self-healed: Disabled allow_url_include to prevent remote file inclusion.',
                    'target' => 'ini:allow_url_include',
                    'status' => 'applied',
                ]);
            }
        } else {
            $findings[] = [
                'check' => 'php_allow_url_include',
                'title' => 'Remote File Inclusion Guard',
                'severity' => 'low',
                'status' => 'secure',
                'message' => 'allow_url_include is disabled.',
            ];
        }

        // 5. Automatic Server Configuration File Hardening (php.ini & phpForApache.ini)
        if ($applyFixes) {
            $patchedFiles = $this->hardenPhpConfigurationFiles();
            foreach ($patchedFiles as $file) {
                $healedActions[] = "Hardened PHP configuration file ({$file}): disabled session.upload_progress.enabled and expose_php.";
                SecurityHealingLog::create([
                    'action_type' => 'php_ini_hardening',
                    'description' => "Self-healed: Applied persistent security hardening to {$file}.",
                    'target' => $file,
                    'status' => 'applied',
                    'details' => [
                        'file' => $file,
                        'directives_hardened' => [
                            'session.upload_progress.enabled' => 'Off',
                            'expose_php' => 'Off',
                        ],
                    ],
                ]);
            }
        }

        // 6. Quarantine Recent PHP Exploit Probers
        $phpExploits = SecurityThreatLog::where('is_resolved', false)
            ->whereIn('threat_type', ['php_runtime_exploit_probe', 'php_cgi_argument_injection'])
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        $quarantinedPhpAttackers = 0;
        foreach ($phpExploits as $exploit) {
            $isWhitelisted = $this->isWhitelisted($exploit->ip_address)
                || ($exploit->client_ipv4 && $this->isWhitelisted($exploit->client_ipv4))
                || ($exploit->client_ipv6 && $this->isWhitelisted($exploit->client_ipv6));

            if (! $this->isIpBlocked($exploit->ip_address) && ! $isWhitelisted) {
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
                    $quarantinedPhpAttackers++;
                }
            }
        }

        if ($quarantinedPhpAttackers > 0) {
            $healedActions[] = "Quarantined {$quarantinedPhpAttackers} IP address(es) probing PHP runtime vulnerabilities.";
        }

        return [
            'findings' => $findings,
            'healed_actions' => $healedActions,
            'score_penalty' => $scorePenalty,
            'is_vulnerable' => $isVulnerable,
        ];
    }

    /**
     * Locate and harden server PHP configuration files (php.ini, phpForApache.ini).
     *
     * @return array<int, string> List of successfully hardened configuration file paths.
     */
    public function hardenPhpConfigurationFiles(?string $customFile = null): array
    {
        $modifiedFiles = [];
        $candidateFiles = [];

        if ($customFile !== null) {
            $candidateFiles[] = $customFile;
        } else {
            $loadedIni = php_ini_loaded_file();
            if ($loadedIni && is_file($loadedIni) && is_writable($loadedIni)) {
                $candidateFiles[] = $loadedIni;
            }

            if ($loadedIni) {
                $dir = dirname($loadedIni);
                $siblingApacheIni = $dir.DIRECTORY_SEPARATOR.'phpForApache.ini';
                if (is_file($siblingApacheIni) && is_writable($siblingApacheIni) && ! in_array($siblingApacheIni, $candidateFiles, true)) {
                    $candidateFiles[] = $siblingApacheIni;
                }
            }
        }

        foreach ($candidateFiles as $file) {
            try {
                $contents = file_get_contents($file);
                if ($contents === false) {
                    continue;
                }

                $original = $contents;

                // 1. Harden expose_php = Off
                $contents = preg_replace('/^\s*;?\s*expose_php\s*=\s*(?:On|1)/mi', 'expose_php = Off', $contents);

                // 2. Harden session.upload_progress.enabled = Off
                $contents = preg_replace('/^\s*;?\s*session\.upload_progress\.enabled\s*=\s*(?:On|1)/mi', 'session.upload_progress.enabled = Off', $contents);

                // If session.upload_progress.enabled was not present at all, append it
                if (! preg_match('/^\s*session\.upload_progress\.enabled\s*=/mi', $contents)) {
                    $contents .= "\n; Hardened by Self-Healing Engine\nsession.upload_progress.enabled = Off\n";
                }

                if ($contents !== $original) {
                    file_put_contents($file, $contents);
                    $modifiedFiles[] = $file;
                }
            } catch (\Throwable) {
                // If permission issues occur or file is locked, fail gracefully
            }
        }

        return $modifiedFiles;
    }

    /**
     * Check if a PHP ini directive is enabled/truthy.
     */
    public function isIniEnabled(string $directive): bool
    {
        $val = strtolower((string) ini_get($directive));

        return in_array($val, ['1', 'on', 'true', 'yes'], true);
    }
}
