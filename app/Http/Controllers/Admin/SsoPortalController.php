<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Sso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SsoPortalController extends Controller
{
    /**
     * Display the SSO Portal management dashboard.
     */
    public function index(Request $request): Response
    {
        $search = $request->query('search');
        $status = $request->query('status', 'all');

        $query = Sso::query()->with('creator:id,name,email')->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('client_id', 'like', "%{$search}%")
                    ->orWhere('redirect_uri', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $clients = $query->get();

        $stats = [
            'total_clients' => Sso::count(),
            'active_clients' => Sso::where('is_active', true)->count(),
            'inactive_clients' => Sso::where('is_active', false)->count(),
        ];

        return Inertia::render('Admin/SsoPortal', [
            'clients' => $clients,
            'stats' => $stats,
            'frameworks' => Sso::FRAMEWORKS,
            'filters' => [
                'search' => $search ?? '',
                'status' => $status,
            ],
            'flashSecret' => session('new_secret'),
            'flashSecretClient' => session('new_secret_client_name'),
        ]);
    }

    /**
     * Store a newly created SSO client in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'client_id' => 'required|string|max:255|unique:sso,client_id',
            'client_secret' => 'nullable|string|max:255',
            'redirect_uri' => 'required|string',
            'api_url' => 'nullable|url|max:500',
            'is_active' => 'boolean',
            'framework' => 'nullable|string|max:100',
            'icon' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ]);

        $plainSecret = ! empty($validated['client_secret']) ? $validated['client_secret'] : Str::random(64);

        $iconPath = null;
        if ($request->hasFile('icon')) {
            $iconPath = $request->file('icon')->store('sso-icons', 'public');
        }

        $client = Sso::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'client_id' => $validated['client_id'],
            'client_secret' => $plainSecret,
            'redirect_uri' => $validated['redirect_uri'],
            'api_url' => $validated['api_url'] ?? null,
            'icon' => $iconPath,
            'framework' => $validated['framework'] ?? 'laravel_inertia',
            'created_by' => $request->user()?->id,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'created_sso_client',
            'target_type' => 'sso',
            'target_id' => (string) $client->id,
            'details' => [
                'name' => $client->name,
                'client_id' => $client->client_id,
                'framework' => $client->framework,
            ],
        ]);

        session()->flash('new_secret', $plainSecret);
        session()->flash('new_secret_client_name', $client->name);

        return back()->with('success', "SSO Client \"{$client->name}\" created successfully.");
    }

    /**
     * Update the specified SSO client in storage.
     */
    public function update(Request $request, Sso $sso): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'client_id' => 'required|string|max:255|unique:sso,client_id,'.$sso->id,
            'client_secret' => 'nullable|string|max:255',
            'redirect_uri' => 'required|string',
            'api_url' => 'nullable|url|max:500',
            'is_active' => 'boolean',
            'framework' => 'nullable|string|max:100',
            'icon' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'remove_icon' => 'nullable|boolean',
        ]);

        $iconPath = $sso->icon;
        if ($request->boolean('remove_icon')) {
            if ($sso->icon && Storage::disk('public')->exists($sso->icon)) {
                Storage::disk('public')->delete($sso->icon);
            }
            $iconPath = null;
        } elseif ($request->hasFile('icon')) {
            if ($sso->icon && Storage::disk('public')->exists($sso->icon)) {
                Storage::disk('public')->delete($sso->icon);
            }
            $iconPath = $request->file('icon')->store('sso-icons', 'public');
        }

        $updateData = [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? $sso->description,
            'client_id' => $validated['client_id'],
            'redirect_uri' => $validated['redirect_uri'],
            'api_url' => $validated['api_url'] ?? null,
            'icon' => $iconPath,
            'framework' => $validated['framework'] ?? $sso->framework,
            'is_active' => $validated['is_active'] ?? $sso->is_active,
        ];

        if (! empty($validated['client_secret'])) {
            $updateData['client_secret'] = $validated['client_secret'];
        }

        $sso->update($updateData);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'updated_sso_client',
            'target_type' => 'sso',
            'target_id' => (string) $sso->id,
            'details' => [
                'name' => $sso->name,
                'client_id' => $sso->client_id,
                'framework' => $sso->framework,
            ],
        ]);

        return back()->with('success', "SSO Client \"{$sso->name}\" updated successfully.");
    }

    /**
     * Toggle the active state of an SSO client.
     */
    public function toggle(Request $request, Sso $sso): RedirectResponse
    {
        $sso->update([
            'is_active' => ! $sso->is_active,
        ]);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => $sso->is_active ? 'enabled_sso_client' : 'disabled_sso_client',
            'target_type' => 'sso',
            'target_id' => (string) $sso->id,
            'details' => [
                'name' => $sso->name,
                'client_id' => $sso->client_id,
                'is_active' => $sso->is_active,
            ],
        ]);

        $status = $sso->is_active ? 'enabled' : 'disabled';

        return back()->with('success', "SSO Client \"{$sso->name}\" has been {$status}.");
    }

    /**
     * Explicitly enable an SSO client.
     */
    public function enable(Request $request, Sso $sso): RedirectResponse
    {
        if (! $sso->is_active) {
            $sso->update(['is_active' => true]);
            AuditLog::create([
                'admin_id' => $request->user()->id,
                'action' => 'enabled_sso_client',
                'target_type' => 'sso',
                'target_id' => (string) $sso->id,
                'details' => ['client_id' => $sso->client_id],
            ]);
        }

        return back()->with('success', "SSO Client \"{$sso->name}\" enabled.");
    }

    /**
     * Explicitly disable an SSO client.
     */
    public function disable(Request $request, Sso $sso): RedirectResponse
    {
        if ($sso->is_active) {
            $sso->update(['is_active' => false]);
            AuditLog::create([
                'admin_id' => $request->user()->id,
                'action' => 'disabled_sso_client',
                'target_type' => 'sso',
                'target_id' => (string) $sso->id,
                'details' => ['client_id' => $sso->client_id],
            ]);
        }

        return back()->with('success', "SSO Client \"{$sso->name}\" disabled.");
    }

    /**
     * Regenerate client secret for an SSO client.
     */
    public function regenerateSecret(Request $request, Sso $sso): RedirectResponse
    {
        $newSecret = Str::random(64);
        $sso->update([
            'client_secret' => $newSecret,
        ]);

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'regenerated_sso_secret',
            'target_type' => 'sso',
            'target_id' => (string) $sso->id,
            'details' => [
                'name' => $sso->name,
                'client_id' => $sso->client_id,
            ],
        ]);

        session()->flash('new_secret', $newSecret);
        session()->flash('new_secret_client_name', $sso->name);

        return back()->with('success', "New client secret generated for \"{$sso->name}\".");
    }

    /**
     * Remove the specified SSO client from storage.
     */
    public function destroy(Request $request, Sso $sso): RedirectResponse
    {
        $name = $sso->name;
        $clientId = $sso->client_id;
        $id = $sso->id;

        if ($sso->icon && Storage::disk('public')->exists($sso->icon)) {
            Storage::disk('public')->delete($sso->icon);
        }

        $sso->delete();

        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'deleted_sso_client',
            'target_type' => 'sso',
            'target_id' => (string) $id,
            'details' => [
                'name' => $name,
                'client_id' => $clientId,
            ],
        ]);

        return back()->with('success', "SSO Client \"{$name}\" was permanently deleted.");
    }
}
