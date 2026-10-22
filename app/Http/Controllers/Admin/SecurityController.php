<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SecurityBlockedIp;
use App\Models\SecurityHealingLog;
use App\Models\SecurityThreatLog;
use App\Models\SecurityWhitelistedIp;
use App\Models\SystemRule;
use App\Services\Security\SecuritySelfHealingService;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function __construct(
        protected SecuritySelfHealingService $selfHealingService
    ) {}

    /**
     * Display the Security Command Center dashboard.
     */
    public function index(Request $request): Response
    {
        // 1. High-level metric counts
        $totalThreats24h = SecurityThreatLog::where('created_at', '>=', now()->subDay())->count();
        $probes40424h = SecurityThreatLog::where('threat_type', '404_probe')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $ddosBlocks24h = SecurityThreatLog::where('threat_type', 'ddos_rate_limit')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $blockedIpsCount = SecurityBlockedIp::active()->count();
        $autoDefenseEnabled = (bool) SystemRule::getValue('security_auto_defense_enabled', true);

        // 2. Dynamic Timeline Chart Data (Last 24 hours in 2-hour buckets, ending in the current period)
        $timelineBuckets = [];
        $currentPeriodStart = now()->startOfHour();
        if ($currentPeriodStart->hour % 2 !== 0) {
            $currentPeriodStart->subHour();
        }

        $firstBucketStart = (clone $currentPeriodStart)->subHours(22);

        // Fetch logs across the full 24-hour window in a single query
        $recentLogs = SecurityThreatLog::where('created_at', '>=', $firstBucketStart)
            ->select(['id', 'threat_type', 'created_at'])
            ->get();

        for ($i = 0; $i < 12; $i++) {
            $bucketStart = (clone $firstBucketStart)->addHours($i * 2);
            $bucketEnd = (clone $bucketStart)->addHours(2);
            $label = $bucketStart->format('H:i');

            $bucketLogs = $recentLogs->filter(function ($log) use ($bucketStart, $bucketEnd) {
                return $log->created_at >= $bucketStart && $log->created_at < $bucketEnd;
            });

            $probes = $bucketLogs->where('threat_type', '404_probe')->count();
            $ddos = $bucketLogs->where('threat_type', 'ddos_rate_limit')->count();
            $exploits = $bucketLogs->whereNotIn('threat_type', ['404_probe', 'ddos_rate_limit'])->count();

            $timelineBuckets[] = [
                'timestamp' => $bucketStart->toIso8601String(),
                'label' => $label,
                'probes' => $probes,
                'ddos' => $ddos,
                'exploits' => $exploits,
                'total' => $probes + $ddos + $exploits,
            ];
        }

        // 3. Category Breakdown (Last 7 days)
        $categories = SecurityThreatLog::where('created_at', '>=', now()->subDays(7))
            ->select('threat_type', DB::raw('count(*) as count'))
            ->groupBy('threat_type')
            ->get()
            ->map(function ($item) {
                return [
                    'key' => $item->threat_type,
                    'name' => SecurityThreatLog::THREAT_TYPES[$item->threat_type] ?? ucfirst(str_replace('_', ' ', (string) $item->threat_type)),
                    'count' => (int) $item->count,
                ];
            });

        // 4. Top Probed URLs (Last 7 days)
        $topProbedUrls = SecurityThreatLog::where('created_at', '>=', now()->subDays(7))
            ->select('url', DB::raw('count(*) as count'), DB::raw('max(created_at) as last_seen'))
            ->groupBy('url')
            ->orderByDesc('count')
            ->take(6)
            ->get();

        // 5. Top Attacking Client IPs (aggregating by primary IP, client IPv4, and IPv6)
        $topOffendingIps = SecurityThreatLog::where('created_at', '>=', now()->subDays(7))
            ->select('ip_address', 'client_ipv4', 'client_ipv6', DB::raw('count(*) as count'), DB::raw('max(created_at) as last_seen'))
            ->groupBy('ip_address', 'client_ipv4', 'client_ipv6')
            ->orderByDesc('count')
            ->take(6)
            ->get()
            ->map(function ($item) {
                $isBlocked = $this->selfHealingService->isIpBlocked($item->ip_address)
                    || ($item->client_ipv4 && $this->selfHealingService->isIpBlocked($item->client_ipv4));

                return [
                    'ip_address' => $item->ip_address,
                    'client_ipv4' => $item->client_ipv4,
                    'client_ipv6' => $item->client_ipv6,
                    'count' => (int) $item->count,
                    'last_seen' => Carbon::parse($item->last_seen)->diffForHumans(),
                    'is_blocked' => $isBlocked,
                ];
            });

        // 6. Threat Logs Query with Filters
        $query = SecurityThreatLog::query()->with('user:id,name,email');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('ip_address', 'like', "%{$search}%")
                    ->orWhere('client_ipv4', 'like', "%{$search}%")
                    ->orWhere('client_ipv6', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('user_agent', 'like', "%{$search}%");
            });
        }

        if ($request->filled('threat_type')) {
            $query->where('threat_type', $request->input('threat_type'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->has('resolved')) {
            $query->where('is_resolved', filter_var($request->input('resolved'), FILTER_VALIDATE_BOOLEAN));
        }

        $threatLogs = $query->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        // 7. Active Quarantined IPs
        $blockedIps = SecurityBlockedIp::active()
            ->orderByDesc('created_at')
            ->take(20)
            ->get();

        // 8. Whitelisted IPs (Exempt from auto-quarantine)
        $whitelistedIps = SecurityWhitelistedIp::orderByDesc('created_at')->get();
        $whitelistedIpsCount = SecurityWhitelistedIp::where('is_active', true)->count();

        // 9. Recent Self-Healing Actions
        $recentHealingLogs = SecurityHealingLog::latest()
            ->take(8)
            ->get();

        // 10. Current Diagnostic Audit
        $diagnosticAudit = $this->selfHealingService->runAuditAndHeal(false);

        return Inertia::render('Admin/Security/Index', [
            'stats' => [
                'total_threats_24h' => $totalThreats24h,
                'probes_404_24h' => $probes40424h,
                'ddos_blocks_24h' => $ddosBlocks24h,
                'blocked_ips_count' => $blockedIpsCount,
                'whitelisted_ips_count' => $whitelistedIpsCount,
                'auto_defense_enabled' => $autoDefenseEnabled,
                'score' => $diagnosticAudit['score'],
                'posture' => $diagnosticAudit['posture'],
            ],
            'timeline' => $timelineBuckets,
            'categories' => $categories,
            'topProbedUrls' => $topProbedUrls,
            'topOffendingIps' => $topOffendingIps,
            'threatLogs' => $threatLogs,
            'blockedIps' => $blockedIps,
            'whitelistedIps' => $whitelistedIps,
            'recentHealingLogs' => $recentHealingLogs,
            'diagnosticFindings' => $diagnosticAudit['findings'],
            'filters' => $request->only(['search', 'threat_type', 'severity', 'resolved']),
        ]);
    }

    /**
     * Add an IP address or CIDR range to the whitelist.
     */
    public function whitelistIp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ip_address' => [
                'required',
                'string',
                'max:50',
                'unique:security_whitelisted_ips,ip_address',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $isValidIp = filter_var($value, FILTER_VALIDATE_IP) !== false;
                    $isValidCidr = false;

                    if (! $isValidIp && str_contains((string) $value, '/')) {
                        [$subnet, $mask] = explode('/', (string) $value, 2);
                        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $isValidCidr = is_numeric($mask) && (int) $mask >= 0 && (int) $mask <= 32;
                        } elseif (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                            $isValidCidr = is_numeric($mask) && (int) $mask >= 0 && (int) $mask <= 128;
                        }
                    }

                    if (! $isValidIp && ! $isValidCidr) {
                        $fail('The '.$attribute.' must be a valid IPv4 address, IPv6 address, or CIDR subnet (e.g. 192.168.1.100 or 10.0.0.0/24).');
                    }
                },
            ],
            'label' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $admin = $request->user();
        $whitelisted = $this->selfHealingService->addWhitelistedIp(
            $validated['ip_address'],
            $validated['label'],
            $validated['description'] ?? null,
            "admin:{$admin->name}"
        );

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'security_whitelist_ip',
            'target_type' => 'security_whitelisted_ip',
            'target_id' => $whitelisted->id,
            'details' => $validated,
        ]);

        return back()->with('success', "IP address {$validated['ip_address']} has been added to the whitelist and protected from automated quarantine.");
    }

    /**
     * Remove an IP address from the whitelist.
     */
    public function removeWhitelistIp(Request $request, SecurityWhitelistedIp $whitelistedIp): RedirectResponse
    {
        $ip = $whitelistedIp->ip_address;
        $whitelistedIp->delete();
        $this->selfHealingService->clearWhitelistCache();

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'security_remove_whitelist_ip',
            'target_type' => 'security_whitelisted_ip',
            'target_id' => $whitelistedIp->id,
            'details' => ['ip_address' => $ip],
        ]);

        return back()->with('success', "IP address {$ip} has been removed from the whitelist.");
    }

    /**
     * Toggle active state of a whitelisted IP address.
     */
    public function toggleWhitelistIp(Request $request, SecurityWhitelistedIp $whitelistedIp): RedirectResponse
    {
        $whitelistedIp->update(['is_active' => ! $whitelistedIp->is_active]);
        $this->selfHealingService->clearWhitelistCache();

        $status = $whitelistedIp->is_active ? 'activated' : 'deactivated';

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'security_toggle_whitelist_ip',
            'target_type' => 'security_whitelisted_ip',
            'target_id' => $whitelistedIp->id,
            'details' => [
                'ip_address' => $whitelistedIp->ip_address,
                'is_active' => $whitelistedIp->is_active,
            ],
        ]);

        return back()->with('success', "IP address {$whitelistedIp->ip_address} whitelist protection {$status}.");
    }

    /**
     * Manually quarantine an IP address.
     */
    public function blockIp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ip_address' => ['required', 'string', 'max:45'],
            'reason' => ['required', 'string', 'max:255'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($this->selfHealingService->isWhitelisted($validated['ip_address'])) {
            return back()->withErrors([
                'ip_address' => "IP address {$validated['ip_address']} is currently on the whitelist and protected from quarantine. Remove it from the whitelist first if you intend to quarantine it.",
            ]);
        }

        $admin = $request->user();
        $this->selfHealingService->quarantineIp(
            $validated['ip_address'],
            $validated['reason'],
            $validated['duration_minutes'] ?? null,
            "admin:{$admin->name}"
        );

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'security_block_ip',
            'target_type' => 'ip_address',
            'target_id' => null,
            'details' => $validated,
        ]);

        return back()->with('success', "IP address {$validated['ip_address']} has been quarantined.");
    }

    /**
     * Manually unblock an IP address.
     */
    public function unblockIp(Request $request, SecurityBlockedIp $blockedIp): RedirectResponse
    {
        $admin = $request->user();
        $this->selfHealingService->unblockIp($blockedIp->ip_address, $admin->name);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'security_unblock_ip',
            'target_type' => 'ip_address',
            'target_id' => $blockedIp->id,
            'details' => ['ip_address' => $blockedIp->ip_address],
        ]);

        return back()->with('success', "IP address {$blockedIp->ip_address} has been unblocked.");
    }

    /**
     * Mark a threat log as resolved.
     */
    public function resolveThreat(Request $request, SecurityThreatLog $threatLog): RedirectResponse
    {
        $threatLog->update(['is_resolved' => true]);

        return back()->with('success', 'Threat incident marked as resolved.');
    }

    /**
     * Run the automated self-healing audit and apply fixes.
     */
    public function runAuditAndHeal(Request $request): RedirectResponse
    {
        $results = $this->selfHealingService->runAuditAndHeal(true);
        $actionCount = count($results['healed_actions']);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'security_run_self_healing',
            'target_type' => 'system',
            'target_id' => null,
            'details' => [
                'score' => $results['score'],
                'actions_applied' => $results['healed_actions'],
            ],
        ]);

        $message = $actionCount > 0
            ? "Self-healing executed successfully: {$actionCount} security remediation(s) applied."
            : 'Security audit complete: System configuration is already optimal and hardened.';

        return back()->with('success', $message);
    }

    /**
     * Toggle the automated threat defense engine.
     */
    public function toggleAutoDefense(Request $request): RedirectResponse
    {
        $current = (bool) SystemRule::getValue('security_auto_defense_enabled', true);
        $newStatus = ! $current;

        SystemRule::updateOrCreate(
            ['key' => 'security_auto_defense_enabled'],
            [
                'name' => 'Automated Threat Defense & IP Quarantine',
                'category' => 'security',
                'description' => 'Automatically detects and quarantines malicious IP addresses exceeding threat velocity thresholds.',
                'rule_type' => 'boolean',
                'value' => $newStatus ? 'true' : 'false',
                'is_active' => true,
                'priority' => 10,
            ]
        );

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'security_toggle_auto_defense',
            'target_type' => 'system_rule',
            'target_id' => null,
            'details' => ['enabled' => $newStatus],
        ]);

        $statusText = $newStatus ? 'enabled' : 'disabled';

        return back()->with('success', "Automated defense engine has been {$statusText}.");
    }

    /**
     * Prune old resolved threat logs.
     */
    public function clearOldLogs(Request $request): RedirectResponse
    {
        $deleted = SecurityThreatLog::where('is_resolved', true)
            ->where('created_at', '<', now()->subDays(14))
            ->delete();

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'security_prune_logs',
            'target_type' => 'security_threat_logs',
            'target_id' => null,
            'details' => ['deleted_count' => $deleted],
        ]);

        return back()->with('success', "Pruned {$deleted} historical resolved threat log(s).");
    }
}
