<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageMention extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'message_id',
        'conversation_id',
        'user_id',
    ];

    /**
     * The message where the mention occurred.
     *
     * @return BelongsTo<Message, MessageMention>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * The conversation associated with the mention.
     *
     * @return BelongsTo<Conversation, MessageMention>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The mentioned user.
     *
     * @return BelongsTo<User, MessageMention>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
