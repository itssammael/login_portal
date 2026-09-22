<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SystemAppearanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_non_admin_cannot_access_system_appearance(): void
    {
        $regularUser = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($regularUser)->get(route('admin.appearance.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_system_appearance_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.appearance.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Appearance'));
    }

    public function test_admin_can_update_system_name(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.appearance.update'), [
            'name' => 'San Fernando City Portal',
        ]);

        $response->assertSessionHas('success');
        $this->assertEquals('San Fernando City Portal', SystemSetting::get('system_name'));

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'updated_system_appearance',
        ]);
    }

    public function test_admin_can_upload_and_remove_custom_logo(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $file = UploadedFile::fake()->image('city_seal.png', 200, 200);

        $response = $this->actingAs($admin)->post(route('admin.appearance.update'), [
            'name' => 'LGUNET Portal',
            'logo' => $file,
        ]);

        $response->assertSessionHas('success');
        $logoPath = SystemSetting::get('system_logo');
        $this->assertNotNull($logoPath);
        Storage::disk('public')->assertExists($logoPath);

        // Remove logo
        $response = $this->actingAs($admin)->post(route('admin.appearance.update'), [
            'name' => 'LGUNET Portal',
            'remove_logo' => true,
        ]);

        $response->assertSessionHas('success');
        $this->assertNull(SystemSetting::get('system_logo'));
        Storage::disk('public')->assertMissing($logoPath);
    }

    public function test_admin_can_upload_and_remove_custom_favicon(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        $file = UploadedFile::fake()->image('favicon.png', 32, 32);

        $response = $this->actingAs($admin)->post(route('admin.appearance.update'), [
            'name' => 'LGUNET Portal',
            'favicon' => $file,
        ]);

        $response->assertSessionHas('success');
        $faviconPath = SystemSetting::get('system_favicon');
        $this->assertNotNull($faviconPath);
        Storage::disk('public')->assertExists($faviconPath);

        // Remove favicon
        $response = $this->actingAs($admin)->post(route('admin.appearance.update'), [
            'name' => 'LGUNET Portal',
            'remove_favicon' => true,
        ]);

        $response->assertSessionHas('success');
        $this->assertNull(SystemSetting::get('system_favicon'));
        Storage::disk('public')->assertMissing($faviconPath);
    }
}
