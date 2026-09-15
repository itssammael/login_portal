<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserAndDepartmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_user_with_section_and_role(): void
    {
        $admin = User::factory()->admin()->create();
        $department = Department::factory()->create(['name' => 'City Engineering Office']);
        $section = Section::factory()->create([
            'department_id' => $department->id,
            'name' => 'Planning Section',
        ]);
        $role = Role::create([
            'name' => 'Officer',
            'slug' => 'officer',
            'color' => 'blue',
        ]);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan.delacruz@lgunet.gov.ph',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role_id' => $role->id,
            'section_id' => $section->id,
            'position' => 'Senior Engineer',
            'employee_number' => 'EMP-2026-999',
            'is_admin' => false,
        ]);

        $response->assertSessionHas('success');

        $createdUser = User::where('email', 'juan.delacruz@lgunet.gov.ph')->first();
        $this->assertNotNull($createdUser);
        $this->assertEquals('Juan Dela Cruz', $createdUser->name);
        $this->assertTrue(Hash::check('Password123!', $createdUser->password));
        $this->assertEquals($role->id, $createdUser->role_id);
        $this->assertEquals($section->id, $createdUser->section_id);
        $this->assertEquals('Senior Engineer', $createdUser->position);
        $this->assertEquals('EMP-2026-999', $createdUser->employee_number);
        $this->assertFalse($createdUser->is_admin);
        $this->assertNotNull($createdUser->email_verified_at);

        // Audit Log verification
        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'created_user',
            'target_id' => $createdUser->id,
        ]);
    }

    public function test_create_user_requires_valid_data(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => '',
            'email' => 'invalid-email',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ]);

        $response->assertSessionHasErrors(['name', 'email', 'password']);
    }

    public function test_admin_can_view_departments_index(): void
    {
        $admin = User::factory()->admin()->create();
        Department::factory()->has(Section::factory()->count(2))->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.departments.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Departments')
            ->has('departments', 3)
            ->has('stats')
        );
    }

    public function test_admin_can_create_update_and_delete_department(): void
    {
        $admin = User::factory()->admin()->create();

        // 1. Create
        $createResponse = $this->actingAs($admin)->post(route('admin.departments.store'), [
            'name' => 'Municipal Health Office',
            'acronym' => 'MHO',
            'contact_person_name' => 'Dr. Maria Cruz',
            'contact_person_position' => 'Municipal Health Officer',
            'department_phone_extension' => '301',
            'email' => 'mho@lgunet.gov.ph',
            'department_address' => 'Health Center Bldg',
        ]);

        $createResponse->assertSessionHas('success');
        $department = Department::where('acronym', 'MHO')->first();
        $this->assertNotNull($department);
        $this->assertEquals('Municipal Health Office', $department->name);

        // 2. Update
        $updateResponse = $this->actingAs($admin)->put(route('admin.departments.update', $department->id), [
            'name' => 'Municipal Health & Sanitation Office',
            'acronym' => 'MHSO',
            'contact_person_name' => 'Dr. Maria Cruz',
            'contact_person_position' => 'Chief Health Officer',
            'department_phone_extension' => '302',
            'email' => 'mhso@lgunet.gov.ph',
            'department_address' => 'Health Center Bldg, 2nd Floor',
        ]);

        $updateResponse->assertSessionHas('success');
        $this->assertEquals('Municipal Health & Sanitation Office', $department->fresh()->name);
        $this->assertEquals('MHSO', $department->fresh()->acronym);

        // 3. Delete
        $deleteResponse = $this->actingAs($admin)->delete(route('admin.departments.destroy', $department->id));
        $deleteResponse->assertSessionHas('success');
        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    public function test_admin_can_manage_sections_under_department(): void
    {
        $admin = User::factory()->admin()->create();
        $department = Department::factory()->create();

        // 1. Create Section
        $createResponse = $this->actingAs($admin)->post(route('admin.departments.sections.store', $department->id), [
            'name' => 'Maternal & Child Health Section',
            'section_contact_person_name' => 'Nurse Joy',
            'section_contact_person_position' => 'Head Nurse',
            'section_phone_extension' => '3021',
            'section_email' => 'maternal@lgunet.gov.ph',
            'section_address' => 'Room 101',
        ]);

        $createResponse->assertSessionHas('success');
        $section = Section::where('name', 'Maternal & Child Health Section')->first();
        $this->assertNotNull($section);
        $this->assertEquals($department->id, $section->department_id);

        // 2. Update Section
        $updateResponse = $this->actingAs($admin)->put(route('admin.sections.update', $section->id), [
            'name' => 'Maternal, Neonatal & Child Care Section',
            'section_contact_person_name' => 'Nurse Joy Smith',
            'section_contact_person_position' => 'Supervising Nurse',
            'section_phone_extension' => '3022',
            'section_email' => 'neonatal@lgunet.gov.ph',
            'section_address' => 'Room 102',
        ]);

        $updateResponse->assertSessionHas('success');
        $this->assertEquals('Maternal, Neonatal & Child Care Section', $section->fresh()->name);

        // 3. Delete Section
        $deleteResponse = $this->actingAs($admin)->delete(route('admin.sections.destroy', $section->id));
        $deleteResponse->assertSessionHas('success');
        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
    }

    public function test_admin_can_update_user_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'orig@example.com',
            'position' => 'Junior Clerk',
            'employee_number' => 'EMP-001',
        ]);

        $department = Department::factory()->create();
        $section = Section::factory()->create(['department_id' => $department->id]);
        $role = Role::create([
            'name' => 'Coordinator',
            'slug' => 'coordinator',
            'color' => 'indigo',
        ]);

        $response = $this->actingAs($admin)->put(route('admin.users.update', $user->id), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'position' => 'Senior Coordinator',
            'employee_number' => 'EMP-777',
            'role_id' => $role->id,
            'section_id' => $section->id,
            'is_admin' => true,
            'password' => 'NewSecretPassword123!',
            'password_confirmation' => 'NewSecretPassword123!',
        ]);

        $response->assertSessionHas('success');

        $refreshed = $user->fresh();
        $this->assertEquals('Updated Name', $refreshed->name);
        $this->assertEquals('updated@example.com', $refreshed->email);
        $this->assertEquals('Senior Coordinator', $refreshed->position);
        $this->assertEquals('EMP-777', $refreshed->employee_number);
        $this->assertEquals($role->id, $refreshed->role_id);
        $this->assertEquals($section->id, $refreshed->section_id);
        $this->assertTrue($refreshed->is_admin);
        $this->assertTrue(Hash::check('NewSecretPassword123!', $refreshed->password));

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'updated_user_profile',
            'target_id' => $user->id,
        ]);
    }

    public function test_admin_cannot_remove_own_admin_privileges(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->put(route('admin.users.update', $admin->id), [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_admin' => false,
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_admin);
    }
}
