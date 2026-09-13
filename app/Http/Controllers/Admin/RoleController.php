<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    /**
     * Display role management list and permissions overview.
     */
    public function index(): Response
    {
        $roles = Role::withCount('users')
            ->orderBy('is_system', 'desc')
            ->orderBy('id', 'asc')
            ->get();

        $availableColors = [
            ['key' => 'indigo', 'label' => 'Indigo', 'bg' => 'bg-indigo-100', 'text' => 'text-indigo-800', 'border' => 'border-indigo-300'],
            ['key' => 'purple', 'label' => 'Purple', 'bg' => 'bg-purple-100', 'text' => 'text-purple-800', 'border' => 'border-purple-300'],
            ['key' => 'blue', 'label' => 'Blue', 'bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'border' => 'border-blue-300'],
            ['key' => 'emerald', 'label' => 'Emerald', 'bg' => 'bg-emerald-100', 'text' => 'text-emerald-800', 'border' => 'border-emerald-300'],
            ['key' => 'amber', 'label' => 'Amber', 'bg' => 'bg-amber-100', 'text' => 'text-amber-800', 'border' => 'border-amber-300'],
            ['key' => 'rose', 'label' => 'Rose', 'bg' => 'bg-rose-100', 'text' => 'text-rose-800', 'border' => 'border-rose-300'],
            ['key' => 'gray', 'label' => 'Gray', 'bg' => 'bg-gray-100', 'text' => 'text-gray-800', 'border' => 'border-gray-300'],
        ];

        return Inertia::render('Admin/Roles', [
            'roles' => $roles,
            'availablePermissions' => Role::PERMISSIONS,
            'availableColors' => $availableColors,
            'stats' => [
                'total_roles' => $roles->count(),
                'custom_roles' => $roles->where('is_system', false)->count(),
                'total_assigned_users' => User::whereNotNull('role_id')->count(),
            ],
        ]);
    }

    /**
     * Store a newly created custom role.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:roles,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['required', 'string', 'in:indigo,purple,blue,emerald,amber,rose,gray'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', array_keys(Role::PERMISSIONS))],
        ]);

        $slug = $validated['slug'] ? Str::slug($validated['slug']) : Str::slug($validated['name']);

        if (Role::where('slug', $slug)->exists()) {
            $slug = $slug.'-'.rand(10, 99);
        }

        $role = Role::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'],
            'permissions' => $validated['permissions'] ?? [],
            'is_system' => false,
        ]);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'created_role',
            'target_type' => Role::class,
            'target_id' => $role->id,
            'details' => [
                'role_name' => $role->name,
                'slug' => $role->slug,
                'permissions' => $role->permissions,
            ],
        ]);

        return back()->with('success', "Role \"{$role->name}\" was created successfully.");
    }

    /**
     * Update an existing role.
     */
    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['required', 'string', 'in:indigo,purple,blue,emerald,amber,rose,gray'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'in:'.implode(',', array_keys(Role::PERMISSIONS))],
        ]);

        $role->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'],
            'permissions' => $validated['permissions'] ?? [],
        ]);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'updated_role',
            'target_type' => Role::class,
            'target_id' => $role->id,
            'details' => [
                'role_name' => $role->name,
                'slug' => $role->slug,
                'permissions' => $role->permissions,
            ],
        ]);

        return back()->with('success', "Role \"{$role->name}\" was updated successfully.");
    }

    /**
     * Delete a custom role.
     */
    public function destroy(Request $request, Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('error', "System role \"{$role->name}\" cannot be deleted.");
        }

        $defaultUserRole = Role::where('slug', 'user')->first();
        $reassignedCount = User::where('role_id', $role->id)->update([
            'role_id' => $defaultUserRole?->id,
        ]);

        $roleName = $role->name;
        $role->delete();

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'deleted_role',
            'target_type' => Role::class,
            'target_id' => $role->id,
            'details' => [
                'role_name' => $roleName,
                'reassigned_users_count' => $reassignedCount,
            ],
        ]);

        return back()->with('success', "Role \"{$roleName}\" was deleted. {$reassignedCount} user(s) reassigned to default user role.");
    }
}
