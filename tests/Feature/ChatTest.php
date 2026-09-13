<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_chat_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('chat.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Chat/Index'));
    }

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->get(route('chat.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_start_direct_conversation_with_recipient(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $response = $this->actingAs($sender)->post(route('chat.start-direct'), [
            'recipient_id' => $recipient->id,
        ]);

        $this->assertDatabaseHas('conversations', [
            'type' => 'direct',
        ]);

        $conversation = Conversation::where('type', 'direct')->first();
        $this->assertNotNull($conversation);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $sender->id,
        ]);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $recipient->id,
        ]);

        $response->assertRedirect(route('chat.index', ['conversation' => $conversation->id]));
    }

    public function test_user_can_send_encrypted_text_message(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $conversation = Conversation::create([
            'type' => 'direct',
            'last_message_at' => now(),
        ]);

        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        $response = $this->actingAs($sender)->post(route('chat.send-message', $conversation), [
            'body' => 'Hello encrypted world!',
        ]);

        $response->assertSessionHasNoErrors();

        // Encryption key record created in user_encryption_keys table
        $this->assertDatabaseHas('user_encryption_keys', [
            'user_id' => $sender->id,
        ]);

        // Message stored in DB is encrypted with ENC:: prefix
        $message = Message::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($message);
        $this->assertStringStartsWith('ENC::', $message->body);
        $this->assertStringNotContainsString('Hello encrypted world!', $message->body);

        // Fetching conversation returns decrypted content for participants
        $chatResponse = $this->actingAs($recipient)->get(route('chat.index', ['conversation' => $conversation->id]));
        $chatResponse->assertOk();
        $chatResponse->assertInertia(fn ($page) => $page
            ->where('activeConversation.messages.0.body', 'Hello encrypted world!')
        );
    }

    public function test_user_can_toggle_reaction_on_message(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Test message',
            'type' => 'text',
        ]);

        // Add reaction
        $this->actingAs($recipient)->post(route('chat.reaction', $message), [
            'reaction' => 'love',
        ]);

        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $recipient->id,
            'reaction' => 'love',
        ]);

        // Toggle reaction off
        $this->actingAs($recipient)->post(route('chat.reaction', $message), [
            'reaction' => 'love',
        ]);

        $this->assertDatabaseMissing('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $recipient->id,
        ]);
    }

    public function test_user_can_delete_own_message(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Message to be deleted',
            'type' => 'text',
        ]);

        $this->actingAs($sender)->delete(route('chat.delete-message', $message));

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'is_deleted' => true,
            'body' => null,
        ]);
    }

    public function test_banned_user_cannot_access_chat(): void
    {
        $bannedUser = User::factory()->banned()->create();

        $response = $this->actingAs($bannedUser)->get(route('chat.index'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
    }

    public function test_user_can_create_group_chat_with_multiple_members(): void
    {
        $creator = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $response = $this->actingAs($creator)->post(route('chat.create-group'), [
            'title' => 'Project Alpha Team',
            'user_ids' => [$member1->id, $member2->id],
        ]);

        $this->assertDatabaseHas('conversations', [
            'type' => 'group',
            'title' => 'Project Alpha Team',
        ]);

        $conversation = Conversation::where('title', 'Project Alpha Team')->first();
        $this->assertNotNull($conversation);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $creator->id,
        ]);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member1->id,
        ]);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member2->id,
        ]);

        $response->assertRedirect(route('chat.index', ['conversation' => $conversation->id]));
    }

    public function test_attachment_access_is_restricted_to_conversation_participants(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $unauthorizedUser = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        $file = UploadedFile::fake()->create('secret_document.pdf', 100, 'application/pdf');

        $this->actingAs($sender)->post(route('chat.send-message', $conversation), [
            'attachment' => $file,
        ]);

        $message = Message::where('conversation_id', $conversation->id)->whereNotNull('attachment_path')->first();
        $this->assertNotNull($message);

        // Verify file on disk is encrypted (starts with ENC_FILE::)
        $rawFileOnDisk = Storage::disk('local')->get($message->attachment_path);
        $this->assertStringStartsWith('ENC_FILE::', $rawFileOnDisk);

        // Unauthorized user receives 403 Forbidden
        $forbiddenResponse = $this->actingAs($unauthorizedUser)->get(route('chat.download-attachment', $message));
        $forbiddenResponse->assertForbidden();

        // Authorized conversation participant receives decrypted file content
        $allowedResponse = $this->actingAs($recipient)->get(route('chat.download-attachment', $message));
        $allowedResponse->assertOk();
        $allowedResponse->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_system_messages_are_not_displayed_in_chat_list_for_sender(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => '📢 Announcement',
            'type' => 'system',
        ]);

        // Sender does not see system message in direct conversation with recipient
        $senderResponse = $this->actingAs($sender)->get(route('chat.index'));
        $senderResponse->assertOk();
        $senderResponse->assertInertia(fn ($page) => $page
            ->where('conversations.0.type', 'system')
            ->has('conversations', 1)
        );

        // Recipient does see the system message in chat list
        $recipientResponse = $this->actingAs($recipient)->get(route('chat.index', ['conversation' => $conversation->id]));
        $recipientResponse->assertOk();
        $recipientResponse->assertInertia(fn ($page) => $page
            ->where('activeConversation.messages.0.body', '📢 Announcement')
        );
    }

    public function test_system_notification_conversations_cannot_be_replied_to_or_deleted(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        $sysConv = Conversation::getOrCreateSystemConversationForUser($user);

        $message = Message::create([
            'conversation_id' => $sysConv->id,
            'sender_id' => $admin->id,
            'body' => '📢 Maintenance Notice',
            'type' => 'system',
        ]);

        // Replying to system conversation returns 403 Forbidden
        $replyResponse = $this->actingAs($user)->post(route('chat.send-message', $sysConv), [
            'body' => 'My reply',
        ]);
        $replyResponse->assertForbidden();

        // Deleting system message returns 403 Forbidden
        $deleteResponse = $this->actingAs($user)->delete(route('chat.delete-message', $message));
        $deleteResponse->assertForbidden();
    }
}
