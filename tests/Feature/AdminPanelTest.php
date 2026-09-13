<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_user_is_forbidden_from_admin_panel(): void
    {
        $regularUser = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($regularUser)->get(route('admin.dashboard'));

        $response->assertForbidden();
    }

    public function test_admin_user_can_access_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Dashboard'));
    }

    public function test_admin_can_view_users_directory(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->create();

        $response = $this->actingAs($admin)->get(route('admin.users'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Users'));
    }

    public function test_admin_can_toggle_user_suspension(): void
    {
        $admin = User::factory()->admin()->create();
        $targetUser = User::factory()->create(['is_banned' => false]);

        $response = $this->actingAs($admin)->post(route('admin.users.toggle-ban', $targetUser));

        $response->assertSessionHas('success');
        $this->assertTrue($targetUser->fresh()->is_banned);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'banned_user',
            'target_id' => $targetUser->id,
        ]);

        // Unban
        $this->actingAs($admin)->post(route('admin.users.toggle-ban', $targetUser));
        $this->assertFalse($targetUser->fresh()->is_banned);
    }

    public function test_admin_cannot_ban_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.users.toggle-ban', $admin));

        $response->assertSessionHas('error');
        $this->assertFalse($admin->fresh()->is_banned);
    }

    public function test_admin_can_toggle_user_admin_privileges(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($admin)->post(route('admin.users.toggle-admin', $user));

        $response->assertSessionHas('success');
        $this->assertTrue($user->fresh()->is_admin);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'promoted_to_admin',
            'target_id' => $user->id,
        ]);
    }

    public function test_admin_can_moderate_and_delete_message(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'body' => 'Spam message',
            'type' => 'text',
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.messages.delete', $message), [
            'reason' => 'Spamming',
        ]);

        $response->assertSessionHas('success');
        $this->assertTrue($message->fresh()->is_deleted);

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'deleted_message',
            'target_id' => $message->id,
        ]);
    }

    public function test_admin_can_broadcast_announcement_to_all_users(): void
    {
        $admin = User::factory()->admin()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.broadcast'), [
            'title' => 'System Update Notice',
            'content' => 'New chat features are live.',
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'broadcast_announcement',
        ]);

        $this->assertDatabaseHas('messages', [
            'sender_id' => $admin->id,
            'type' => 'system',
        ]);
    }
}
