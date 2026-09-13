<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasFactory;

    /**
     * System available permission keys and titles.
     */
    public const PERMISSIONS = [
        'access_admin' => 'Access Admin Panel',
        'manage_users' => 'Manage Users & Moderation',
        'manage_roles' => 'Manage Roles & Permissions',
        'moderate_chats' => 'Moderate Chat Messages',
        'send_announcements' => 'Broadcast Announcements',
        'view_audit_logs' => 'View Audit Logs',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'permissions',
        'is_system',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Users that have this role.
     *
     * @return HasMany<User>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Check if the role has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        if (empty($this->permissions)) {
            return false;
        }

        return in_array($permission, $this->permissions, true) || in_array('*', $this->permissions, true);
    }
}
