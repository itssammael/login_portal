<?php

namespace App\Models;

use App\Services\MessageEncryptionService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    /**
     * Boot the model to automatically encrypt body on saving.
     */
    protected static function booted(): void
    {
        static::saving(function (Message $message): void {
            if ($message->body !== null && trim($message->body) !== '' && ! str_starts_with($message->body, 'ENC::')) {
                $sender = $message->sender ?: User::find($message->sender_id);
                if ($sender) {
                    $key = $sender->getOrCreateEncryptionKey();
                    $message->body = MessageEncryptionService::encrypt($message->body, $key);
                }
            }
        });
    }

    public const STATUS_FAILED = -1;

    public const STATUS_SENT = 0;

    public const STATUS_DELIVERED = 1;

    public const STATUS_READ = 2;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'type',
        'attachment_path',
        'attachment_name',
        'attachment_type',
        'status',
        'is_deleted',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'attachment_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'is_deleted' => 'boolean',
        ];
    }

    /**
     * Get secure URL for the attachment if present.
     */
    public function getAttachmentUrlAttribute(): ?string
    {
        if (! $this->attachment_path) {
            return null;
        }

        return route('chat.download-attachment', $this->id);
    }

    /**
     * The conversation this message belongs to.
     *
     * @return BelongsTo<Conversation, Message>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The user who sent the message.
     *
     * @return BelongsTo<User, Message>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * The reactions on this message.
     *
     * @return HasMany<MessageReaction>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }
}
