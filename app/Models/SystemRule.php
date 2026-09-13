<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemRule extends Model
{
    use HasFactory;

    public const CATEGORIES = [
        'chat_moderation' => 'Chat & Messaging Moderation',
        'security' => 'Platform Security & Auth',
        'user_access' => 'User Account & Access Controls',
        'general' => 'General System Configuration',
    ];

    public const RULE_TYPES = [
        'boolean' => 'Toggle Switch (True/False)',
        'integer' => 'Numeric Limit / Threshold',
        'string' => 'Text / Phrase Value',
        'json' => 'Structured JSON Data',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'key',
        'category',
        'description',
        'rule_type',
        'value',
        'is_active',
        'priority',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    /**
     * Get parsed system rule value by key.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $rule = static::where('key', $key)->first();
        if (! $rule || ! $rule->is_active) {
            return $default;
        }

        if ($rule->rule_type === 'boolean') {
            return filter_var($rule->value, FILTER_VALIDATE_BOOLEAN);
        }

        if ($rule->rule_type === 'integer') {
            return (int) $rule->value;
        }

        if ($rule->rule_type === 'json') {
            $decoded = json_decode($rule->value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
        }

        return $rule->value;
    }

    /**
     * Determine if a user is permitted to broadcast system announcements based on system engine rule.
     */
    public static function canUserBroadcastAnnouncements(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $allowedRoles = static::getValue('announcement_broadcast_roles', ['admin', 'support']);

        $roleSlug = $user->role?->slug;

        if (! $roleSlug) {
            return false;
        }

        if (is_array($allowedRoles)) {
            return in_array($roleSlug, $allowedRoles, true);
        }

        if (is_string($allowedRoles)) {
            $rolesArray = array_map('trim', explode(',', $allowedRoles));

            return in_array($roleSlug, $rolesArray, true);
        }

        return false;
    }
}
