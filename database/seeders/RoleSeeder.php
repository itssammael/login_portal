<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(
            ['slug' => 'admin'],
            [
                'name' => 'Administrator',
                'description' => 'Full administrative access to manage users, roles, system settings, and content.',
                'color' => 'indigo',
                'permissions' => array_keys(Role::PERMISSIONS),
                'is_system' => true,
            ]
        );

        $moderatorRole = Role::firstOrCreate(
            ['slug' => 'moderator'],
            [
                'name' => 'Moderator',
                'description' => 'Can manage users, monitor chat activities, and enforce content guidelines.',
                'color' => 'purple',
                'permissions' => ['access_admin', 'manage_users', 'moderate_chats'],
                'is_system' => true,
            ]
        );

        $supportRole = Role::firstOrCreate(
            ['slug' => 'support'],
            [
                'name' => 'Support Agent',
                'description' => 'Can moderate chats, broadcast announcements, and inspect system audit logs.',
                'color' => 'blue',
                'permissions' => ['access_admin', 'moderate_chats', 'send_announcements', 'view_audit_logs'],
                'is_system' => false,
            ]
        );

        $userRole = Role::firstOrCreate(
            ['slug' => 'user'],
            [
                'name' => 'Regular User',
                'description' => 'Standard user account with regular platform participation rights.',
                'color' => 'gray',
                'permissions' => [],
                'is_system' => true,
            ]
        );

        User::where('is_admin', true)->whereNull('role_id')->update(['role_id' => $adminRole->id]);
        User::where('is_admin', false)->whereNull('role_id')->update(['role_id' => $userRole->id]);
    }
}
