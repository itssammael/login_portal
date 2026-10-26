<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\MessageReaction;
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

    public function test_group_creator_is_assigned_admin_role_and_members_are_regular_members(): void
    {
        $creator = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $response = $this->actingAs($creator)->post(route('chat.create-group'), [
            'title' => 'Core Architecture Team',
            'user_ids' => [$member1->id, $member2->id],
        ]);

        $conversation = Conversation::where('title', 'Core Architecture Team')->first();
        $this->assertNotNull($conversation);
        $this->assertTrue($conversation->isGroup());

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $creator->id,
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member1->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member2->id,
            'role' => 'member',
        ]);

        $this->assertTrue($conversation->isAdmin($creator));
        $this->assertFalse($conversation->isAdmin($member1));
    }

    public function test_group_admin_can_rename_group(): void
    {
        Event::fake([MessageSent::class]);

        $admin = User::factory()->create();
        $member = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Old Title', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member->id, 'role' => 'member']);

        $response = $this->actingAs($admin)->patch(route('chat.rename-group', $conversation), [
            'title' => 'New Shiny Group Name',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertEquals('New Shiny Group Name', $conversation->fresh()->title);

        Event::assertDispatched(MessageSent::class, function ($event) use ($conversation) {
            return $event->conversationId === $conversation->id
                && $event->action === 'group_renamed'
                && $event->messageData['title'] === 'New Shiny Group Name';
        });
    }

    public function test_non_admin_member_cannot_rename_group(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Original Title', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member->id, 'role' => 'member']);

        $response = $this->actingAs($member)->patch(route('chat.rename-group', $conversation), [
            'title' => 'Hacked Title',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('Original Title', $conversation->fresh()->title);
    }

    public function test_group_admin_can_add_members_to_group(): void
    {
        Event::fake([MessageSent::class]);

        $admin = User::factory()->create();
        $newMember = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Engineering', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('chat.add-members', $conversation), [
            'user_ids' => [$newMember->id],
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $newMember->id,
            'role' => 'member',
        ]);

        Event::assertDispatched(MessageSent::class, function ($event) use ($conversation, $newMember) {
            return $event->conversationId === $conversation->id
                && $event->action === 'members_added'
                && $event->messageData['participants'][0]['id'] === $newMember->id
                && isset($event->messageData['conversation'])
                && $event->messageData['conversation']['id'] === $conversation->id;
        });
    }

    public function test_non_admin_member_cannot_add_members_to_group(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Engineering', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member->id, 'role' => 'member']);

        $response = $this->actingAs($member)->post(route('chat.add-members', $conversation), [
            'user_ids' => [$stranger->id],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $stranger->id,
        ]);
    }

    public function test_group_admin_can_remove_non_admin_member(): void
    {
        Event::fake([MessageSent::class]);

        $admin = User::factory()->create();
        $member = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Frontend Guild', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member->id, 'role' => 'member']);

        $response = $this->actingAs($admin)->delete(route('chat.remove-member', [$conversation, $member]));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
        ]);

        Event::assertDispatched(MessageSent::class, function ($event) use ($conversation, $member, $admin) {
            return $event->conversationId === $conversation->id
                && $event->action === 'member_removed'
                && $event->messageData['user_id'] === $member->id
                && in_array($member->id, $event->recipientUserIds ?? [])
                && in_array($admin->id, $event->recipientUserIds ?? []);
        });

        // Ensure server-side access checks immediately exclude the removed member
        $this->actingAs($member)->post(route('chat.send-message', $conversation), [
            'message' => 'Should be forbidden',
        ])->assertStatus(403);
    }

    public function test_non_admin_member_cannot_remove_member(): void
    {
        $admin = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Frontend Guild', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member1->id, 'role' => 'member']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member2->id, 'role' => 'member']);

        $response = $this->actingAs($member1)->delete(route('chat.remove-member', [$conversation, $member2]));
        $response->assertStatus(403);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member2->id,
        ]);
    }

    public function test_admin_cannot_remove_another_admin(): void
    {
        $admin1 = User::factory()->create();
        $admin2 = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Leadership', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin1->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin2->id, 'role' => 'admin']);

        $response = $this->actingAs($admin1)->delete(route('chat.remove-member', [$conversation, $admin2]));
        $response->assertStatus(422);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $admin2->id,
            'role' => 'admin',
        ]);
    }

    public function test_group_admin_can_promote_member_to_admin(): void
    {
        Event::fake([MessageSent::class]);

        $admin = User::factory()->create();
        $member = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Backend Guild', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member->id, 'role' => 'member']);

        $response = $this->actingAs($admin)->post(route('chat.promote-member', [$conversation, $member]));
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member->id,
            'role' => 'admin',
        ]);

        Event::assertDispatched(MessageSent::class, function ($event) use ($conversation, $member) {
            return $event->conversationId === $conversation->id
                && $event->action === 'member_promoted'
                && $event->messageData['user_id'] === $member->id
                && $event->messageData['role'] === 'admin';
        });
    }

    public function test_non_admin_member_cannot_promote_member(): void
    {
        $admin = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Backend Guild', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member1->id, 'role' => 'member']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member2->id, 'role' => 'member']);

        $response = $this->actingAs($member1)->post(route('chat.promote-member', [$conversation, $member2]));
        $response->assertStatus(403);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'user_id' => $member2->id,
            'role' => 'member',
        ]);
    }

    public function test_promoting_already_admin_member_is_rejected(): void
    {
        $admin1 = User::factory()->create();
        $admin2 = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Executive', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin1->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin2->id, 'role' => 'admin']);

        $response = $this->actingAs($admin1)->post(route('chat.promote-member', [$conversation, $admin2]));
        $response->assertStatus(422);
    }

    public function test_group_admin_can_delete_group_and_associated_messages_and_attachments(): void
    {
        Event::fake([MessageSent::class]);
        Storage::fake('local');
        Storage::fake('public');

        $admin = User::factory()->create();
        $member = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Temporary Squad', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member->id, 'role' => 'member']);

        $attachmentPath = 'chat-attachments/temp_file.pdf';
        Storage::disk('local')->put($attachmentPath, 'Sample content');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $admin->id,
            'body' => 'Important document',
            'type' => 'file',
            'attachment_path' => $attachmentPath,
            'attachment_name' => 'temp_file.pdf',
        ]);

        $response = $this->actingAs($admin)->delete(route('chat.delete-group', $conversation));
        $response->assertRedirect(route('chat.index'));

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('conversation_participants', ['conversation_id' => $conversation->id]);

        Storage::disk('local')->assertMissing($attachmentPath);

        Event::assertDispatched(MessageSent::class, function ($event) use ($conversation, $admin, $member) {
            return $event->conversationId === $conversation->id
                && $event->action === 'group_deleted'
                && in_array($admin->id, $event->recipientUserIds ?? [])
                && in_array($member->id, $event->recipientUserIds ?? []);
        });
    }

    public function test_message_sent_preserves_channels_from_recipient_snapshot_even_after_participants_deleted(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Test Channels', 'last_message_at' => now()]);

        // Simulating post-deletion state where conversation_participants rows are gone
        $event = new MessageSent(
            'Snapshot test',
            $conversation->id,
            ['some' => 'data'],
            'member_removed',
            [$admin->id, $member->id]
        );

        $channels = $event->broadcastOn();
        $channelNames = array_map(fn ($c) => $c->name, $channels);

        $this->assertContains('private-chat.conversation.'.$conversation->id, $channelNames);
        $this->assertContains('private-App.Models.User.'.$admin->id, $channelNames);
        $this->assertContains('private-App.Models.User.'.$member->id, $channelNames);

        $payload = $event->broadcastWith();
        $this->assertContains($admin->id, $payload['participant_ids']);
        $this->assertContains($member->id, $payload['participant_ids']);
    }

    public function test_non_admin_member_cannot_delete_group(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $conversation = Conversation::create(['type' => 'group', 'title' => 'Permanent Group', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $admin->id, 'role' => 'admin']);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $member->id, 'role' => 'member']);

        $response = $this->actingAs($member)->delete(route('chat.delete-group', $conversation));
        $response->assertStatus(403);

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    public function test_admin_actions_cannot_be_performed_on_direct_conversations(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $directConv = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $directConv->id, 'user_id' => $user1->id]);
        ConversationParticipant::create(['conversation_id' => $directConv->id, 'user_id' => $user2->id]);

        $this->actingAs($user1)->patch(route('chat.rename-group', $directConv), ['title' => 'Direct Rename'])->assertStatus(403);
        $this->actingAs($user1)->post(route('chat.add-members', $directConv), ['user_ids' => [$user2->id]])->assertStatus(403);
        $this->actingAs($user1)->delete(route('chat.remove-member', [$directConv, $user2]))->assertStatus(403);
        $this->actingAs($user1)->post(route('chat.promote-member', [$directConv, $user2]))->assertStatus(403);
        $this->actingAs($user1)->delete(route('chat.delete-group', $directConv))->assertStatus(403);
    }

    public function test_search_conversations_excludes_current_user_name_match_but_matches_other_participants(): void
    {
        $currentUser = User::factory()->create(['name' => 'Alice Wonderland']);
        $bob = User::factory()->create(['name' => 'Bob Smith']);
        $charlie = User::factory()->create(['name' => 'Charlie Brown']);

        $convBob = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $convBob->id, 'user_id' => $currentUser->id]);
        ConversationParticipant::create(['conversation_id' => $convBob->id, 'user_id' => $bob->id]);

        $convCharlie = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $convCharlie->id, 'user_id' => $currentUser->id]);
        ConversationParticipant::create(['conversation_id' => $convCharlie->id, 'user_id' => $charlie->id]);

        // Searching for current user's own name should NOT match conversations via participant name
        $response = $this->actingAs($currentUser)->getJson(route('chat.search-conversations', ['q' => 'Alice']));
        $response->assertOk();
        $response->assertJsonCount(0, 'conversations');

        // Searching for another participant's name should match
        $responseBob = $this->actingAs($currentUser)->getJson(route('chat.search-conversations', ['q' => 'Bob']));
        $responseBob->assertOk();
        $responseBob->assertJsonCount(1, 'conversations');
        $this->assertEquals($convBob->id, $responseBob->json('conversations.0.id'));

        // Group title matching should still match even if title includes query
        $groupConv = Conversation::create(['type' => 'group', 'title' => 'Alice Group Project', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $groupConv->id, 'user_id' => $currentUser->id]);

        $responseGroup = $this->actingAs($currentUser)->getJson(route('chat.search-conversations', ['q' => 'Alice']));
        $responseGroup->assertOk();
        $responseGroup->assertJsonCount(1, 'conversations');
        $this->assertEquals($groupConv->id, $responseGroup->json('conversations.0.id'));
    }

    public function test_start_direct_queries_by_direct_key_first(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $directKey = Conversation::generateDirectKey($user1->id, $user2->id);

        $existing = Conversation::create([
            'type' => 'direct',
            'direct_key' => $directKey,
            'last_message_at' => now(),
        ]);
        ConversationParticipant::create(['conversation_id' => $existing->id, 'user_id' => $user1->id]);
        ConversationParticipant::create(['conversation_id' => $existing->id, 'user_id' => $user2->id]);

        $response = $this->actingAs($user1)->post(route('chat.start-direct'), [
            'recipient_id' => $user2->id,
        ]);

        $response->assertRedirect(route('chat.index', ['conversation' => $existing->id]));
        $this->assertSame(1, Conversation::where('type', 'direct')->count());
    }

    public function test_start_direct_falls_back_deterministically_to_unkeyed_conversation(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $directKey = Conversation::generateDirectKey($user1->id, $user2->id);

        $olderUnkeyed = Conversation::create([
            'type' => 'direct',
            'direct_key' => null,
            'last_message_at' => now(),
        ]);
        ConversationParticipant::create(['conversation_id' => $olderUnkeyed->id, 'user_id' => $user1->id]);
        ConversationParticipant::create(['conversation_id' => $olderUnkeyed->id, 'user_id' => $user2->id]);

        $newerUnkeyed = Conversation::create([
            'type' => 'direct',
            'direct_key' => null,
            'last_message_at' => now(),
        ]);
        ConversationParticipant::create(['conversation_id' => $newerUnkeyed->id, 'user_id' => $user1->id]);
        ConversationParticipant::create(['conversation_id' => $newerUnkeyed->id, 'user_id' => $user2->id]);

        $response = $this->actingAs($user1)->post(route('chat.start-direct'), [
            'recipient_id' => $user2->id,
        ]);

        // Deterministically selects the older one (ordered by id)
        $response->assertRedirect(route('chat.index', ['conversation' => $olderUnkeyed->id]));
        $this->assertEquals($directKey, $olderUnkeyed->fresh()->direct_key);
        $this->assertNull($newerUnkeyed->fresh()->direct_key);
    }

    public function test_mention_creation_in_group_chat_records_mention_for_target_user_only(): void
    {
        $sender = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob Smith']);
        $charlie = User::factory()->create(['name' => 'Charlie Brown']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Project Group', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $bob->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $charlie->id]);

        $response = $this->actingAs($sender)->post(route('chat.send-message', $group), [
            'body' => 'Hello @Bob Smith, please review this draft!',
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('message_mentions', [
            'conversation_id' => $group->id,
            'user_id' => $bob->id,
        ]);

        $this->assertDatabaseMissing('message_mentions', [
            'conversation_id' => $group->id,
            'user_id' => $charlie->id,
        ]);

        $this->assertDatabaseMissing('message_mentions', [
            'conversation_id' => $group->id,
            'user_id' => $sender->id,
        ]);
    }

    public function test_repeated_mentions_in_one_message_do_not_duplicate_records(): void
    {
        $sender = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob', 'username' => 'bobby']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Dev Team', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $bob->id]);

        $this->actingAs($sender)->post(route('chat.send-message', $group), [
            'body' => 'Hey @bobby, take a look! Also pinging @bobby again.',
        ]);

        $this->assertSame(1, MessageMention::where('conversation_id', $group->id)->where('user_id', $bob->id)->count());
    }

    public function test_sender_mentioning_self_does_not_create_mention_record(): void
    {
        $sender = User::factory()->create(['name' => 'Alice', 'username' => 'alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Dev Team', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $bob->id]);

        $this->actingAs($sender)->post(route('chat.send-message', $group), [
            'body' => 'Note to self: @alice update deployment docs',
        ]);

        $this->assertSame(0, MessageMention::where('user_id', $sender->id)->count());
    }

    public function test_chat_list_shows_unread_mention_indicator_only_to_mentioned_user(): void
    {
        $sender = User::factory()->create(['name' => 'Alice']);
        $mentionedUser = User::factory()->create(['name' => 'Seanna', 'username' => 'seanna']);
        $otherUser = User::factory()->create(['name' => 'David']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Design Sync', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $sender->id, 'last_read_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $mentionedUser->id, 'last_read_at' => now()->subHour()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $otherUser->id, 'last_read_at' => now()->subHour()]);

        $this->actingAs($sender)->post(route('chat.send-message', $group), [
            'body' => 'Welcome to the team @seanna!',
        ]);

        // Mentioned user views chat index (list of conversations)
        $responseMentioned = $this->actingAs($mentionedUser)->get(route('chat.index'));
        $responseMentioned->assertOk();
        $responseMentioned->assertInertia(function ($page) use ($group) {
            $convs = collect($page->toArray()['props']['conversations']);
            $target = $convs->firstWhere('id', $group->id);
            $this->assertNotNull($target);
            $this->assertTrue($target['has_unread_mentions']);
            $this->assertEquals(1, $target['unread_mentions_count']);

            return true;
        });

        // Other user views chat index: has unread message but NO unread mention
        $responseOther = $this->actingAs($otherUser)->get(route('chat.index'));
        $responseOther->assertOk();
        $responseOther->assertInertia(function ($page) use ($group) {
            $convs = collect($page->toArray()['props']['conversations']);
            $target = $convs->firstWhere('id', $group->id);
            $this->assertNotNull($target);
            $this->assertFalse($target['has_unread_mentions']);
            $this->assertEquals(0, $target['unread_mentions_count']);
            $this->assertEquals(1, $target['unread_count']);

            return true;
        });
    }

    public function test_viewing_conversation_clears_unread_mention_indicator(): void
    {
        $sender = User::factory()->create(['name' => 'Alice']);
        $mentionedUser = User::factory()->create(['name' => 'Seanna', 'username' => 'seanna']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Design Sync', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $sender->id, 'last_read_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $mentionedUser->id, 'last_read_at' => now()->subHour()]);

        $this->actingAs($sender)->post(route('chat.send-message', $group), [
            'body' => 'Welcome @seanna!',
        ]);

        // Viewing the conversation explicitly via query param
        $response = $this->actingAs($mentionedUser)->get(route('chat.index', ['conversation' => $group->id]));
        $response->assertOk();
        $response->assertInertia(function ($page) use ($group) {
            $convs = collect($page->toArray()['props']['conversations']);
            $target = $convs->firstWhere('id', $group->id);
            $this->assertNotNull($target);
            $this->assertFalse($target['has_unread_mentions']);
            $this->assertEquals(0, $target['unread_mentions_count']);
            $this->assertEquals(0, $target['unread_count']);

            return true;
        });
    }

    public function test_overlapping_names_resolve_to_longest_full_name_participant_only(): void
    {
        $sender = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $bobSmith = User::factory()->create(['name' => 'Bob Smith']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Devs', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $bob->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $bobSmith->id]);

        $this->actingAs($sender)->post(route('chat.send-message', $group), [
            'body' => 'Hello @Bob Smith please check this',
        ]);

        $this->assertDatabaseHas('message_mentions', [
            'conversation_id' => $group->id,
            'user_id' => $bobSmith->id,
        ]);

        $this->assertDatabaseMissing('message_mentions', [
            'conversation_id' => $group->id,
            'user_id' => $bob->id,
        ]);
    }

    public function test_mention_must_be_preceded_by_start_of_message_or_whitespace(): void
    {
        $sender = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Devs', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $bob->id]);

        $this->actingAs($sender)->post(route('chat.send-message', $group), [
            'body' => 'myemail@Bob is not a mention',
        ]);

        $this->assertSame(0, MessageMention::where('conversation_id', $group->id)->count());
    }

    public function test_editing_message_reconciles_mentions_and_broadcasts_ids(): void
    {
        Event::fake([MessageSent::class]);

        $sender = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob', 'username' => 'bobby']);
        $charlie = User::factory()->create(['name' => 'Charlie', 'username' => 'charlie']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Devs', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $sender->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $bob->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $charlie->id]);

        $this->actingAs($sender)->post(route('chat.send-message', $group), [
            'body' => 'Hey @bobby check this',
        ]);

        $message = Message::where('conversation_id', $group->id)->first();
        $this->assertNotNull($message);
        $this->assertDatabaseHas('message_mentions', [
            'message_id' => $message->id,
            'user_id' => $bob->id,
        ]);

        // Edit message to mention Charlie instead of Bob
        $response = $this->actingAs($sender)->patch(route('chat.edit-message', $message), [
            'body' => 'Hey @charlie check this instead',
        ]);

        $response->assertSessionHasNoErrors();

        // Mention for Bob is removed, mention for Charlie is created
        $this->assertDatabaseMissing('message_mentions', [
            'message_id' => $message->id,
            'user_id' => $bob->id,
        ]);
        $this->assertDatabaseHas('message_mentions', [
            'message_id' => $message->id,
            'user_id' => $charlie->id,
        ]);

        // Verify broadcast payload
        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($bob, $charlie) {
            if ($event->action !== 'edited') {
                return false;
            }
            $data = $event->messageData;

            return in_array($charlie->id, $data['mentioned_user_ids'], true)
                && in_array($bob->id, $data['previous_mentioned_user_ids'], true);
        });
    }

    public function test_user_can_delete_message_for_themselves_only(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $userA->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $userB->id]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userB->id,
            'body' => 'Secret message for user A',
            'type' => 'text',
            'is_deleted' => false,
        ]);

        $response = $this->actingAs($userA)->post(route('chat.delete-message-for-me', $message));
        $response->assertSessionHasNoErrors();

        // Recorded in message_user_deletions for user A
        $this->assertDatabaseHas('message_user_deletions', [
            'message_id' => $message->id,
            'user_id' => $userA->id,
        ]);

        // Original message is NOT deleted globally
        $this->assertFalse($message->fresh()->is_deleted);

        // User A sees "User has deleted a message" in messages cursor endpoint, and original content is not returned
        $responseA = $this->actingAs($userA)->getJson(route('chat.messages', $conversation));
        $responseA->assertOk();
        $this->assertCount(1, $responseA->json('messages'));
        $this->assertEquals('User has deleted a message', $responseA->json('messages.0.body'));
        $this->assertTrue($responseA->json('messages.0.is_deleted_for_me'));
        $this->assertStringNotContainsString('Secret message for user A', $responseA->getContent());

        // User B CAN still see the original message normally
        $responseB = $this->actingAs($userB)->getJson(route('chat.messages', $conversation));
        $responseB->assertOk();
        $this->assertCount(1, $responseB->json('messages'));
        $this->assertEquals($message->id, $responseB->json('messages.0.id'));
        $this->assertEquals('Secret message for user A', $responseB->json('messages.0.body'));
        $this->assertFalse($responseB->json('messages.0.is_deleted_for_me'));

        // Subsequent page loads persist the replacement for deleting user
        $pageLoadA = $this->actingAs($userA)->get(route('chat.index', ['conversation' => $conversation->id]));
        $pageLoadA->assertOk();
        $pageLoadA->assertInertia(fn ($page) => $page
            ->where('activeConversation.messages.0.body', 'User has deleted a message')
            ->where('activeConversation.messages.0.is_deleted_for_me', true)
            ->where('conversations', function ($convs) use ($conversation) {
                $target = collect($convs)->firstWhere('id', $conversation->id);

                return $target
                    && $target['latest_message']['body'] === 'User has deleted a message'
                    && $target['latest_message']['is_deleted_for_me'] === true;
            })
        );
        $this->assertStringNotContainsString('Secret message for user A', $pageLoadA->getContent());

        // Subsequent page loads for other members still show the original message
        $pageLoadB = $this->actingAs($userB)->get(route('chat.index', ['conversation' => $conversation->id]));
        $pageLoadB->assertOk();
        $pageLoadB->assertInertia(fn ($page) => $page
            ->where('activeConversation.messages.0.body', 'Secret message for user A')
            ->where('activeConversation.messages.0.is_deleted_for_me', false)
            ->where('conversations', function ($convs) use ($conversation) {
                $target = collect($convs)->firstWhere('id', $conversation->id);

                return $target
                    && $target['latest_message']['body'] === 'Secret message for user A'
                    && $target['latest_message']['is_deleted_for_me'] === false;
            })
        );
    }

    public function test_deleted_for_me_message_does_not_leak_attachments_replies_or_mentions_and_blocks_actions(): void
    {
        Storage::fake('local');

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $userA->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $userB->id]);

        $filePath = 'chat_attachments/confidential_file.pdf';
        Storage::disk('local')->put($filePath, 'Confidential binary data');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userB->id,
            'body' => 'Sensitive message with file',
            'type' => 'file',
            'attachment_path' => $filePath,
            'attachment_name' => 'confidential_file.pdf',
            'attachment_type' => 'application/pdf',
            'is_deleted' => false,
        ]);

        $replyMessage = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userB->id,
            'reply_to_id' => $message->id,
            'body' => 'Replying to sensitive message',
            'type' => 'text',
            'is_deleted' => false,
        ]);

        // Add reaction from user B
        MessageReaction::create([
            'message_id' => $message->id,
            'user_id' => $userB->id,
            'reaction' => 'fire',
        ]);

        // User A deletes message for themselves
        $this->actingAs($userA)->post(route('chat.delete-message-for-me', $message))->assertSessionHasNoErrors();

        // 1. User A view: attachments, reactions, mentions are stripped/hidden
        $responseA = $this->actingAs($userA)->getJson(route('chat.messages', $conversation));
        $responseA->assertOk();
        $messagesA = collect($responseA->json('messages'))->keyBy('id');

        $deletedForMeMsg = $messagesA->get($message->id);
        $this->assertEquals('User has deleted a message', $deletedForMeMsg['body']);
        $this->assertTrue($deletedForMeMsg['is_deleted_for_me']);
        $this->assertNull($deletedForMeMsg['attachment_url']);
        $this->assertNull($deletedForMeMsg['attachment_name']);
        $this->assertEmpty($deletedForMeMsg['reactions']);

        // The reply quoting this message also masks the quote body for user A
        $replyMsgA = $messagesA->get($replyMessage->id);
        $this->assertEquals('User has deleted a message', $replyMsgA['reply_to']['body']);
        $this->assertTrue($replyMsgA['reply_to']['is_deleted_for_me']);

        // 2. User A cannot download the attachment (returns 404)
        $this->actingAs($userA)->get(route('chat.download-attachment', $message))->assertNotFound();

        // 3. User A cannot react to or edit the deleted message (returns 403)
        $this->actingAs($userA)->post(route('chat.reaction', $message), ['reaction' => 'like'])->assertForbidden();

        // 4. Other member (User B) still sees original attachment, quote, and can download
        $responseB = $this->actingAs($userB)->getJson(route('chat.messages', $conversation));
        $responseB->assertOk();
        $messagesB = collect($responseB->json('messages'))->keyBy('id');

        $userBMsg = $messagesB->get($message->id);
        $this->assertEquals('Sensitive message with file', $userBMsg['body']);
        $this->assertFalse($userBMsg['is_deleted_for_me']);
        $this->assertNotNull($userBMsg['attachment_url']);
        $this->assertEquals('confidential_file.pdf', $userBMsg['attachment_name']);
        $this->assertCount(1, $userBMsg['reactions']);

        $replyMsgB = $messagesB->get($replyMessage->id);
        $this->assertEquals('Sensitive message with file', $replyMsgB['reply_to']['body']);
        $this->assertFalse($replyMsgB['reply_to']['is_deleted_for_me']);

        $this->actingAs($userB)->get(route('chat.download-attachment', $message))->assertOk();
    }

    public function test_non_participant_cannot_delete_message_for_themselves(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $outsider = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $userA->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $userB->id]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userA->id,
            'body' => 'Private message',
            'type' => 'text',
        ]);

        $response = $this->actingAs($outsider)->post(route('chat.delete-message-for-me', $message));
        $response->assertForbidden();

        $this->assertDatabaseMissing('message_user_deletions', [
            'message_id' => $message->id,
            'user_id' => $outsider->id,
        ]);
    }

    public function test_group_members_can_view_reaction_details_with_timestamps(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $charlie = User::factory()->create(['name' => 'Charlie']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Project Team', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $alice->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $bob->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $charlie->id]);

        $message = Message::create([
            'conversation_id' => $group->id,
            'sender_id' => $alice->id,
            'body' => 'Great work team!',
            'type' => 'text',
        ]);

        MessageReaction::create([
            'message_id' => $message->id,
            'user_id' => $bob->id,
            'reaction' => 'love',
            'created_at' => now()->subMinutes(5),
        ]);

        MessageReaction::create([
            'message_id' => $message->id,
            'user_id' => $charlie->id,
            'reaction' => 'fire',
            'created_at' => now()->subMinute(),
        ]);

        // Alice views reaction details
        $response = $this->actingAs($alice)->getJson(route('chat.message-reactions', $message));
        $response->assertOk();
        $response->assertJsonStructure([
            'message_id',
            'reactions' => [
                '*' => [
                    'id',
                    'reaction',
                    'user' => ['id', 'name', 'username', 'profile_photo_url'],
                    'created_at',
                    'created_at_relative',
                ],
            ],
        ]);

        $reactions = $response->json('reactions');
        $this->assertCount(2, $reactions);
        $this->assertEquals('Bob', $reactions[0]['user']['name']);
        $this->assertEquals('love', $reactions[0]['reaction']);
        $this->assertNotNull($reactions[0]['created_at']);
        $this->assertEquals('Charlie', $reactions[1]['user']['name']);
        $this->assertEquals('fire', $reactions[1]['reaction']);
    }

    public function test_repeated_reaction_selection_does_not_create_duplicate_records(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $userA->id]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $userB->id]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userA->id,
            'body' => 'Test message',
            'type' => 'text',
        ]);

        // First react: creates reaction
        $this->actingAs($userB)->post(route('chat.reaction', $message), ['reaction' => 'like']);
        $this->assertEquals(1, MessageReaction::where('message_id', $message->id)->where('user_id', $userB->id)->count());

        // Repeated react with same emoji: toggles off (removes reaction)
        $this->actingAs($userB)->post(route('chat.reaction', $message), ['reaction' => 'like']);
        $this->assertEquals(0, MessageReaction::where('message_id', $message->id)->where('user_id', $userB->id)->count());

        // React again: creates 1 reaction (never duplicates)
        $this->actingAs($userB)->post(route('chat.reaction', $message), ['reaction' => 'like']);
        $this->assertEquals(1, MessageReaction::where('message_id', $message->id)->where('user_id', $userB->id)->count());
    }

    public function test_non_participant_cannot_view_reaction_details(): void
    {
        $userA = User::factory()->create();
        $outsider = User::factory()->create();

        $conversation = Conversation::create(['type' => 'direct', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $userA->id]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $userA->id,
            'body' => 'Private message',
            'type' => 'text',
        ]);

        $response = $this->actingAs($outsider)->getJson(route('chat.message-reactions', $message));
        $response->assertForbidden();
    }

    public function test_group_members_can_view_seen_by_details_excluding_sender(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $charlie = User::factory()->create(['name' => 'Charlie']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Project Team', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $alice->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $bob->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $charlie->id]);

        $message = Message::create([
            'conversation_id' => $group->id,
            'sender_id' => $alice->id,
            'body' => 'Status update please',
            'type' => 'text',
            'created_at' => now()->subMinutes(10),
        ]);

        // Bob marks conversation as read -> triggers recordMessageReads
        $this->actingAs($bob)->post(route('chat.mark-read', $group));

        $this->assertDatabaseHas('message_reads', [
            'message_id' => $message->id,
            'user_id' => $bob->id,
        ]);

        // Alice opens seen-by details
        $response = $this->actingAs($alice)->getJson(route('chat.message-seen-by', $message));
        $response->assertOk();
        $response->assertJsonStructure([
            'message_id',
            'seen_by' => [
                '*' => [
                    'user' => ['id', 'name', 'username', 'profile_photo_url'],
                    'seen_at',
                    'seen_at_relative',
                    'raw_seen_at',
                ],
            ],
            'count',
        ]);

        $seenBy = $response->json('seen_by');
        $this->assertCount(1, $seenBy);
        $this->assertEquals('Bob', $seenBy[0]['user']['name']);
        $this->assertNotNull($seenBy[0]['seen_at']);

        // Sender (Alice) is NOT in seen_by list
        $seenUserIds = collect($seenBy)->pluck('user.id')->all();
        $this->assertNotContains($alice->id, $seenUserIds);
        // Charlie (who has not read) is NOT in seen_by list
        $this->assertNotContains($charlie->id, $seenUserIds);
    }

    public function test_member_who_deleted_message_for_themselves_cannot_view_seen_by_details_but_read_receipt_persists(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        $group = Conversation::create(['type' => 'group', 'title' => 'Project Team', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $alice->id]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $bob->id]);

        $message = Message::create([
            'conversation_id' => $group->id,
            'sender_id' => $alice->id,
            'body' => 'Important note',
            'type' => 'text',
        ]);

        // Bob reads it first
        $this->actingAs($bob)->post(route('chat.mark-read', $group));
        $this->assertDatabaseHas('message_reads', [
            'message_id' => $message->id,
            'user_id' => $bob->id,
        ]);

        // Bob deletes the message for himself
        $this->actingAs($bob)->post(route('chat.delete-message-for-me', $message));
        $this->assertDatabaseHas('message_user_deletions', [
            'message_id' => $message->id,
            'user_id' => $bob->id,
        ]);

        // Bob can no longer view seen-by details for the message he deleted
        $responseBob = $this->actingAs($bob)->getJson(route('chat.message-seen-by', $message));
        $responseBob->assertNotFound();

        // But Alice can still view seen-by, and Bob's historical read receipt is preserved
        $responseAlice = $this->actingAs($alice)->getJson(route('chat.message-seen-by', $message));
        $responseAlice->assertOk();
        $this->assertCount(1, $responseAlice->json('seen_by'));
        $this->assertEquals('Bob', $responseAlice->json('seen_by.0.user.name'));
    }

    public function test_non_participant_cannot_view_seen_by_details(): void
    {
        $userA = User::factory()->create();
        $outsider = User::factory()->create();

        $group = Conversation::create(['type' => 'group', 'title' => 'Team', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $userA->id]);

        $message = Message::create([
            'conversation_id' => $group->id,
            'sender_id' => $userA->id,
            'body' => 'Secret update',
            'type' => 'text',
        ]);

        $response = $this->actingAs($outsider)->getJson(route('chat.message-seen-by', $message));
        $response->assertForbidden();
    }

    public function test_historical_participant_seen_by_details_provides_profile_photo_url(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create([
            'name' => 'Bob',
            'profile_photo_path' => 'profile-photos/bob.jpg',
        ]);

        $group = Conversation::create(['type' => 'group', 'title' => 'Team', 'last_message_at' => now()]);
        ConversationParticipant::create(['conversation_id' => $group->id, 'user_id' => $alice->id]);
        ConversationParticipant::create([
            'conversation_id' => $group->id,
            'user_id' => $bob->id,
            'last_read_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $group->id,
            'sender_id' => $alice->id,
            'body' => 'Older message without MessageRead record',
            'type' => 'text',
            'created_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($alice)->getJson(route('chat.message-seen-by', $message));
        $response->assertOk();

        $seenBy = $response->json('seen_by');
        $this->assertCount(1, $seenBy);
        $this->assertEquals('Bob', $seenBy[0]['user']['name']);
        $this->assertNotNull($seenBy[0]['user']['profile_photo_url']);
        $this->assertEquals($bob->profile_photo_url, $seenBy[0]['user']['profile_photo_url']);
    }
}
