<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LguActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LguActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_fetch_lgu_activities_from_api(): void
    {
        LguActivity::create([
            'title' => 'Community Clean Up',
            'category' => 'Environment',
            'date' => now()->format('Y-m-d'),
            'time' => '07:00 AM',
            'location' => 'Town Plaza',
            'status' => 'upcoming',
        ]);

        $response = $this->getJson('/api/lgu-activities');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'month',
                'year',
                'month_name',
                'month_year',
                'total',
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'category',
                        'date',
                        'day',
                        'month_short',
                        'formatted_date',
                        'time',
                        'location',
                        'status',
                    ],
                ],
            ]);
    }

    public function test_can_filter_lgu_activities_by_month_and_year(): void
    {
        LguActivity::create([
            'title' => 'September Activity',
            'category' => 'Health',
            'date' => '2026-09-15',
            'status' => 'upcoming',
        ]);

        LguActivity::create([
            'title' => 'October Activity',
            'category' => 'Education',
            'date' => '2026-10-10',
            'status' => 'upcoming',
        ]);

        $response = $this->getJson('/api/lgu-activities?month=9&year=2026');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('September Activity', $response->json('data.0.title'));
    }

    public function test_can_fetch_latest_announcement_from_api(): void
    {
        $admin = User::factory()->create();

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'broadcast_announcement',
            'details' => [
                'title' => 'Server Maintenance Notice',
                'content' => 'Server upgrade will occur tonight.',
                'recipients_count' => 15,
            ],
        ]);

        $response = $this->getJson('/api/announcements/latest');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'title' => 'Server Maintenance Notice',
                    'content' => 'Server upgrade will occur tonight.',
                ],
            ]);
    }

    public function test_authenticated_user_can_access_dashboard_with_announcement(): void
    {
        $user = User::factory()->create();

        AuditLog::create([
            'admin_id' => $user->id,
            'action' => 'broadcast_announcement',
            'details' => [
                'title' => 'Welcome to New Portal',
                'content' => 'All modules are operational.',
            ],
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('latestAnnouncement')
                ->where('latestAnnouncement.title', 'Welcome to New Portal')
            );
    }
}
