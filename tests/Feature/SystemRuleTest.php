<?php

namespace Tests\Feature;

use App\Models\SystemRule;
use App\Models\User;
use Database\Seeders\SystemRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemRuleSeeder::class);
    }

    public function test_non_admin_cannot_access_system_rules_page(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->get(route('admin.rules.index'));

        $response->assertForbidden();
    }

    public function test_admin_can_view_system_rules_list(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.rules.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/SystemRules')
            ->has('rules')
            ->has('categories')
            ->has('ruleTypes')
            ->has('stats')
        );
    }

    public function test_admin_can_create_system_rule(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.rules.store'), [
            'name' => 'Max Attachment Size Limit',
            'key' => 'max_attachment_size_mb',
            'category' => 'chat_moderation',
            'description' => 'Maximum allowed file size for chat attachments in MB.',
            'rule_type' => 'integer',
            'value' => '25',
            'is_active' => true,
            'priority' => 5,
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('system_rules', [
            'name' => 'Max Attachment Size Limit',
            'key' => 'max_attachment_size_mb',
            'category' => 'chat_moderation',
            'value' => '25',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'created_system_rule',
        ]);
    }

    public function test_admin_can_update_system_rule(): void
    {
        $admin = User::factory()->admin()->create();
        $rule = SystemRule::firstOrFail();

        $response = $this->actingAs($admin)->put(route('admin.rules.update', $rule), [
            'name' => 'Updated Rule Name',
            'category' => $rule->category,
            'description' => 'Updated description content.',
            'rule_type' => $rule->rule_type,
            'value' => '99',
            'is_active' => true,
            'priority' => 10,
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('system_rules', [
            'id' => $rule->id,
            'name' => 'Updated Rule Name',
            'value' => '99',
            'priority' => 10,
        ]);
    }

    public function test_admin_can_toggle_system_rule_status(): void
    {
        $admin = User::factory()->admin()->create();
        $rule = SystemRule::where('is_active', true)->firstOrFail();

        $response = $this->actingAs($admin)->post(route('admin.rules.toggle', $rule));

        $response->assertSessionHas('success');
        $this->assertFalse($rule->fresh()->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'disabled_system_rule',
            'target_id' => $rule->id,
        ]);

        // Toggle back on
        $this->actingAs($admin)->post(route('admin.rules.toggle', $rule));
        $this->assertTrue($rule->fresh()->is_active);
    }

    public function test_admin_can_delete_system_rule(): void
    {
        $admin = User::factory()->admin()->create();
        $rule = SystemRule::firstOrFail();

        $response = $this->actingAs($admin)->delete(route('admin.rules.destroy', $rule));

        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('system_rules', ['id' => $rule->id]);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'deleted_system_rule',
        ]);
    }
}
