<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FeedbackEmbed extends Model
{
    use HasFactory;

    protected $table = 'fb_embeds';

    protected $fillable = [
        'event_id',
        'public_id',
        'client_id',
        'client_secret',
        'allowed_origins',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allowed_origins' => 'array',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected static function booted(): void
    {
        static::creating(function (FeedbackEmbed $embed): void {
            if (empty($embed->public_id)) {
                $embed->public_id = (string) Str::uuid();
            }
            if (empty($embed->client_id)) {
                $embed->client_id = 'emb_'.Str::random(32);
            }
            if (empty($embed->client_secret)) {
                $embed->client_secret = Hash::make(Str::random(48));
            }
        });
    }

    /**
     * The event this embed configuration belongs to.
     *
     * @return BelongsTo<FbEvent, FeedbackEmbed>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(FbEvent::class, 'event_id');
    }

    /**
     * The short-lived iframe sessions created for this embed.
     *
     * @return HasMany<FeedbackEmbedSession, FeedbackEmbed>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(FeedbackEmbedSession::class, 'embed_id');
    }

    /**
     * The submissions recorded through this embed.
     *
     * @return HasMany<FbSubmission, FeedbackEmbed>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FbSubmission::class, 'embed_id');
    }

    /**
     * Verify the client secret against the stored hash.
     */
    public function verifySecret(string $secret): bool
    {
        if (empty($secret) || empty($this->client_secret)) {
            return false;
        }

        return Hash::check($secret, $this->client_secret);
    }

    /**
     * Regenerate the public ID and invalidate all active sessions.
     */
    public function regeneratePublicId(): string
    {
        $this->public_id = (string) Str::uuid();
        $this->save();

        $this->invalidateActiveSessions();

        return $this->public_id;
    }

    /**
     * Regenerate client secret, hash & store it, invalidate active sessions, and return plaintext secret.
     */
    public function regenerateSecret(): string
    {
        $plainSecret = 'sec_'.Str::random(48);
        $this->client_secret = Hash::make($plainSecret);
        $this->save();

        $this->invalidateActiveSessions();

        return $plainSecret;
    }

    /**
     * Invalidate all unexpired and unused sessions for this embed.
     */
    public function invalidateActiveSessions(): void
    {
        $this->sessions()
            ->where('expires_at', '>', now())
            ->whereNull('used_at')
            ->update([
                'expires_at' => now(),
            ]);
    }

    /**
     * Create a new short-lived session and return token and iframe URL.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array{session_token: string, expires_at: string, iframe_url: string}
     */
    public function createSession(?string $respondentId = null, ?array $metadata = null, int $ttlMinutes = 5): array
    {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);
        $respondentHash = $respondentId ? hash('sha256', $respondentId) : null;
        $expiresAt = now()->addMinutes($ttlMinutes);

        $this->sessions()->create([
            'token_hash' => $tokenHash,
            'respondent_id' => $respondentId,
            'respondent_hash' => $respondentHash,
            'metadata' => $metadata,
            'expires_at' => $expiresAt,
        ]);

        return [
            'session_token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'iframe_url' => route('feedback.embed.show', [
                'publicId' => $this->public_id,
                'token' => $token,
            ]),
        ];
    }
}
