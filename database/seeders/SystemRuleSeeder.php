<?php

namespace Database\Seeders;

use App\Models\SystemRule;
use Illuminate\Database\Seeder;

class SystemRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultRules = [
            // Chat Moderation
            [
                'key' => 'profanity_filter',
                'name' => 'Automated Chat Profanity & Keyword Filter',
                'category' => 'chat_moderation',
                'description' => 'Automatically flags or masks forbidden keywords and profanity in direct and group chat messages.',
                'rule_type' => 'boolean',
                'value' => 'true',
                'is_active' => true,
                'priority' => 1,
            ],
            [
                'key' => 'max_message_length',
                'name' => 'Maximum Message Length Threshold',
                'category' => 'chat_moderation',
                'description' => 'Maximum character length allowed for a single chat message transmission.',
                'rule_type' => 'integer',
                'value' => '2000',
                'is_active' => true,
                'priority' => 2,
            ],
            [
                'key' => 'block_external_links',
                'name' => 'Restrict External Links in Messenger',
                'category' => 'chat_moderation',
                'description' => 'Prevents non-administrative users from posting unverified external URLs in chat threads.',
                'rule_type' => 'boolean',
                'value' => 'false',
                'is_active' => false,
                'priority' => 3,
            ],
            [
                'key' => 'chat_rate_limit',
                'name' => 'Message Transmission Rate Limit',
                'category' => 'chat_moderation',
                'description' => 'Maximum number of messages a user can send within a 60-second window.',
                'rule_type' => 'integer',
                'value' => '30',
                'is_active' => true,
                'priority' => 4,
            ],

            // Security Controls
            [
                'key' => 'enforce_2fa_admin',
                'name' => 'Mandatory 2FA for Administrative Users',
                'category' => 'security',
                'description' => 'Requires two-factor TOTP authentication setup for all users with admin or moderator access.',
                'rule_type' => 'boolean',
                'value' => 'true',
                'is_active' => true,
                'priority' => 1,
            ],
            [
                'key' => 'max_login_attempts',
                'name' => 'Failed Login Attempt Throttle Limit',
                'category' => 'security',
                'description' => 'Number of invalid password attempts before temporary IP address lockout.',
                'rule_type' => 'integer',
                'value' => '5',
                'is_active' => true,
                'priority' => 2,
            ],
            [
                'key' => 'session_timeout_minutes',
                'name' => 'Idle Session Timeout (Minutes)',
                'category' => 'security',
                'description' => 'Automatic logout timer for inactive user browser sessions.',
                'rule_type' => 'integer',
                'value' => '120',
                'is_active' => true,
                'priority' => 3,
            ],

            // User Access
            [
                'key' => 'require_email_verification',
                'name' => 'Require Verified Email for Messaging',
                'category' => 'user_access',
                'description' => 'Restricts sending chat messages until user confirms their email address.',
                'rule_type' => 'boolean',
                'value' => 'true',
                'is_active' => true,
                'priority' => 1,
            ],
            [
                'key' => 'auto_suspend_flag_threshold',
                'name' => 'Auto-Suspend Threshold on Flags',
                'category' => 'user_access',
                'description' => 'Automatically suspends user account after accumulating X moderated violations.',
                'rule_type' => 'integer',
                'value' => '3',
                'is_active' => false,
                'priority' => 2,
            ],
            [
                'key' => 'allow_public_registration',
                'name' => 'Allow Public User Registration',
                'category' => 'user_access',
                'description' => 'Enables or disables new user signup on the welcome portal.',
                'rule_type' => 'boolean',
                'value' => 'true',
                'is_active' => true,
                'priority' => 3,
            ],
            [
                'key' => 'announcement_broadcast_roles',
                'name' => 'Roles Permitted to Broadcast System Announcements',
                'category' => 'user_access',
                'description' => 'Defines user roles (e.g. admin, support) that are authorized to create and broadcast system announcements.',
                'rule_type' => 'json',
                'value' => '["admin", "support"]',
                'is_active' => true,
                'priority' => 4,
            ],
        ];

        foreach ($defaultRules as $rule) {
            SystemRule::firstOrCreate(['key' => $rule['key']], $rule);
        }
    }
}
