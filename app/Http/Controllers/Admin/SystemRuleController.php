<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SystemRuleController extends Controller
{
    /**
     * Display System Rules Engine overview and configuration list.
     */
    public function index(Request $request): Response
    {
        $query = SystemRule::query();

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('key', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $rules = $query->orderBy('priority', 'asc')
            ->orderBy('category', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        $allRules = SystemRule::all();

        return Inertia::render('Admin/SystemRules', [
            'rules' => $rules,
            'categories' => SystemRule::CATEGORIES,
            'ruleTypes' => SystemRule::RULE_TYPES,
            'stats' => [
                'total_rules' => $allRules->count(),
                'active_rules' => $allRules->where('is_active', true)->count(),
                'inactive_rules' => $allRules->where('is_active', false)->count(),
                'categories_count' => count(SystemRule::CATEGORIES),
            ],
            'filters' => $request->only(['category', 'search']),
        ]);
    }

    /**
     * Store a new custom system rule.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'key' => ['nullable', 'string', 'max:100', 'unique:system_rules,key'],
            'category' => ['required', 'string', 'in:'.implode(',', array_keys(SystemRule::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:1000'],
            'rule_type' => ['required', 'string', 'in:'.implode(',', array_keys(SystemRule::RULE_TYPES))],
            'value' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $key = $validated['key'] ? Str::slug($validated['key'], '_') : Str::slug($validated['name'], '_');

        if (SystemRule::where('key', $key)->exists()) {
            $key = $key.'_'.rand(10, 99);
        }

        $rule = SystemRule::create([
            'name' => $validated['name'],
            'key' => $key,
            'category' => $validated['category'],
            'description' => $validated['description'] ?? null,
            'rule_type' => $validated['rule_type'],
            'value' => $validated['value'] ?? ($validated['rule_type'] === 'boolean' ? 'true' : ''),
            'is_active' => $validated['is_active'],
            'priority' => $validated['priority'],
        ]);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'created_system_rule',
            'target_type' => SystemRule::class,
            'target_id' => $rule->id,
            'details' => [
                'rule_name' => $rule->name,
                'rule_key' => $rule->key,
                'category' => $rule->category,
                'is_active' => $rule->is_active,
            ],
        ]);

        return back()->with('success', "System Rule \"{$rule->name}\" was created successfully.");
    }

    /**
     * Update an existing system rule.
     */
    public function update(Request $request, SystemRule $systemRule): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', 'in:'.implode(',', array_keys(SystemRule::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:1000'],
            'rule_type' => ['required', 'string', 'in:'.implode(',', array_keys(SystemRule::RULE_TYPES))],
            'value' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $systemRule->update($validated);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'updated_system_rule',
            'target_type' => SystemRule::class,
            'target_id' => $systemRule->id,
            'details' => [
                'rule_name' => $systemRule->name,
                'rule_key' => $systemRule->key,
                'category' => $systemRule->category,
                'is_active' => $systemRule->is_active,
                'value' => $systemRule->value,
            ],
        ]);

        return back()->with('success', "System Rule \"{$systemRule->name}\" updated successfully.");
    }

    /**
     * Instant toggle rule active status.
     */
    public function toggle(Request $request, SystemRule $systemRule): RedirectResponse
    {
        $systemRule->update([
            'is_active' => ! $systemRule->is_active,
        ]);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => $systemRule->is_active ? 'enabled_system_rule' : 'disabled_system_rule',
            'target_type' => SystemRule::class,
            'target_id' => $systemRule->id,
            'details' => [
                'rule_name' => $systemRule->name,
                'rule_key' => $systemRule->key,
            ],
        ]);

        $statusLabel = $systemRule->is_active ? 'Activated' : 'Deactivated';

        return back()->with('success', "Rule \"{$systemRule->name}\" is now {$statusLabel}.");
    }

    /**
     * Delete a system rule.
     */
    public function destroy(Request $request, SystemRule $systemRule): RedirectResponse
    {
        $ruleName = $systemRule->name;
        $systemRule->delete();

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'deleted_system_rule',
            'target_type' => SystemRule::class,
            'target_id' => $systemRule->id,
            'details' => [
                'rule_name' => $ruleName,
            ],
        ]);

        return back()->with('success', "System Rule \"{$ruleName}\" deleted successfully.");
    }
}
