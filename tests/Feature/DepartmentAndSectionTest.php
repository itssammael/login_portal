<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DepartmentAndSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_departments_and_sections_tables_have_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('departments'));
        $this->assertTrue(Schema::hasColumns('departments', [
            'id',
            'name',
            'acronym',
            'contact_person_name',
            'contact_person_position',
            'department_phone_extension',
            'email',
            'department_address',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('sections'));
        $this->assertTrue(Schema::hasColumns('sections', [
            'id',
            'department_id',
            'name',
            'section_contact_person_name',
            'section_contact_person_position',
            'section_phone_extension',
            'section_email',
            'section_address',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('users', [
            'section_id',
            'position',
            'employee_number',
        ]));
    }

    public function test_department_has_sections_and_employees_relationships(): void
    {
        $department = Department::factory()->create([
            'name' => 'Human Resource Management Office',
            'acronym' => 'HRMO',
            'contact_person_name' => 'Jane Doe',
            'contact_person_position' => 'HR Officer IV',
            'department_phone_extension' => '104',
            'email' => 'hrmo@lgunet.gov.ph',
            'department_address' => '2nd Floor, Executive Hall, City Hall',
        ]);

        $section1 = Section::factory()->create([
            'department_id' => $department->id,
            'name' => 'Recruitment & Selection Section',
            'section_contact_person_name' => 'John Smith',
            'section_contact_person_position' => 'HRMO II',
            'section_phone_extension' => '1041',
            'section_email' => 'recruitment@lgunet.gov.ph',
            'section_address' => 'Room 201, City Hall',
        ]);

        $section2 = Section::factory()->create([
            'department_id' => $department->id,
            'name' => 'Employee Welfare Section',
        ]);

        $employee1 = User::factory()->create([
            'section_id' => $section1->id,
            'position' => 'Administrative Assistant I',
            'employee_number' => 'EMP-2026-001',
        ]);

        $employee2 = User::factory()->create([
            'section_id' => $section1->id,
            'position' => 'HR Specialist',
            'employee_number' => 'EMP-2026-002',
        ]);

        $employee3 = User::factory()->create([
            'section_id' => $section2->id,
            'position' => 'Welfare Coordinator',
            'employee_number' => 'EMP-2026-003',
        ]);

        // Verify Department -> Sections
        $this->assertCount(2, $department->sections);
        $this->assertTrue($department->sections->contains($section1));
        $this->assertTrue($department->sections->contains($section2));

        // Verify Section -> Users
        $this->assertCount(2, $section1->users);
        $this->assertTrue($section1->users->contains($employee1));
        $this->assertTrue($section1->users->contains($employee2));

        // Verify Department -> Users (through sections)
        $this->assertCount(3, $department->users);
        $this->assertTrue($department->users->contains($employee1));
        $this->assertTrue($department->users->contains($employee3));

        // Verify User -> Section
        $this->assertEquals($section1->id, $employee1->section->id);
        $this->assertEquals('Recruitment & Selection Section', $employee1->section->name);
        $this->assertEquals('EMP-2026-001', $employee1->employee_number);
        $this->assertEquals('Administrative Assistant I', $employee1->position);

        // Verify User -> Department (through section)
        $this->assertNotNull($employee1->department);
        $this->assertEquals($department->id, $employee1->department->id);
        $this->assertEquals('Human Resource Management Office', $employee1->department->name);
    }

    public function test_deleting_section_nullifies_user_section_id(): void
    {
        $department = Department::factory()->create();
        $section = Section::factory()->create(['department_id' => $department->id]);
        $user = User::factory()->create(['section_id' => $section->id]);

        $section->delete();

        $this->assertNull($user->fresh()->section_id);
    }

    public function test_deleting_department_cascades_to_sections(): void
    {
        $department = Department::factory()->create();
        $section = Section::factory()->create(['department_id' => $department->id]);

        $department->delete();

        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
    }
}
