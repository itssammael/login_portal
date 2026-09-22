<?php

namespace Tests\Unit;

use App\Events\MessageSent;
use PHPUnit\Framework\TestCase;

class MessageSentEventTest extends TestCase
{
    public function test_message_sent_event_broadcast_configuration(): void
    {
        $event = new MessageSent(
            message: 'Hello world',
            conversationId: null,
            messageData: ['id' => 123, 'body' => 'Test message', 'sender_id' => 5],
            action: 'created'
        );

        $this->assertEquals('message.sent', $event->broadcastAs());
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertEquals('public-chat', $channels[0]->name);

        $data = $event->broadcastWith();
        $this->assertEquals('Hello world', $data['message']);
        $this->assertNull($data['conversation_id']);
        $this->assertEquals([], $data['participant_ids']);
        $this->assertEquals('created', $data['action']);
        $this->assertEquals(5, $data['data']['sender_id']);
    }
}
