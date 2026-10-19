<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class TrustCloudflareTunnel
{
    /**
     * Handle an incoming request.
     *
     * Detects Cloudflare Tunnel / Reverse Proxy headers, normalizes real client IP
     * to prevent rate-limiting and audit log bypasses, and enforces HTTPS scheme.
     * Strictly verifies that Cloudflare headers originate from the local tunnel agent
     * or trusted proxies to prevent intranet header injection.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $rawRemote = (string) $request->server->get('REMOTE_ADDR');
        $trustedTunnelProxies = array_filter(array_map('trim', explode(',', (string) env('CLOUDFLARE_TUNNEL_PROXIES', '127.0.0.1,::1'))));

        $isFromTunnelProxy = in_array($rawRemote, $trustedTunnelProxies, true)
            || in_array('*', $trustedTunnelProxies, true);
        $hasCfHeaders = $request->headers->has('CF-Ray') || $request->headers->has('CF-Visitor');

        // 1. Only trust CF-Connecting-IP if request came through the verified tunnel agent
        if ($isFromTunnelProxy && $hasCfHeaders) {
            $cfIp = $request->header('CF-Connecting-IP');
            if ($cfIp && filter_var($cfIp, FILTER_VALIDATE_IP)) {
                $request->server->set('REMOTE_ADDR', $cfIp);
                $request->headers->set('X-Forwarded-For', $cfIp);
            }
        }

        // 2. Detect HTTPS scheme from verified Cloudflare CF-Visitor or trusted X-Forwarded-Proto
        $cfVisitor = (string) $request->header('CF-Visitor');
        $isCfHttps = $isFromTunnelProxy && str_contains($cfVisitor, '"scheme":"https"');
        $isForwardedHttps = $isFromTunnelProxy && strtolower((string) $request->header('X-Forwarded-Proto')) === 'https';

        if ($isCfHttps || $isForwardedHttps) {
            $request->server->set('HTTPS', 'on');
            $request->server->set('SERVER_PORT', 443);
            $request->headers->set('X-Forwarded-Proto', 'https');
            $request->headers->set('X-Forwarded-Port', '443');
            URL::forceScheme('https');
        } else {
            URL::forceScheme(null);
        }

        return $next($request);
    }
}
