<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
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

        // Verify attachment deletion removes file from disk
        Storage::fake('local');
        $filePath = 'chat_attachments/test_delete_attachment.pdf';
        Storage::disk('local')->put($filePath, 'encrypted content');

        $attachmentMsg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => null,
            'type' => 'file',
            'attachment_path' => $filePath,
            'attachment_name' => 'test_delete_attachment.pdf',
        ]);

        $this->actingAs($sender)->delete(route('chat.delete-message', $attachmentMsg));

        Storage::disk('local')->assertMissing($filePath);
        $this->assertDatabaseHas('messages', [
            'id' => $attachmentMsg->id,
            'is_deleted' => true,
            'attachment_path' => null,
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

    public function test_group_chat_without_messages_is_displayed_in_conversation_list_for_members(): void
    {
        $creator = User::factory()->create(['name' => 'Creator User']);
        $member = User::factory()->create(['name' => 'Member User']);
        $nonMember = User::factory()->create(['name' => 'Non Member User']);

        // Create group chat without sending any message
        $this->actingAs($creator)->post(route('chat.create-group'), [
            'title' => 'Alpha Team Chat',
            'user_ids' => [$member->id],
        ]);

        $group = Conversation::where('title', 'Alpha Team Chat')->first();
        $this->assertNotNull($group);
        $this->assertEquals(0, $group->messages()->count());

        // Creator should see the group in conversations list
        $creatorResponse = $this->actingAs($creator)->get(route('chat.index'));
        $creatorResponse->assertOk();
        $creatorResponse->assertInertia(fn ($page) => $page
            ->where('conversations', function ($convs): bool {
                $groupConv = collect($convs)->firstWhere('title', 'Alpha Team Chat');

                return $groupConv !== null
                    && $groupConv['type'] === 'group'
                    && ! empty($groupConv['participants']);
            })
        );

        // Member should also see the group in conversations list
        $memberResponse = $this->actingAs($member)->get(route('chat.index'));
        $memberResponse->assertOk();
        $memberResponse->assertInertia(fn ($page) => $page
            ->where('conversations', function ($convs): bool {
                $groupConv = collect($convs)->firstWhere('title', 'Alpha Team Chat');

                return $groupConv !== null
                    && $groupConv['type'] === 'group';
            })
        );

        // Non-member should NOT see the group in conversations list
        $nonMemberResponse = $this->actingAs($nonMember)->get(route('chat.index'));
        $nonMemberResponse->assertOk();
        $nonMemberResponse->assertInertia(fn ($page) => $page
            ->where('conversations', fn ($convs): bool => ! collect($convs)->contains('title', 'Alpha Team Chat')
            )
        );
    }

    public function test_user_can_paginate_messages_with_cursor(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user1->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user2->id]);

        // Create 60 messages
        for ($i = 1; $i <= 60; $i++) {
            Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $user1->id,
                'body' => "Message number {$i}",
                'type' => 'text',
            ]);
        }

        // Initial chat page load returns latest 50 messages
        $response = $this->actingAs($user1)->get(route('chat.index', ['conversation' => $conversation->id]));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('activeConversation.messages', 50)
            ->where('activeConversation.has_more', true)
            ->where('activeConversation.messages.49.body', 'Message number 60')
        );

        $firstPageOldestId = $conversation->messages()->orderByDesc('id')->take(50)->get()->last()->id;

        // Fetch older messages using cursor endpoint
        $paginationResponse = $this->actingAs($user1)->getJson(route('chat.messages', [
            'conversation' => $conversation->id,
            'before_id' => $firstPageOldestId,
        ]));

        $paginationResponse->assertOk();
        $paginationResponse->assertJsonStructure([
            'messages',
            'has_more',
            'oldest_id',
        ]);

        $responseData = $paginationResponse->json();
        $this->assertCount(10, $responseData['messages']);
        $this->assertFalse($responseData['has_more']);
        $this->assertEquals('Message number 1', $responseData['messages'][0]['body']);

        // Non-participant gets 403 Forbidden
        $outsider = User::factory()->create();
        $forbiddenResponse = $this->actingAs($outsider)->getJson(route('chat.messages', [
            'conversation' => $conversation->id,
        ]));
        $forbiddenResponse->assertForbidden();
    }

    public function test_lazy_contact_search_endpoint_returns_filtered_results(): void
    {
        $currentUser = User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $userA = User::factory()->create(['name' => 'Alice Wonderland', 'email' => 'alice@example.com']);
        $userB = User::factory()->create(['name' => 'Bob Builder', 'email' => 'bob@example.com']);
        $banned = User::factory()->banned()->create(['name' => 'Alice Banned', 'email' => 'banned@example.com']);

        // Unauthenticated user is redirected to login
        $guestResponse = $this->getJson(route('chat.contacts'));
        $guestResponse->assertUnauthorized();

        // Search for 'Alice'
        $response = $this->actingAs($currentUser)->getJson(route('chat.contacts', ['query' => 'Alice']));
        $response->assertOk();
        $response->assertJsonStructure([
            'users' => [
                '*' => ['id', 'name', 'email', 'profile_photo_url', 'is_online'],
            ],
        ]);

        $users = $response->json('users');
        $this->assertCount(1, $users);
        $this->assertEquals($userA->id, $users[0]['id']);

        // Current user should never be in contacts
        $selfSearch = $this->actingAs($currentUser)->getJson(route('chat.contacts', ['query' => 'John']));
        $selfSearch->assertOk();
        $this->assertCount(0, $selfSearch->json('users'));
    }

    public function test_message_sent_event_broadcasts_on_private_channels_only(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        $event = new MessageSent('Test text', $conversation->id, ['id' => 10], 'created');
        $channels = $event->broadcastOn();

        // No public-chat channel in broadcast channels
        foreach ($channels as $channel) {
            $this->assertInstanceOf(PrivateChannel::class, $channel);
            $this->assertNotEquals('public-chat', $channel->name);
        }

        $channelNames = array_map(fn ($c) => $c->name, $channels);
        $this->assertContains('private-chat.conversation.'.$conversation->id, $channelNames);
        $this->assertContains('private-App.Models.User.'.$sender->id, $channelNames);
        $this->assertContains('private-App.Models.User.'.$recipient->id, $channelNames);

        $payload = $event->broadcastWith();
        $this->assertEquals($conversation->id, $payload['conversation_id']);
        $this->assertEquals('created', $payload['action']);
        $this->assertContains($sender->id, $payload['participant_ids']);
        $this->assertContains($recipient->id, $payload['participant_ids']);
    }

    public function test_sidebar_conversations_do_not_load_all_messages_and_show_accurate_previews(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user1->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user2->id]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user2->id,
            'body' => 'Latest visible hello',
            'type' => 'text',
        ]);

        $response = $this->actingAs($user1)->get(route('chat.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('conversations', function ($convs): bool {
                $directConv = collect($convs)->firstWhere('type', 'direct');

                return $directConv !== null
                    && $directConv['latest_message']['body'] === 'Latest visible hello'
                    && $directConv['unread_count'] === 1;
            })
            ->where('availableUsers', [])
        );
    }

    public function test_send_message_broadcasts_full_conversation_preview_payload(): void
    {
        Event::fake([MessageSent::class]);

        $sender = User::factory()->create(['name' => 'Alice']);
        $recipient = User::factory()->create(['name' => 'Bob']);

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        $this->actingAs($sender)->post(route('chat.send-message', $conversation), [
            'body' => 'Hello preview check',
        ]);

        Event::assertDispatched(MessageSent::class, function (MessageSent $event): bool {
            $conv = $event->messageData['conversation'] ?? [];

            return isset($conv['title'], $conv['type'], $conv['other_user'], $conv['unread_count']);
        });

        // Create an attachment that predates the 50-message page
        $attachmentMessage = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => null,
            'type' => 'file',
            'attachment_path' => 'chat_attachments/old_shared_doc.pdf',
            'attachment_name' => 'old_shared_doc.pdf',
            'created_at' => now()->subHours(2),
        ]);

        // Create 52 newer messages so $attachmentMessage is pushed out of the latest 50 messages
        for ($i = 1; $i <= 52; $i++) {
            Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => "Newer message {$i}",
                'type' => 'text',
                'created_at' => now()->subHour()->addMinutes($i),
            ]);
        }

        $chatResponse = $this->actingAs($recipient)->get(route('chat.index', ['conversation' => $conversation->id]));
        $chatResponse->assertOk();
        $chatResponse->assertInertia(fn ($page) => $page
            ->where('activeConversation.messages', function ($messages) use ($attachmentMessage): bool {
                // Ensure the assertion cannot pass merely because the attachment appears on the latest page
                return ! collect($messages)->contains('id', $attachmentMessage->id);
            })
            ->where('activeConversation.shared_attachments', function ($attachments): bool {
                return collect($attachments)->contains('name', 'old_shared_doc.pdf');
            })
        );
    }

    public function test_delete_message_preserves_attachment_path_if_disk_deletion_fails(): void
    {
        $sender = User::factory()->create();
        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);

        $filePath = 'chat_attachments/cannot_delete.pdf';
        $attachmentMsg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => null,
            'type' => 'file',
            'attachment_path' => $filePath,
            'attachment_name' => 'cannot_delete.pdf',
        ]);

        $mockDisk = \Mockery::mock(Filesystem::class);
        $mockDisk->shouldReceive('exists')->with($filePath)->andReturn(true);
        $mockDisk->shouldReceive('delete')->with($filePath)->andReturn(false);

        Storage::set('local', $mockDisk);

        $this->actingAs($sender)->delete(route('chat.delete-message', $attachmentMsg));

        $this->assertDatabaseHas('messages', [
            'id' => $attachmentMsg->id,
            'is_deleted' => true,
            'attachment_path' => $filePath,
        ]);
    }

    public function test_delete_message_preserves_attachment_path_if_disk_throws_exception(): void
    {
        $sender = User::factory()->create();
        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);

        $filePath = 'chat_attachments/throwing_delete.pdf';
        $attachmentMsg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => null,
            'type' => 'file',
            'attachment_path' => $filePath,
            'attachment_name' => 'throwing_delete.pdf',
        ]);

        $mockDisk = \Mockery::mock(Filesystem::class);
        $mockDisk->shouldReceive('exists')->with($filePath)->andReturn(true);
        $mockDisk->shouldReceive('delete')->with($filePath)->andThrow(new \RuntimeException('Disk error'));

        Storage::set('local', $mockDisk);

        $this->actingAs($sender)->delete(route('chat.delete-message', $attachmentMsg));

        $this->assertDatabaseHas('messages', [
            'id' => $attachmentMsg->id,
            'is_deleted' => true,
            'attachment_path' => $filePath,
        ]);
    }

    public function test_user_can_pin_and_unpin_conversation(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        $p1 = ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user1->id, 'is_pinned' => false]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user2->id, 'is_pinned' => false]);

        $response = $this->actingAs($user1)->post(route('chat.toggle-pin', $conversation));
        $response->assertSessionHasNoErrors();
        $this->assertTrue($p1->fresh()->is_pinned);

        // Toggle again to unpin
        $this->actingAs($user1)->post(route('chat.toggle-pin', $conversation));
        $this->assertFalse($p1->fresh()->is_pinned);
    }

    public function test_user_can_mute_and_unmute_conversation(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        $p1 = ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user1->id, 'is_muted' => false]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user2->id, 'is_muted' => false]);

        $response = $this->actingAs($user1)->post(route('chat.toggle-mute', $conversation));
        $response->assertSessionHasNoErrors();
        $this->assertTrue($p1->fresh()->is_muted);

        // Toggle again to unmute
        $this->actingAs($user1)->post(route('chat.toggle-mute', $conversation));
        $this->assertFalse($p1->fresh()->is_muted);
    }

    public function test_user_can_mark_all_conversations_as_read(): void
    {
        $user = User::factory()->create();
        $conv1 = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        $conv2 = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);

        $p1 = ConversationParticipant::create(['conversation_id' => $conv1->id, 'user_id' => $user->id, 'last_read_at' => now()->subDay()]);
        $p2 = ConversationParticipant::create(['conversation_id' => $conv2->id, 'user_id' => $user->id, 'last_read_at' => null]);

        $response = $this->actingAs($user)->post(route('chat.mark-all-read'));
        $response->assertSessionHasNoErrors();

        $this->assertNotNull($p1->fresh()->last_read_at);
        $this->assertNotNull($p2->fresh()->last_read_at);
        $this->assertTrue($p1->fresh()->last_read_at->isToday());
        $this->assertTrue($p2->fresh()->last_read_at->isToday());
    }

    public function test_participant_can_mark_conversation_as_read_and_broadcasts(): void
    {
        Event::fake([MessageSent::class]);

        $user = User::factory()->create();
        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        $participant = ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'last_read_at' => null,
        ]);

        $response = $this->actingAs($user)->post(route('chat.mark-read', $conversation));
        $response->assertSessionHasNoErrors();

        $this->assertNotNull($participant->fresh()->last_read_at);

        Event::assertDispatched(MessageSent::class, function ($event) use ($conversation, $user) {
            return $event->conversationId === $conversation->id
                && $event->action === 'read'
                && $event->messageData['user_id'] === $user->id;
        });
    }

    public function test_non_participant_cannot_mark_conversation_as_read(): void
    {
        Event::fake([MessageSent::class]);

        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'last_read_at' => null,
        ]);

        $response = $this->actingAs($stranger)->post(route('chat.mark-read', $conversation));
        $response->assertStatus(403);

        Event::assertNotDispatched(MessageSent::class);
    }

    public function test_user_can_send_reply_to_message(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        $originalMsg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $recipient->id,
            'body' => 'Original question',
            'type' => 'text',
        ]);

        $response = $this->actingAs($sender)->post(route('chat.send-message', $conversation), [
            'body' => 'This is my reply',
            'reply_to_id' => $originalMsg->id,
        ]);
        $response->assertSessionHasNoErrors();

        $replyMsg = Message::where('conversation_id', $conversation->id)->where('reply_to_id', $originalMsg->id)->first();
        $this->assertNotNull($replyMsg);
        $this->assertEquals($originalMsg->id, $replyMsg->reply_to_id);
    }

    public function test_user_can_edit_own_message_within_15_minutes(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Initial text',
            'type' => 'text',
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($sender)->patch(route('chat.edit-message', $msg), [
            'body' => 'Corrected text',
        ]);
        $response->assertSessionHasNoErrors();

        $fresh = $msg->fresh();
        $this->assertNotNull($fresh->edited_at);
        $this->assertStringStartsWith('ENC::', $fresh->body);
    }

    public function test_user_cannot_edit_message_after_15_minutes(): void
    {
        $sender = User::factory()->create();
        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Old message text',
            'type' => 'text',
        ]);
        $msg->forceFill(['created_at' => now()->subMinutes(20)])->saveQuietly();

        $response = $this->actingAs($sender)->patch(route('chat.edit-message', $msg), [
            'body' => 'Trying to edit after 20 mins',
        ]);
        $response->assertStatus(403);
    }

    public function test_user_cannot_edit_another_users_message(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user1->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user2->id]);

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user1->id,
            'body' => 'User1 text',
            'type' => 'text',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user2)->patch(route('chat.edit-message', $msg), [
            'body' => 'Malicious edit',
        ]);
        $response->assertStatus(403);
    }

    public function test_typing_endpoint_broadcasts_to_conversation_participants(): void
    {
        Event::fake([MessageSent::class]);

        $sender = User::factory()->create(['name' => 'Alice']);
        $recipient = User::factory()->create(['name' => 'Bob']);

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        $response = $this->actingAs($sender)->post(route('chat.typing', $conversation));
        $response->assertOk();
        $response->assertJson(['status' => 'ok']);

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($sender, $conversation): bool {
            return $event->action === 'typing'
                && $event->conversationId === $conversation->id
                && ($event->messageData['user_id'] ?? null) === $sender->id;
        });
    }

    public function test_search_messages_in_active_conversation_returns_decrypted_matches(): void
    {
        $sender = User::factory()->create(['name' => 'Alice']);
        $recipient = User::factory()->create(['name' => 'Bob']);

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $recipient->id]);

        $senderKey = $sender->getOrCreateEncryptionKey();
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Secret strawberry project update',
            'type' => 'text',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Unrelated blueberry notice',
            'type' => 'text',
        ]);

        $response = $this->actingAs($recipient)->getJson(route('chat.search-messages', [
            'conversation' => $conversation->id,
            'q' => 'strawberry',
        ]));

        $response->assertOk();
        $data = $response->json();
        $this->assertEquals(1, $data['count']);
        $this->assertStringContainsString('strawberry', $data['results'][0]['body']);
    }

    public function test_search_messages_forbids_non_participants(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $outsider = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user1->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $user2->id]);

        $response = $this->actingAs($outsider)->getJson(route('chat.search-messages', [
            'conversation' => $conversation->id,
            'q' => 'anything',
        ]));

        $response->assertStatus(403);
    }
}
