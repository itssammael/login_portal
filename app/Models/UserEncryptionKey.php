<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEncryptionKey extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'encryption_key',
    ];

    /**
     * The user that owns this encryption key.
     *
     * @return BelongsTo<User, UserEncryptionKey>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
