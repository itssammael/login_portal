<?php

namespace App\Http\Middleware;

use App\Services\Security\ClientIpResolver;
use App\Services\Security\SecuritySelfHealingService;
use App\Services\Security\ThreatDetectorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityThreatDetection
{
    public function __construct(
        protected SecuritySelfHealingService $selfHealingService,
        protected ThreatDetectorService $threatDetector
    ) {}

    /**
     * Intercept and evaluate incoming request for malicious traffic and active quarantine.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ips = ClientIpResolver::resolve($request);
        $primaryIp = $ips['primary'];

        // 1. Check if Client IP (primary, IPv4, or IPv6) is actively quarantined
        $isBlocked = $this->selfHealingService->isIpBlocked($primaryIp)
            || ($ips['client_ipv4'] && $this->selfHealingService->isIpBlocked($ips['client_ipv4']))
            || ($ips['client_ipv6'] && $this->selfHealingService->isIpBlocked($ips['client_ipv6']));

        if ($isBlocked) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Access Denied: Your IP address has been quarantined by the automated defense system.',
                    'status' => 'quarantined',
                ], 403);
            }

            abort(403, 'Access Denied: Your IP address has been quarantined by the automated defense system.');
        }

        // 2. Real-time Signature & Exploit Inspection
        $detection = $this->threatDetector->inspectRequest($request);
        if ($detection !== null && $detection['detected']) {
            $isWhitelisted = $this->selfHealingService->isWhitelisted($primaryIp)
                || ($ips['client_ipv4'] && $this->selfHealingService->isWhitelisted($ips['client_ipv4']))
                || ($ips['client_ipv6'] && $this->selfHealingService->isWhitelisted($ips['client_ipv6']));

            if ($isWhitelisted) {
                $this->threatDetector->logThreat([
                    'ip_address' => $primaryIp,
                    'client_ipv4' => $ips['client_ipv4'],
                    'client_ipv6' => $ips['client_ipv6'],
                    'user_id' => $request->user()?->id,
                    'threat_type' => $detection['type'],
                    'severity' => $detection['severity'],
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'user_agent' => $request->userAgent(),
                    'payload' => [
                        'description' => $detection['description'].' [Whitelisted Exemption]',
                        'client_ipv4' => $ips['client_ipv4'],
                        'client_ipv6' => $ips['client_ipv6'],
                        'query' => $request->query(),
                        'path' => $request->path(),
                    ],
                    'action_taken' => 'whitelisted_allowed',
                ]);

                return $next($request);
            }

            $this->threatDetector->logThreat([
                'ip_address' => $primaryIp,
                'client_ipv4' => $ips['client_ipv4'],
                'client_ipv6' => $ips['client_ipv6'],
                'user_id' => $request->user()?->id,
                'threat_type' => $detection['type'],
                'severity' => $detection['severity'],
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'user_agent' => $request->userAgent(),
                'payload' => [
                    'description' => $detection['description'],
                    'client_ipv4' => $ips['client_ipv4'],
                    'client_ipv6' => $ips['client_ipv6'],
                    'query' => $request->query(),
                    'path' => $request->path(),
                ],
                'action_taken' => 'auto_blocked',
            ]);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Forbidden: Malicious activity signature intercepted.',
                    'status' => 'blocked',
                ], 403);
            }

            abort(403, 'Forbidden: Malicious activity signature intercepted.');
        }

        return $next($request);
    }
}
