<?php

namespace App\Events;

use App\Models\ConversationParticipant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var array<int>|null
     */
    public ?array $recipientUserIds = null;

    /**
     * Create a new event instance.
     *
     * @param  array<int>|null  $recipientUserIds
     */
    public function __construct(
        public string $message = '',
        public ?int $conversationId = null,
        public mixed $messageData = null,
        public string $action = 'created',
        ?array $recipientUserIds = null
    ) {
        if ($recipientUserIds !== null) {
            $this->recipientUserIds = array_values(array_unique(array_map('intval', $recipientUserIds)));
        }
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->conversationId) {
            $channels[] = new PrivateChannel('chat.conversation.'.$this->conversationId);

            $participantIds = $this->recipientUserIds !== null
                ? $this->recipientUserIds
                : ConversationParticipant::query()
                    ->where('conversation_id', $this->conversationId)
                    ->pluck('user_id');

            foreach ($participantIds as $participantId) {
                $channels[] = new PrivateChannel('App.Models.User.'.$participantId);
            }
        } else {
            $userId = (app()->bound('auth') && auth()->check())
                ? auth()->id()
                : (is_array($this->messageData) ? ($this->messageData['sender_id'] ?? 0) : 0);

            $channels[] = new PrivateChannel('App.Models.User.'.$userId);
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $participantIds = [];
        if ($this->recipientUserIds !== null) {
            $participantIds = $this->recipientUserIds;
        } elseif ($this->conversationId) {
            $participantIds = ConversationParticipant::query()
                ->where('conversation_id', $this->conversationId)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->toArray();
        }

        return [
            'message' => $this->message,
            'conversation_id' => $this->conversationId,
            'participant_ids' => $participantIds,
            'data' => $this->messageData,
            'action' => $this->action,
        ];
    }
}
