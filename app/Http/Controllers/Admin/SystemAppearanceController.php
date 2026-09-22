<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SystemAppearanceController extends Controller
{
    /**
     * Display the System Appearance configuration page.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/Appearance', [
            'appearance' => [
                'name' => SystemSetting::get('system_name', config('app.name', 'LGUNET Portal')),
                'logo_url' => SystemSetting::getLogoUrl(),
                'favicon_url' => SystemSetting::getFaviconUrl(),
            ],
        ]);
    }

    /**
     * Update the system appearance settings.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'favicon' => 'nullable|file|mimes:ico,png,svg,jpg,jpeg,webp|max:1024',
            'remove_logo' => 'nullable|boolean',
            'remove_favicon' => 'nullable|boolean',
        ]);

        // 1. Update System Name
        SystemSetting::set('system_name', trim($validated['name']));

        // 2. Handle System Logo
        $existingLogo = SystemSetting::get('system_logo');
        if ($request->boolean('remove_logo')) {
            if ($existingLogo && Storage::disk('public')->exists($existingLogo)) {
                Storage::disk('public')->delete($existingLogo);
            }
            SystemSetting::set('system_logo', null);
        } elseif ($request->hasFile('logo')) {
            if ($existingLogo && Storage::disk('public')->exists($existingLogo)) {
                Storage::disk('public')->delete($existingLogo);
            }
            $logoPath = $request->file('logo')->store('system-appearance', 'public');
            SystemSetting::set('system_logo', $logoPath);
        }

        // 3. Handle System Favicon
        $existingFavicon = SystemSetting::get('system_favicon');
        if ($request->boolean('remove_favicon')) {
            if ($existingFavicon && Storage::disk('public')->exists($existingFavicon)) {
                Storage::disk('public')->delete($existingFavicon);
            }
            SystemSetting::set('system_favicon', null);
        } elseif ($request->hasFile('favicon')) {
            if ($existingFavicon && Storage::disk('public')->exists($existingFavicon)) {
                Storage::disk('public')->delete($existingFavicon);
            }
            $faviconPath = $request->file('favicon')->store('system-appearance', 'public');
            SystemSetting::set('system_favicon', $faviconPath);
        }

        // 4. Record Audit Log
        AuditLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'updated_system_appearance',
            'target_type' => 'system_appearance',
            'target_id' => null,
            'details' => [
                'name' => trim($validated['name']),
                'has_custom_logo' => ! empty(SystemSetting::get('system_logo')),
                'has_custom_favicon' => ! empty(SystemSetting::get('system_favicon')),
            ],
        ]);

        return back()->with('success', 'System appearance updated successfully.');
    }
}
