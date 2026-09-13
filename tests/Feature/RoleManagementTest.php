<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_non_admin_cannot_access_roles_page(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('admin.roles.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_roles_list(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.roles.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Roles')
            ->has('roles')
            ->has('availablePermissions')
            ->has('availableColors')
        );
    }

    public function test_admin_can_create_custom_role(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.roles.store'), [
            'name' => 'Community Specialist',
            'slug' => 'community-specialist',
            'description' => 'Handles community interactions and chat moderation.',
            'color' => 'purple',
            'permissions' => ['access_admin', 'moderate_chats'],
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('roles', [
            'name' => 'Community Specialist',
            'slug' => 'community-specialist',
            'color' => 'purple',
            'is_system' => false,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'created_role',
        ]);
    }

    public function test_admin_can_update_role(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::create([
            'name' => 'Junior Moderator',
            'slug' => 'junior-moderator',
            'description' => 'Initial description',
            'color' => 'blue',
            'permissions' => ['moderate_chats'],
            'is_system' => false,
        ]);

        $response = $this->actingAs($admin)->put(route('admin.roles.update', $role), [
            'name' => 'Senior Moderator',
            'description' => 'Updated description',
            'color' => 'rose',
            'permissions' => ['access_admin', 'moderate_chats', 'manage_users'],
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Senior Moderator',
            'color' => 'rose',
        ]);
    }

    public function test_admin_cannot_delete_system_role(): void
    {
        $admin = User::factory()->admin()->create();
        $systemRole = Role::where('slug', 'admin')->firstOrFail();

        $response = $this->actingAs($admin)->delete(route('admin.roles.destroy', $systemRole));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('roles', ['id' => $systemRole->id]);
    }

    public function test_admin_can_delete_custom_role_and_reassign_users(): void
    {
        $admin = User::factory()->admin()->create();
        $customRole = Role::create([
            'name' => 'Temporary Helper',
            'slug' => 'temp-helper',
            'color' => 'amber',
            'is_system' => false,
        ]);

        $assignedUser = User::factory()->create(['role_id' => $customRole->id]);

        $response = $this->actingAs($admin)->delete(route('admin.roles.destroy', $customRole));

        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('roles', ['id' => $customRole->id]);

        $defaultUserRole = Role::where('slug', 'user')->first();
        $this->assertEquals($defaultUserRole->id, $assignedUser->fresh()->role_id);
    }

    public function test_admin_can_assign_role_to_user(): void
    {
        $admin = User::factory()->admin()->create();
        $targetUser = User::factory()->create();
        $moderatorRole = Role::where('slug', 'moderator')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('admin.users.update-role', $targetUser), [
            'role_id' => $moderatorRole->id,
        ]);

        $response->assertSessionHas('success');
        $this->assertEquals($moderatorRole->id, $targetUser->fresh()->role_id);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'updated_user_role',
            'target_id' => $targetUser->id,
        ]);
    }
}
