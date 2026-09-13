<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageReaction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'message_id',
        'user_id',
        'reaction',
    ];

    /**
     * The message associated with this reaction.
     *
     * @return BelongsTo<Message, MessageReaction>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * The user who made this reaction.
     *
     * @return BelongsTo<User, MessageReaction>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
