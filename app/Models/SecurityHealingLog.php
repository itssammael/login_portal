<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityHealingLog extends Model
{
    use HasFactory;

    /**
     * Action type labels.
     */
    public const ACTION_TYPES = [
        'ip_auto_ban' => 'Automated IP Quarantine',
        'config_patch' => 'Configuration Hardening Patch',
        'header_enforce' => 'Security Headers Enforced',
        'cleanup_stale_tokens' => 'Stale Token Attack Surface Cleanup',
        'file_permission_remediate' => 'Storage & File Security Hardened',
        'rate_limit_enforce' => 'Dynamic Rate Limiter Applied',
        'php_runtime_hardening' => 'PHP Runtime & CVE Mitigation',
        'php_ini_hardening' => 'PHP Server Configuration Hardened',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'action_type',
        'description',
        'target',
        'status',
        'details',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }
}
