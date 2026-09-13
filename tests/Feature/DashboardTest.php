<?php

namespace Tests\Feature;

use App\Models\Sso;
use App\Models\SsoUserBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_only_includes_systems_bound_to_current_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $system1 = Sso::create([
            'name' => 'System One',
            'client_id' => 'system_1',
            'client_secret' => 'secret1',
            'redirect_uri' => 'http://localhost:8001/callback',
            'icon' => 'sso-icons/sys1.png',
            'is_active' => true,
        ]);

        $system2 = Sso::create([
            'name' => 'System Two',
            'client_id' => 'system_2',
            'client_secret' => 'secret2',
            'redirect_uri' => 'http://localhost:8002/callback',
            'icon' => 'sso-icons/sys2.png',
            'is_active' => true,
        ]);

        $inactiveSystem = Sso::create([
            'name' => 'Inactive System',
            'client_id' => 'inactive_sys',
            'client_secret' => 'secret3',
            'redirect_uri' => 'http://localhost:8003/callback',
            'is_active' => false,
        ]);

        // User A is bound to System 1 and Inactive System
        SsoUserBinding::create([
            'user_id' => $userA->id,
            'client_id' => $system1->client_id,
            'external_user_id' => '100',
            'external_username' => 'user_a',
            'is_verified' => true,
        ]);
        SsoUserBinding::create([
            'user_id' => $userA->id,
            'client_id' => $inactiveSystem->client_id,
            'external_user_id' => '101',
            'external_username' => 'user_a_inactive',
            'is_verified' => true,
        ]);

        // User B is bound to System 2
        SsoUserBinding::create([
            'user_id' => $userB->id,
            'client_id' => $system2->client_id,
            'external_user_id' => '200',
            'external_username' => 'user_b',
            'is_verified' => true,
        ]);

        // When User A accesses Dashboard, they should only see System 1
        $responseA = $this->actingAs($userA)->get(route('dashboard'));
        $responseA->assertOk();
        $responseA->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('ssoClients', 1)
            ->where('ssoClients.0.name', 'System One')
            ->where('ssoClients.0.client_id', 'system_1')
            ->where('ssoClients.0.icon_url', $system1->icon_url)
            ->has('ssoClients.0.launch_url')
        );

        // When User B accesses Dashboard, they should only see System 2
        $responseB = $this->actingAs($userB)->get(route('dashboard'));
        $responseB->assertOk();
        $responseB->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('ssoClients', 1)
            ->where('ssoClients.0.name', 'System Two')
            ->where('ssoClients.0.client_id', 'system_2')
        );

        // A user with no bindings receives empty ssoClients array
        $userC = User::factory()->create();
        $responseC = $this->actingAs($userC)->get(route('dashboard'));
        $responseC->assertOk();
        $responseC->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('ssoClients', 0)
        );
    }
}
