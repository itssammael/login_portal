<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SsoAuthorizationCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'client_id',
        'user_id',
        'redirect_uri',
        'nonce',
        'code_challenge',
        'code_challenge_method',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(SsoClient::class, 'client_id', 'client_id');
    }

    public function isValid(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function verifyPkce(?string $codeVerifier): bool
    {
        if (empty($this->code_challenge)) {
            return true;
        }

        if (empty($codeVerifier)) {
            return false;
        }

        if ($this->code_challenge_method === 'S256') {
            $derived = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

            return hash_equals($this->code_challenge, $derived);
        }

        if ($this->code_challenge_method === 'plain') {
            return hash_equals($this->code_challenge, $codeVerifier);
        }

        return false;
    }
}
