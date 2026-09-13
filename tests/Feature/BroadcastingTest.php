<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_sent_event_can_be_dispatched(): void
    {
        Event::fake([MessageSent::class]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('test-broadcast', ['message' => 'Testing Reverb WebSocket']))
            ->assertOk()
            ->assertJson([
                'status' => 'Event broadcasted successfully',
                'message' => 'Testing Reverb WebSocket',
                'connection' => config('broadcasting.default'),
            ]);

        Event::assertDispatched(MessageSent::class, function ($event) {
            return $event->message === 'Testing Reverb WebSocket';
        });
    }

    public function test_event_has_correct_broadcast_configuration(): void
    {
        $event = new MessageSent('Hello world');

        $this->assertEquals('message.sent', $event->broadcastAs());
        $this->assertEquals('public-chat', $event->broadcastOn()[0]->name);
    }
}
