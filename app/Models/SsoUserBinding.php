<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsoUserBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'client_id',
        'external_user_id',
        'external_username',
        'is_verified',
        'last_login_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(SsoClient::class, 'client_id', 'client_id');
    }
}
