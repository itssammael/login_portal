<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    /**
     * Display a listing of departments and sections.
     */
    public function index(Request $request): Response
    {
        $query = Department::query()
            ->with(['sections' => function ($q): void {
                $q->withCount('users')->orderBy('name');
            }])
            ->withCount(['sections', 'users']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('acronym', 'like', "%{$search}%")
                    ->orWhere('contact_person_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('sections', function ($sq) use ($search): void {
                        $sq->where('name', 'like', "%{$search}%")
                            ->orWhere('section_contact_person_name', 'like', "%{$search}%");
                    });
            });
        }

        $departments = $query->orderBy('name')->get();

        $stats = [
            'total_departments' => Department::count(),
            'total_sections' => Section::count(),
            'total_employees' => User::whereNotNull('section_id')->count(),
        ];

        return Inertia::render('Admin/Departments', [
            'departments' => $departments,
            'stats' => $stats,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Store a newly created department.
     */
    public function store(Request $request): RedirectResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'acronym' => ['nullable', 'string', 'max:50'],
            'contact_person_name' => ['nullable', 'string', 'max:255'],
            'contact_person_position' => ['nullable', 'string', 'max:255'],
            'department_phone_extension' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'department_address' => ['nullable', 'string', 'max:500'],
        ]);

        $department = Department::create($validated);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'created_department',
            'target_type' => Department::class,
            'target_id' => $department->id,
            'details' => [
                'name' => $department->name,
                'acronym' => $department->acronym,
            ],
        ]);

        return back()->with('success', "Department '{$department->name}' created successfully.");
    }

    /**
     * Update the specified department.
     */
    public function update(Request $request, Department $department): RedirectResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'acronym' => ['nullable', 'string', 'max:50'],
            'contact_person_name' => ['nullable', 'string', 'max:255'],
            'contact_person_position' => ['nullable', 'string', 'max:255'],
            'department_phone_extension' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'department_address' => ['nullable', 'string', 'max:500'],
        ]);

        $department->update($validated);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'updated_department',
            'target_type' => Department::class,
            'target_id' => $department->id,
            'details' => [
                'name' => $department->name,
                'acronym' => $department->acronym,
            ],
        ]);

        return back()->with('success', "Department '{$department->name}' updated successfully.");
    }

    /**
     * Remove the specified department.
     */
    public function destroy(Request $request, Department $department): RedirectResponse
    {
        $admin = $request->user();
        $name = $department->name;

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'deleted_department',
            'target_type' => Department::class,
            'target_id' => $department->id,
            'details' => [
                'name' => $name,
            ],
        ]);

        $department->delete();

        return back()->with('success', "Department '{$name}' deleted successfully.");
    }

    /**
     * Store a newly created section in a department.
     */
    public function storeSection(Request $request, Department $department): RedirectResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'section_contact_person_name' => ['nullable', 'string', 'max:255'],
            'section_contact_person_position' => ['nullable', 'string', 'max:255'],
            'section_phone_extension' => ['nullable', 'string', 'max:50'],
            'section_email' => ['nullable', 'email', 'max:255'],
            'section_address' => ['nullable', 'string', 'max:500'],
        ]);

        $section = $department->sections()->create($validated);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'created_section',
            'target_type' => Section::class,
            'target_id' => $section->id,
            'details' => [
                'department' => $department->name,
                'name' => $section->name,
            ],
        ]);

        return back()->with('success', "Section '{$section->name}' added to {$department->name}.");
    }

    /**
     * Update the specified section.
     */
    public function updateSection(Request $request, Section $section): RedirectResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'section_contact_person_name' => ['nullable', 'string', 'max:255'],
            'section_contact_person_position' => ['nullable', 'string', 'max:255'],
            'section_phone_extension' => ['nullable', 'string', 'max:50'],
            'section_email' => ['nullable', 'email', 'max:255'],
            'section_address' => ['nullable', 'string', 'max:500'],
        ]);

        $section->update($validated);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'updated_section',
            'target_type' => Section::class,
            'target_id' => $section->id,
            'details' => [
                'name' => $section->name,
            ],
        ]);

        return back()->with('success', "Section '{$section->name}' updated successfully.");
    }

    /**
     * Remove the specified section.
     */
    public function destroySection(Request $request, Section $section): RedirectResponse
    {
        $admin = $request->user();
        $name = $section->name;

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'deleted_section',
            'target_type' => Section::class,
            'target_id' => $section->id,
            'details' => [
                'name' => $name,
                'department_id' => $section->department_id,
            ],
        ]);

        $section->delete();

        return back()->with('success', "Section '{$name}' deleted successfully.");
    }
}
