<?php

namespace Tests\Feature;

use App\Models\Sso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
