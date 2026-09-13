<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'user_id',
        'last_read_at',
        'is_muted',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'is_muted' => 'boolean',
        ];
    }

    /**
     * The conversation associated with the participant.
     *
     * @return BelongsTo<Conversation, ConversationParticipant>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The user associated with the participant.
     *
     * @return BelongsTo<User, ConversationParticipant>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
