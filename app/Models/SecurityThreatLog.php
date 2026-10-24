<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityThreatLog extends Model
{
    use HasFactory;

    /**
     * Threat category definitions.
     */
    public const THREAT_TYPES = [
        '404_probe' => '404 URL Probing & Scanning',
        'ddos_rate_limit' => 'DDoS / Velocity Rate Violation',
        'sqli_attempt' => 'SQL Injection Probe',
        'xss_attempt' => 'Cross-Site Scripting (XSS)',
        'path_traversal' => 'Directory / Path Traversal',
        'malicious_scanner' => 'Automated Bot / Exploit Scanner',
        'suspicious_auth' => 'Suspicious Auth Activity',
        'php_runtime_exploit_probe' => 'PHP Runtime / PHP2Shell Exploit Probe',
        'php_cgi_argument_injection' => 'PHP CGI / Windows Argument Injection',
    ];

    /**
     * Severity levels.
     */
    public const SEVERITY_LEVELS = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ip_address',
        'client_ipv4',
        'client_ipv6',
        'user_id',
        'threat_type',
        'severity',
        'url',
        'method',
        'user_agent',
        'payload',
        'action_taken',
        'is_resolved',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_resolved' => 'boolean',
        ];
    }

    /**
     * Associated authenticated user, if any.
     *
     * @return BelongsTo<User, SecurityThreatLog>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
