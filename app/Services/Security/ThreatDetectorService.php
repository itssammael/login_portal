<?php

namespace App\Services\Security;

use App\Models\SecurityThreatLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ThreatDetectorService
{
    /**
     * Common sensitive / malicious path probe signatures.
     */
    protected const PROBE_PATHS = [
        '.env',
        '.env.backup',
        '.env.save',
        '.env.production',
        '.git',
        '.git/config',
        '.git/head',
        '.svn',
        'wp-admin',
        'wp-login.php',
        'xmlrpc.php',
        'wp-includes',
        'phpmyadmin',
        'pma',
        'adminer.php',
        'eval-stdin.php',
        'shell.php',
        'alfa.php',
        'cmd.php',
        'c99.php',
        'wso.php',
        '.aws/credentials',
        '.ssh/id_rsa',
        'web.config',
        'actuator/env',
        'actuator/gateway',
        'solr/',
        'telescope/requests',
        'config/database.php',
        'phpinfo.php',
        'setup.php',
        'install.php',
        'php-cgi',
        'php-cgi.exe',
        'cgi-bin/php',
    ];

    /**
     * User agents representing automated exploit and scanning tools.
     */
    protected const SCANNER_USER_AGENTS = [
        'sqlmap',
        'nikto',
        'nuclei',
        'acunetix',
        'nessus',
        'masscan',
        'zgrab',
        'gobuster',
        'dirbuster',
        'wpscan',
        'nmap',
        'hydra',
    ];

    public function __construct(
        protected SecuritySelfHealingService $selfHealingService
    ) {}

    /**
     * Inspect an incoming request for obvious injection, traversal, or scanner signatures.
     *
     * @return array{detected: bool, type: ?string, severity: ?string, description: ?string}|null
     */
    public function inspectRequest(Request $request): ?array
    {
        $uri = strtolower($request->getRequestUri());
        $userAgent = strtolower((string) $request->userAgent());
        $queryString = strtolower((string) $request->getQueryString());

        // 1. Scanner User Agent Detection
        foreach (self::SCANNER_USER_AGENTS as $scanner) {
            if (str_contains($userAgent, $scanner)) {
                return [
                    'detected' => true,
                    'type' => 'malicious_scanner',
                    'severity' => 'high',
                    'description' => "Automated security scanner detected: {$scanner}",
                ];
            }
        }

        // 2. Path Traversal Detection
        if (
            str_contains($uri, '../') ||
            str_contains($uri, '..\\') ||
            str_contains($queryString, '../') ||
            str_contains($queryString, '..%2f') ||
            str_contains($uri, 'etc/passwd') ||
            str_contains($uri, 'win.ini')
        ) {
            return [
                'detected' => true,
                'type' => 'path_traversal',
                'severity' => 'critical',
                'description' => 'Path traversal / LFI probe detected in request URI',
            ];
        }

        // 3. SQL Injection Probe Detection
        $sqliPattern = '/(\b(union\s+all\s+select|union\s+select|select\s+.*\s+from|sleep\s*\(|benchmark\s*\()\b)|(\b(and|or)\b\s+["\']?1["\']?\s*=\s*["\']?1)/i';
        if (preg_match($sqliPattern, $queryString) || preg_match($sqliPattern, $uri)) {
            return [
                'detected' => true,
                'type' => 'sqli_attempt',
                'severity' => 'critical',
                'description' => 'SQL injection signature detected in request query parameters',
            ];
        }

        // 4. Cross-Site Scripting (XSS) Probe Detection
        $xssPattern = '/(<script.*?>.*?<\/script>)|(javascript:)|(onerror\s*=)|(onload\s*=)|(<iframe.*?>)/i';
        if (preg_match($xssPattern, $queryString)) {
            return [
                'detected' => true,
                'type' => 'xss_attempt',
                'severity' => 'high',
                'description' => 'XSS payload pattern detected in query parameters',
            ];
        }

        // 5. PHP-CGI & Windows Argument Injection (CVE-2024-4577 & variants)
        $rawQuery = (string) $request->server->get('QUERY_STRING', '');
        $combinedQuery = strtolower($queryString.' '.$rawQuery);
        if (
            preg_match('/(%ad|%80|-d\b|-s\b|-b\b|-c\b|-n\b|-w\b|-z\b).*(allow_url_include|auto_prepend_file|auto_append_file|cgi\.force_redirect|open_basedir|disable_functions|php:\/\/input)/i', $combinedQuery) ||
            preg_match('/(\b(allow_url_include|auto_prepend_file|auto_append_file)\b\s*=\s*(1|on|true|php:\/\/))/i', $combinedQuery)
        ) {
            return [
                'detected' => true,
                'type' => 'php_cgi_argument_injection',
                'severity' => 'critical',
                'description' => 'PHP-CGI / Windows argument injection signature intercepted',
            ];
        }

        // 6. PHP2Shell & Stream Wrapper / Memory Exhaustion Probes (CVE-2026-109618, CVE-2026-109619)
        if (preg_match('/(php|phar|data):\/\//i', $queryString) || preg_match('/(php|phar|data):\/\//i', $uri)) {
            return [
                'detected' => true,
                'type' => 'php_runtime_exploit_probe',
                'severity' => 'critical',
                'description' => 'Malicious PHP stream wrapper injection (php://, phar://, data://) intercepted',
            ];
        }

        // Detect PHP_SESSION_UPLOAD_PROGRESS abuse (PHP2Shell primary pre-auth vector)
        $progressParam = $request->input('PHP_SESSION_UPLOAD_PROGRESS') ?? $request->header('PHP_SESSION_UPLOAD_PROGRESS');
        if ($progressParam !== null) {
            $progressStr = is_string($progressParam) ? $progressParam : json_encode($progressParam);
            if (
                strlen((string) $progressStr) > 128 ||
                str_contains((string) $progressStr, "\0") ||
                str_contains((string) $progressStr, '%00') ||
                str_contains((string) $progressStr, '../') ||
                str_contains((string) $progressStr, '..\\') ||
                preg_match('/[^\w\-_\.]/', (string) $progressStr)
            ) {
                return [
                    'detected' => true,
                    'type' => 'php_runtime_exploit_probe',
                    'severity' => 'critical',
                    'description' => 'Abnormal PHP_SESSION_UPLOAD_PROGRESS payload intercepted (PHP2Shell attack vector)',
                ];
            }
        }

        // Detect oversized headers attempting low-level PHP memory exhaustion
        $headerTotalLength = 0;
        foreach ($request->headers->all() as $headerName => $headerValues) {
            foreach ($headerValues as $headerVal) {
                $valLen = strlen((string) $headerVal);
                $headerTotalLength += $valLen;
                if ($valLen > 8192) {
                    return [
                        'detected' => true,
                        'type' => 'php_runtime_exploit_probe',
                        'severity' => 'critical',
                        'description' => "Oversized HTTP header '{$headerName}' ({$valLen} bytes) attempting memory exhaustion",
                    ];
                }
            }
        }
        if ($headerTotalLength > 32768) {
            return [
                'detected' => true,
                'type' => 'php_runtime_exploit_probe',
                'severity' => 'critical',
                'description' => "Cumulative HTTP header payload ({$headerTotalLength} bytes) attempting memory exhaustion",
            ];
        }

        // 7. Sensitive File Probe in Route
        foreach (self::PROBE_PATHS as $probe) {
            if (str_contains($uri, $probe)) {
                return [
                    'detected' => true,
                    'type' => '404_probe',
                    'severity' => $this->classifyProbeSeverity($probe),
                    'description' => "Probe for sensitive endpoint or credential file: {$probe}",
                ];
            }
        }

        return null;
    }

    /**
     * Record a 404 page access and determine whether it represents a threat.
     */
    public function record404(Request $request): ?SecurityThreatLog
    {
        $uri = strtolower($request->getRequestUri());
        $isProbe = false;
        $matchedProbe = null;

        foreach (self::PROBE_PATHS as $probe) {
            if (str_contains($uri, $probe)) {
                $isProbe = true;
                $matchedProbe = $probe;
                break;
            }
        }

        // Also check for common scanner file extensions (.php, .asp, .bak, .sql, .env)
        if (! $isProbe && preg_match('/\.(env|bak|sql|config|git|ini|sh)$/i', $uri)) {
            $isProbe = true;
            $matchedProbe = 'sensitive extension';
        }

        $ips = ClientIpResolver::resolve($request);
        $primaryIp = $ips['primary'];
        $clientIpv4 = $ips['client_ipv4'];
        $clientIpv6 = $ips['client_ipv6'];

        $severity = $isProbe ? $this->classifyProbeSeverity($matchedProbe ?? '') : 'low';

        // Rate tracking for 404s per IP
        $key = "sec:404_count:{$primaryIp}";
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addMinutes(15));

        // If high frequency of 404s from a single IP, elevate severity
        if ($count >= 10 && $severity === 'low') {
            $severity = 'medium';
            $isProbe = true;
        }

        // Only persist if it's an identified probe or repeated 404
        if ($isProbe || $count >= 3) {
            return $this->logThreat([
                'ip_address' => $primaryIp,
                'client_ipv4' => $clientIpv4,
                'client_ipv6' => $clientIpv6,
                'user_id' => $request->user()?->id,
                'threat_type' => '404_probe',
                'severity' => $severity,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'user_agent' => $request->userAgent(),
                'payload' => [
                    'path' => $request->path(),
                    'query' => $request->query(),
                    'client_ipv4' => $clientIpv4,
                    'client_ipv6' => $clientIpv6,
                    'consecutive_404_count' => $count,
                ],
                'action_taken' => 'logged',
            ]);
        }

        return null;
    }

    /**
     * Record a DDoS / rate limiting surge (HTTP 429).
     */
    public function recordRateLimitExceeded(Request $request): SecurityThreatLog
    {
        $ips = ClientIpResolver::resolve($request);

        return $this->logThreat([
            'ip_address' => $ips['primary'],
            'client_ipv4' => $ips['client_ipv4'],
            'client_ipv6' => $ips['client_ipv6'],
            'user_id' => $request->user()?->id,
            'threat_type' => 'ddos_rate_limit',
            'severity' => 'high',
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'user_agent' => $request->userAgent(),
            'payload' => [
                'route' => $request->route()?->getName() ?: $request->path(),
                'client_ipv4' => $ips['client_ipv4'],
                'client_ipv6' => $ips['client_ipv6'],
                'headers' => [
                    'accept' => $request->header('accept'),
                    'cf-ray' => $request->header('cf-ray'),
                ],
            ],
            'action_taken' => 'throttled',
        ]);
    }

    /**
     * Recursively sanitize strings to valid UTF-8 so binary or non-standard character sets (e.g. %ad soft hyphens)
     * can be safely encoded as JSON in the database.
     */
    protected function sanitizeUtf8(mixed $data): mixed
    {
        if (is_string($data)) {
            return function_exists('mb_scrub')
                ? mb_scrub($data, 'UTF-8')
                : mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
        }

        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $key => $val) {
                $cleanKey = is_string($key)
                    ? (function_exists('mb_scrub') ? mb_scrub($key, 'UTF-8') : mb_convert_encoding($key, 'UTF-8', 'ISO-8859-1'))
                    : $key;
                $cleaned[$cleanKey] = $this->sanitizeUtf8($val);
            }

            return $cleaned;
        }

        return $data;
    }

    /**
     * Log a security threat and check whether self-healing quarantine should trigger.
     *
     * @param  array<string, mixed>  $data
     */
    public function logThreat(array $data): SecurityThreatLog
    {
        if (isset($data['payload'])) {
            $data['payload'] = $this->sanitizeUtf8($data['payload']);
        }

        $threat = SecurityThreatLog::create($data);

        // Notify Self-Healing Engine to evaluate defense rules
        $this->selfHealingService->evaluateThreat($threat);

        return $threat;
    }

    /**
     * Classify severity of a probe path.
     */
    protected function classifyProbeSeverity(string $probe): string
    {
        $probe = strtolower($probe);

        // Immediate Critical Threat Signatures: Credential leaks, webshells, CMS exploits & admin tools
        if (
            str_contains($probe, '.env') ||
            str_contains($probe, '.git') ||
            str_contains($probe, '.svn') ||
            str_contains($probe, 'credentials') ||
            str_contains($probe, '.aws') ||
            str_contains($probe, '.ssh') ||
            str_contains($probe, 'database.php') ||
            str_contains($probe, 'web.config') ||
            str_contains($probe, 'eval-stdin') ||
            str_contains($probe, 'shell.php') ||
            str_contains($probe, 'alfa.php') ||
            str_contains($probe, 'cmd.php') ||
            str_contains($probe, 'c99.php') ||
            str_contains($probe, 'wso.php') ||
            str_contains($probe, 'phpinfo') ||
            str_contains($probe, 'wp-admin') ||
            str_contains($probe, 'wp-login') ||
            str_contains($probe, 'wp-includes') ||
            str_contains($probe, 'xmlrpc') ||
            str_contains($probe, 'install.php') ||
            str_contains($probe, 'setup.php') ||
            str_contains($probe, 'phpmyadmin') ||
            str_contains($probe, 'pma') ||
            str_contains($probe, 'adminer') ||
            str_contains($probe, 'actuator') ||
            str_contains($probe, 'solr') ||
            str_contains($probe, 'telescope') ||
            str_contains($probe, 'php-cgi') ||
            str_contains($probe, 'cgi-bin')
        ) {
            return 'critical';
        }

        return 'medium';
    }
}
