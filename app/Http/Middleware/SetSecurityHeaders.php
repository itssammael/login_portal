<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetSecurityHeaders
{
    /**
     * Handle an incoming request and attach essential defense-in-depth security headers.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking on normal portal pages while allowing embedded feedback forms with explicit frame-ancestors CSP
        $csp = (string) $response->headers->get('Content-Security-Policy');
        if (! $response->headers->has('X-Frame-Options') && ! str_contains($csp, 'frame-ancestors')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        // Enforce secure referrer policy
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        // Restrict sensitive browser features by default
        if (! $response->headers->has('Permissions-Policy')) {
            $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        }

        // Enforce Cross-Origin-Opener-Policy to protect against window/opener manipulation while permitting SSO popups
        if (! $response->headers->has('Cross-Origin-Opener-Policy')) {
            $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        }

        // Enforce HTTP Strict Transport Security (HSTS) when accessed over secure tunnel / HTTPS
        if (
            $request->isSecure() && ! $response->headers->has('Strict-Transport-Security')
        ) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
