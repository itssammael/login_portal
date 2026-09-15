<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'section_id',
        'position',
        'employee_number',
        'is_admin',
        'is_banned',
        'banned_at',
        'last_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
        'is_online',
    ];

    /**
     * Determine if the user was active recently.
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(5));
    }

    /**
     * Accessor for online status.
     */
    public function getIsOnlineAttribute(): bool
    {
        return $this->isOnline();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_banned' => 'boolean',
            'banned_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Determine if the user is an administrator.
     */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * The role assigned to this user.
     *
     * @return BelongsTo<Role, User>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The section this user belongs to.
     *
     * @return BelongsTo<Section, User>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * The department this user belongs to via their section.
     *
     * @return HasOneThrough<Department>
     */
    public function department(): HasOneThrough
    {
        return $this->hasOneThrough(
            Department::class,
            Section::class,
            'id', // Foreign key on sections table...
            'id', // Foreign key on departments table...
            'section_id', // Local key on users table...
            'department_id' // Local key on sections table...
        );
    }

    /**
     * Determine if the user can access administrative panels.
     */
    public function canAccessAdmin(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->role?->hasPermission('access_admin') ?? false;
    }

    /**
     * Determine if the user is allowed to broadcast system announcements via system engine rules.
     */
    public function canBroadcastAnnouncements(): bool
    {
        return SystemRule::canUserBroadcastAnnouncements($this);
    }

    /**
     * Check if the user has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->role?->hasPermission($permission) ?? false;
    }

    /**
     * Determine if the user is currently banned.
     */
    public function isBanned(): bool
    {
        return (bool) $this->is_banned;
    }

    /**
     * The conversations this user is part of.
     *
     * @return BelongsToMany<Conversation>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot(['last_read_at', 'is_muted'])
            ->withTimestamps();
    }

    /**
     * The messages sent by this user.
     *
     * @return HasMany<Message>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * The message reactions created by this user.
     *
     * @return HasMany<MessageReaction>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * Get the user's encryption key record.
     *
     * @return HasOne<UserEncryptionKey>
     */
    public function encryptionKeyRecord(): HasOne
    {
        return $this->hasOne(UserEncryptionKey::class);
    }

    /**
     * Retrieve or generate the user's unique encryption key.
     */
    public function getOrCreateEncryptionKey(): string
    {
        $record = $this->encryptionKeyRecord()->first();

        if (! $record) {
            $key = bin2hex(random_bytes(32));
            $record = $this->encryptionKeyRecord()->create([
                'encryption_key' => $key,
            ]);
        }

        return $record->encryption_key;
    }

    /**
     * Get the SSO user bindings for this user.
     *
     * @return HasMany<SsoUserBinding>
     */
    public function ssoBindings(): HasMany
    {
        return $this->hasMany(SsoUserBinding::class);
    }
}
