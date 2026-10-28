<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'title',
        'direct_key',
        'last_message_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * The participants in the conversation.
     *
     * @return HasMany<ConversationParticipant>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * The users participating in the conversation.
     *
     * @return BelongsToMany<User>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['last_read_at', 'is_muted', 'is_pinned', 'role', 'notification_preference'])
            ->withTimestamps();
    }

    /**
     * Check if the conversation is a group chat.
     */
    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    /**
     * Check if a specific user is an admin of this group conversation.
     */
    public function isAdmin(User|int $user): bool
    {
        if (! $this->isGroup()) {
            return false;
        }

        $userId = $user instanceof User ? $user->id : (int) $user;

        if ($this->relationLoaded('participants')) {
            return $this->participants->contains(fn ($p) => (int) $p->user_id === $userId && $p->role === ConversationParticipant::ROLE_ADMIN);
        }

        return $this->participants()
            ->where('user_id', $userId)
            ->where('role', ConversationParticipant::ROLE_ADMIN)
            ->exists();
    }

    /**
     * Get the count of admins in this group conversation.
     */
    public function adminCount(): int
    {
        if (! $this->isGroup()) {
            return 0;
        }

        if ($this->relationLoaded('participants')) {
            return $this->participants->where('role', ConversationParticipant::ROLE_ADMIN)->count();
        }

        return $this->participants()->where('role', ConversationParticipant::ROLE_ADMIN)->count();
    }

    /**
     * The messages in this conversation.
     *
     * @return HasMany<Message>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The latest message in this conversation.
     *
     * @return HasOne<Message>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Get the other user for a direct conversation.
     */
    public function getOtherUser(int $currentUserId): ?User
    {
        return $this->users->first(fn (User $user) => $user->id !== $currentUserId);
    }

    /**
     * Get the number of unread messages for a specific user.
     */
    public function unreadCountForUser(int $userId): int
    {
        $participant = $this->participants->firstWhere('user_id', $userId);

        if (! $participant) {
            return 0;
        }

        $query = $this->messages()
            ->where('sender_id', '!=', $userId)
            ->where('is_deleted', false);

        if ($participant->last_read_at) {
            $query->where('created_at', '>', $participant->last_read_at);
        }

        return $query->count();
    }

    /**
     * Get or create the dedicated System Notification conversation for a user.
     */
    public static function getOrCreateSystemConversationForUser(User $user): Conversation
    {
        $conversation = static::query()
            ->where('type', 'system')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if (! $conversation) {
            $conversation = static::create([
                'type' => 'system',
                'title' => 'System Notifications',
                'last_message_at' => now(),
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'last_read_at' => null,
            ]);
        }

        return $conversation;
    }

    /**
     * The mentions in this conversation.
     *
     * @return HasMany<MessageMention>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(MessageMention::class);
    }

    /**
     * Generate normalized unique direct key for two users.
     */
    public static function generateDirectKey(int $userId1, int $userId2): string
    {
        return min($userId1, $userId2).':'.max($userId1, $userId2);
    }
}
