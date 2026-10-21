<?php

namespace App\Services\Security;

use Illuminate\Http\Request;

class ClientIpResolver
{
    /**
     * Resolve the client's public IPv4 address from request headers or environment.
     */
    public static function resolvePublicIpv4(Request $request): ?string
    {
        // 1. Direct Cloudflare Pseudo-IPv4 header
        $cfPseudo = $request->header('CF-Pseudo-IPv4');
        if ($cfPseudo && self::isPublicIpv4($cfPseudo)) {
            return $cfPseudo;
        }

        // 2. Check original X-Forwarded-For preserved by TrustCloudflareTunnel
        $originalXff = (string) $request->attributes->get('cf_raw_forwarded_for');
        if (! empty($originalXff)) {
            $candidate = self::findFirstPublicIpv4InList($originalXff);
            if ($candidate) {
                return $candidate;
            }
        }

        // 3. Scan proxy and client headers in order of precedence
        $headersToCheck = [
            'CF-Connecting-IPv4',
            'True-Client-IP',
            'X-Real-IP',
            'X-Client-IP',
            'Client-IP',
            'X-Forwarded-For',
            'CF-Connecting-IP',
            'Forwarded',
        ];

        foreach ($headersToCheck as $headerName) {
            $headerVal = $request->header($headerName);
            if (! $headerVal) {
                continue;
            }

            $candidate = self::findFirstPublicIpv4InList((string) $headerVal);
            if ($candidate) {
                return $candidate;
            }
        }

        // 4. Check server REMOTE_ADDR
        $remoteAddr = (string) $request->server->get('REMOTE_ADDR');
        if (self::isPublicIpv4($remoteAddr)) {
            return $remoteAddr;
        }

        // 5. Check request standard IP
        $reqIp = (string) $request->ip();
        if (self::isPublicIpv4($reqIp)) {
            return $reqIp;
        }

        // 6. Check for embedded IPv4 in IPv6
        $ipv6 = self::resolveIpv6($request);
        if ($ipv6) {
            $embedded = self::extractEmbeddedIpv4($ipv6);
            if ($embedded && self::isPublicIpv4($embedded)) {
                return $embedded;
            }
        }

        // 7. Fallback for Localhost / LAN testing if no public IPv4 is present
        if (! empty($originalXff)) {
            $fallback = self::findFirstIpv4InList($originalXff);
            if ($fallback) {
                return $fallback;
            }
        }

        foreach ($headersToCheck as $headerName) {
            $headerVal = $request->header($headerName);
            if (! $headerVal) {
                continue;
            }

            $fallback = self::findFirstIpv4InList((string) $headerVal);
            if ($fallback) {
                return $fallback;
            }
        }

        if (self::isValidIpv4($remoteAddr)) {
            return $remoteAddr;
        }

        if (self::isValidIpv4($reqIp)) {
            return $reqIp;
        }

        return null;
    }

    /**
     * Resolve the client's IPv6 address if present.
     */
    public static function resolveIpv6(Request $request): ?string
    {
        $cfIp = $request->header('CF-Connecting-IP');
        if ($cfIp && filter_var($cfIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $cfIp;
        }

        $remote = (string) $request->server->get('REMOTE_ADDR');
        if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $remote;
        }

        $reqIp = (string) $request->ip();
        if (filter_var($reqIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $reqIp;
        }

        return null;
    }

    /**
     * Resolve IP set: primary, client_ipv4, client_ipv6.
     *
     * @return array{primary: string, client_ipv4: ?string, client_ipv6: ?string}
     */
    public static function resolve(Request $request): array
    {
        $ipv4 = self::resolvePublicIpv4($request);
        $ipv6 = self::resolveIpv6($request);

        // Prioritize client public IPv4 as primary identifier if available
        $primary = $ipv4 ?: ($ipv6 ?: ($request->ip() ?: '127.0.0.1'));

        return [
            'primary' => $primary,
            'client_ipv4' => $ipv4,
            'client_ipv6' => $ipv6,
        ];
    }

    /**
     * Check whether an IP string is a valid public IPv4 address.
     */
    public static function isPublicIpv4(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Check whether an IP string is any valid IPv4 address.
     */
    public static function isValidIpv4(?string $ip): bool
    {
        if (! $ip) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Find first valid public IPv4 address in a comma-separated or space-separated list.
     */
    protected static function findFirstPublicIpv4InList(string $list): ?string
    {
        $parts = preg_split('/[\s,]+/', $list);
        if (! is_array($parts)) {
            return null;
        }

        foreach ($parts as $part) {
            $clean = trim($part);
            if (self::isPublicIpv4($clean)) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * Find first valid IPv4 address in a list (including private/local).
     */
    protected static function findFirstIpv4InList(string $list): ?string
    {
        $parts = preg_split('/[\s,]+/', $list);
        if (! is_array($parts)) {
            return null;
        }

        foreach ($parts as $part) {
            $clean = trim($part);
            if (self::isValidIpv4($clean)) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * Extract embedded IPv4 from transition mechanisms (6to4, NAT64, IPv4-mapped IPv6).
     */
    protected static function extractEmbeddedIpv4(string $ipv6): ?string
    {
        // 1. IPv4-mapped IPv6 (::ffff:192.0.2.1)
        if (preg_match('/::ffff:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/i', $ipv6, $m)) {
            return $m[1];
        }

        // 2. NAT64 Well-Known Prefix (64:ff9b::192.0.2.1)
        if (preg_match('/64:ff9b::(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/i', $ipv6, $m)) {
            return $m[1];
        }

        // 3. 6to4 Prefix (2002:aabb:ccdd::)
        if (str_starts_with(strtolower($ipv6), '2002:')) {
            $parts = explode(':', $ipv6);
            if (count($parts) >= 3 && strlen($parts[1]) === 4 && strlen($parts[2]) === 4) {
                $hex = $parts[1].$parts[2];
                $d1 = hexdec(substr($hex, 0, 2));
                $d2 = hexdec(substr($hex, 2, 2));
                $d3 = hexdec(substr($hex, 4, 2));
                $d4 = hexdec(substr($hex, 6, 2));
                $candidate = "{$d1}.{$d2}.{$d3}.{$d4}";
                if (self::isValidIpv4($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
