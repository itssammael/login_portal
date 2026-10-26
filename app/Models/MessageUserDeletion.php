<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageUserDeletion extends Model
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
    ];

    /**
     * The message that was deleted for the user.
     *
     * @return BelongsTo<Message, MessageUserDeletion>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * The user who deleted the message for themselves.
     *
     * @return BelongsTo<User, MessageUserDeletion>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
