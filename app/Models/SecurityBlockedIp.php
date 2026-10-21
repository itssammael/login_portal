<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityBlockedIp extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ip_address',
        'client_ipv4',
        'client_ipv6',
        'reason',
        'threat_count',
        'blocked_by',
        'blocked_until',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threat_count' => 'integer',
            'blocked_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include actively blocked IPs.
     *
     * @param  Builder<SecurityBlockedIp>  $query
     * @return Builder<SecurityBlockedIp>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('blocked_until')
                    ->orWhere('blocked_until', '>', now());
            });
    }

    /**
     * Determine whether the block has expired.
     */
    public function isExpired(): bool
    {
        if ($this->blocked_until === null) {
            return false;
        }

        return $this->blocked_until->isPast();
    }
}
