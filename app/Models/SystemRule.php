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
}
