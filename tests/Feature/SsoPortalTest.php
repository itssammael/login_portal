<?php

namespace Tests\Feature;

use App\Models\Sso;
use App\Models\SsoUserBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SsoPortalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->user = User::factory()->create(['is_admin' => false]);
    }

    public function test_non_admin_cannot_access_sso_portal(): void
    {
        $response = $this->actingAs($this->user)->get(route('admin.sso.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_sso_portal(): void
    {
        $initialCount = Sso::count();

        Sso::create([
            'name' => 'Test Portal Integration',
            'client_id' => 'test-portal',
            'client_secret' => 'super-secret-key-1234567890',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.sso.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/SsoPortal')
            ->has('clients', $initialCount + 1)
            ->has('stats')
            ->where('stats.total_clients', $initialCount + 1)
            ->where('stats.active_clients', $initialCount + 1)
            ->where('stats.inactive_clients', 0)
        );
    }

    public function test_admin_can_create_sso_client(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->post(route('admin.sso.store'), [
                'name' => 'LFEWS 2.0 Emergency System',
                'client_id' => 'lfews-emergency-client',
                'client_secret' => 'lfews-secret-key-1234567890abcdef',
                'redirect_uri' => 'http://localhost:8001/sso/callback',
                'is_active' => true,
            ]);

        $response->assertRedirect(route('admin.sso.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('sso', [
            'name' => 'LFEWS 2.0 Emergency System',
            'client_id' => 'lfews-emergency-client',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $this->admin->id,
            'action' => 'created_sso_client',
        ]);
    }

    public function test_cannot_create_sso_client_with_duplicate_client_id(): void
    {
        Sso::create([
            'name' => 'First Client',
            'client_id' => 'unique-client-id',
            'client_secret' => 'secret1',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->post(route('admin.sso.store'), [
                'name' => 'Second Client',
                'client_id' => 'unique-client-id',
                'client_secret' => 'secret2',
                'redirect_uri' => 'http://localhost:8002/sso/callback',
                'is_active' => true,
            ]);

        $response->assertSessionHasErrors(['client_id']);
    }

    public function test_admin_can_update_sso_client(): void
    {
        $client = Sso::create([
            'name' => 'Original Portal Name',
            'client_id' => 'portal-client-id',
            'client_secret' => 'original-secret',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->put(route('admin.sso.update', $client->id), [
                'name' => 'Updated Portal Name',
                'client_id' => 'portal-client-id',
                'client_secret' => 'new-secret-xyz',
                'redirect_uri' => 'http://localhost:8001/sso/v2/callback',
                'is_active' => false,
            ]);

        $response->assertRedirect(route('admin.sso.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('sso', [
            'id' => $client->id,
            'name' => 'Updated Portal Name',
            'client_secret' => 'new-secret-xyz',
            'redirect_uri' => 'http://localhost:8001/sso/v2/callback',
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $this->admin->id,
            'action' => 'updated_sso_client',
        ]);
    }

    public function test_admin_can_toggle_sso_client_active_state(): void
    {
        $client = Sso::create([
            'name' => 'Project Tracker Test',
            'client_id' => 'project-tracker-toggle-client',
            'client_secret' => 'secret123',
            'redirect_uri' => 'http://localhost:8002/sso/callback',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->post(route('admin.sso.toggle', $client->id));

        $response->assertRedirect(route('admin.sso.index'));
        $response->assertSessionHas('success');

        $this->assertFalse((bool) $client->fresh()->is_active);

        // Toggle again
        $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->post(route('admin.sso.toggle', $client->id));

        $this->assertTrue((bool) $client->fresh()->is_active);
    }

    public function test_admin_can_regenerate_client_secret(): void
    {
        $client = Sso::create([
            'name' => 'Project Tracker Secret Test',
            'client_id' => 'project-tracker-secret-client',
            'client_secret' => 'old-secret-12345678',
            'redirect_uri' => 'http://localhost:8002/sso/callback',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->post(route('admin.sso.regenerate-secret', $client->id));

        $response->assertRedirect(route('admin.sso.index'));
        $response->assertSessionHas('success');

        $fresh = $client->fresh();
        $this->assertNotEquals('old-secret-12345678', $fresh->client_secret);
        $this->assertSame(64, strlen($fresh->client_secret));

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $this->admin->id,
            'action' => 'regenerated_sso_secret',
        ]);
    }

    public function test_admin_can_delete_sso_client(): void
    {
        $client = Sso::create([
            'name' => 'Temporary Client',
            'client_id' => 'temp-client',
            'client_secret' => 'temp-secret',
            'redirect_uri' => 'http://localhost:8003/sso/callback',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->delete(route('admin.sso.destroy', $client->id));

        $response->assertRedirect(route('admin.sso.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('sso', [
            'id' => $client->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $this->admin->id,
            'action' => 'deleted_sso_client',
        ]);
    }

    public function test_inactive_client_cannot_authorize(): void
    {
        Sso::create([
            'name' => 'Disabled Portal',
            'client_id' => 'disabled-client',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'is_active' => false,
        ]);

        $response = $this->get('/sso/authorize?client_id=disabled-client&redirect_uri=http://localhost:8001/sso/callback');

        $response->assertStatus(400);
        $response->assertJson(['error' => 'unauthorized_client']);
    }

    public function test_admin_can_create_sso_client_with_icon(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('client-icon.png', 100, 100);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->post(route('admin.sso.store'), [
                'name' => 'LFEWS With Icon',
                'client_id' => 'lfews-with-icon',
                'client_secret' => 'secret1234567890',
                'redirect_uri' => 'http://localhost:8001/sso/callback',
                'is_active' => true,
                'icon' => $file,
            ]);

        $response->assertRedirect(route('admin.sso.index'));
        $response->assertSessionHas('success');

        $client = Sso::where('client_id', 'lfews-with-icon')->firstOrFail();
        $this->assertNotNull($client->icon);
        Storage::disk('public')->assertExists($client->icon);
        $this->assertStringContainsString('storage/', $client->icon_url);
    }

    public function test_admin_can_update_and_remove_sso_client_icon(): void
    {
        Storage::fake('public');

        $oldFile = UploadedFile::fake()->image('old-icon.png');
        $oldPath = $oldFile->store('sso-icons', 'public');

        $client = Sso::create([
            'name' => 'Portal To Update',
            'client_id' => 'portal-update-icon',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'icon' => $oldPath,
            'is_active' => true,
        ]);

        Storage::disk('public')->assertExists($oldPath);

        // Update with remove_icon = true
        $response = $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->put(route('admin.sso.update', $client->id), [
                'name' => 'Portal To Update',
                'client_id' => 'portal-update-icon',
                'client_secret' => 'secret',
                'redirect_uri' => 'http://localhost:8001/sso/callback',
                'is_active' => true,
                'remove_icon' => true,
            ]);

        $response->assertRedirect(route('admin.sso.index'));
        $this->assertNull($client->fresh()->icon);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_dashboard_provides_active_sso_clients_with_icon(): void
    {
        $client = Sso::create([
            'name' => 'Dashboard Visible Client',
            'client_id' => 'dash-client',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'icon' => 'sso-icons/sample.png',
            'is_active' => true,
        ]);

        SsoUserBinding::create([
            'user_id' => $this->admin->id,
            'client_id' => $client->client_id,
            'external_user_id' => '101',
            'external_username' => 'admin_external',
            'is_verified' => true,
        ]);

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('ssoClients')
            ->where('ssoClients', fn ($clients) => collect($clients)->contains('client_id', 'dash-client'))
        );
    }

    public function test_dashboard_excludes_unbound_or_inactive_sso_clients(): void
    {
        $boundActive = Sso::create([
            'name' => 'Bound Active Client',
            'client_id' => 'bound-active',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'is_active' => true,
        ]);

        $unboundActive = Sso::create([
            'name' => 'Unbound Active Client',
            'client_id' => 'unbound-active',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'is_active' => true,
        ]);

        $boundInactive = Sso::create([
            'name' => 'Bound Inactive Client',
            'client_id' => 'bound-inactive',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'is_active' => false,
        ]);

        // Only bind $boundActive and $boundInactive to $this->user
        SsoUserBinding::create([
            'user_id' => $this->user->id,
            'client_id' => $boundActive->client_id,
            'external_user_id' => '201',
            'external_username' => 'user_bound',
            'is_verified' => true,
        ]);

        SsoUserBinding::create([
            'user_id' => $this->user->id,
            'client_id' => $boundInactive->client_id,
            'external_user_id' => '202',
            'external_username' => 'user_inactive',
            'is_verified' => true,
        ]);

        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('ssoClients', 1)
            ->where('ssoClients.0.client_id', 'bound-active')
        );
    }

    public function test_admin_can_create_sso_client_with_framework(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->post(route('admin.sso.store'), [
                'name' => 'Nuxt Web Portal',
                'client_id' => 'nuxt-portal-client',
                'client_secret' => 'nuxt-secret-key-1234567890abcdef',
                'redirect_uri' => 'http://localhost:3000/sso/callback',
                'framework' => 'nuxt_node',
                'is_active' => true,
            ]);

        $response->assertRedirect(route('admin.sso.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('sso', [
            'client_id' => 'nuxt-portal-client',
            'framework' => 'nuxt_node',
        ]);

        $client = Sso::where('client_id', 'nuxt-portal-client')->firstOrFail();
        $this->assertSame('nuxt_node', $client->framework);
        $this->assertSame('Nuxt.js / Node.js', $client->framework_label);
    }

    public function test_admin_can_update_sso_client_framework(): void
    {
        $client = Sso::create([
            'name' => 'Framework Test Client',
            'client_id' => 'fw-test-client',
            'client_secret' => 'fw-secret-key',
            'redirect_uri' => 'http://localhost:8001/sso/callback',
            'framework' => 'laravel_inertia',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->from(route('admin.sso.index'))
            ->put(route('admin.sso.update', $client->id), [
                'name' => 'Framework Test Client',
                'client_id' => 'fw-test-client',
                'client_secret' => 'fw-secret-key',
                'redirect_uri' => 'http://localhost:8001/sso/callback',
                'framework' => 'vue_spa',
                'is_active' => true,
            ]);

        $response->assertRedirect(route('admin.sso.index'));
        $this->assertSame('vue_spa', $client->fresh()->framework);
        $this->assertSame('Vue.js / React (SPA)', $client->fresh()->framework_label);
    }
}
