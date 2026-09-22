<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class SsoClient extends Model
{
    use HasFactory;

    protected $table = 'sso';

    public const FRAMEWORKS = [
        'laravel_inertia' => 'Laravel + Jetstream + Inertia (Vue.js)',
        'laravel_livewire' => 'Laravel + Jetstream (Livewire/Blade)',
        'laravel_blade' => 'Laravel (Standard MVC / Blade)',
        'php_vanilla' => 'PHP + Vanilla JS / jQuery',
        'nuxt_node' => 'Nuxt.js / Node.js',
        'vue_spa' => 'Vue.js / React (SPA)',
        'generic_rest' => 'Generic REST API / Python / Other',
    ];

    protected $fillable = [
        'name',
        'description',
        'client_id',
        'client_secret',
        'redirect_uri',
        'api_url',
        'icon',
        'framework',
        'created_by',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected $appends = [
        'icon_url',
        'framework_label',
        'sso_client_id',
        'sso_client_redirect_url',
        'sso_client_base_url',
    ];

    /**
     * Alias for client_id.
     */
    public function getSsoClientIdAttribute(): string
    {
        return (string) $this->client_id;
    }

    /**
     * Alias for redirect_uri.
     */
    public function getSsoClientRedirectUrlAttribute(): string
    {
        return (string) $this->redirect_uri;
    }

    /**
     * Alias for api_url / resolveApiBaseUrl().
     */
    public function getSsoClientBaseUrlAttribute(): ?string
    {
        return $this->resolveApiBaseUrl();
    }

    /**
     * The admin user who created this SSO client.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Active user bindings for this client.
     */
    public function bindings(): HasMany
    {
        return $this->hasMany(SsoUserBinding::class, 'client_id', 'client_id');
    }

    /**
     * Authorization codes issued for this client.
     */
    public function authorizationCodes(): HasMany
    {
        return $this->hasMany(SsoAuthorizationCode::class, 'client_id', 'client_id');
    }

    /**
     * Get the human-readable framework label.
     */
    public function getFrameworkLabelAttribute(): string
    {
        return static::FRAMEWORKS[$this->framework] ?? ($this->framework ?: 'Laravel + Jetstream + Inertia (Vue.js)');
    }

    /**
     * Get the accessible public URL for the SSO client icon.
     */
    public function getIconUrlAttribute(): ?string
    {
        if (! $this->icon) {
            return null;
        }

        if (filter_var($this->icon, FILTER_VALIDATE_URL)) {
            return $this->icon;
        }

        return asset('storage/'.$this->icon);
    }

    /**
     * Resolve the base API URL of the connected system for verify-credentials calls.
     * Uses the explicit api_url when set; otherwise derives the base URL from redirect_uri.
     */
    public function resolveApiBaseUrl(): ?string
    {
        if (! empty($this->api_url)) {
            return rtrim($this->api_url, '/');
        }

        $firstUri = trim(explode(',', $this->redirect_uri ?? '')[0]);
        if (empty($firstUri)) {
            return null;
        }

        $parsed = parse_url($firstUri);
        if (! isset($parsed['host'])) {
            return null;
        }

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    /**
     * Find client by ID or name.
     */
    public static function findClient(string $identifier): ?self
    {
        return static::where('client_id', $identifier)
            ->orWhere('name', $identifier)
            ->orWhere('client_id', 'LIKE', $identifier.'%')
            ->orWhere('name', 'LIKE', '%'.$identifier.'%')
            ->first();
    }

    /**
     * Validate that the given redirect URI strictly matches the client's registered redirect URI(s).
     * Prevents open-redirect vulnerabilities.
     */
    public function validateRedirectUri(string $uri): bool
    {
        if (empty($uri) || empty($this->redirect_uri)) {
            return false;
        }

        $inputParts = parse_url($uri);
        if (! isset($inputParts['host']) || ! isset($inputParts['scheme'])) {
            return false;
        }

        $inputScheme = strtolower($inputParts['scheme']);
        $inputHost = strtolower($inputParts['host']);
        $inputPort = $inputParts['port'] ?? ($inputScheme === 'https' ? 443 : 80);
        $inputPath = rtrim($inputParts['path'] ?? '', '/');

        $allowedUris = array_filter(array_map('trim', explode(',', $this->redirect_uri)));

        foreach ($allowedUris as $allowedUri) {
            if ($allowedUri === $uri) {
                return true;
            }

            $allowedParts = parse_url($allowedUri);
            if (! isset($allowedParts['host']) || ! isset($allowedParts['scheme'])) {
                continue;
            }

            $allowedScheme = strtolower($allowedParts['scheme']);
            $allowedHost = strtolower($allowedParts['host']);
            $allowedPort = $allowedParts['port'] ?? ($allowedScheme === 'https' ? 443 : 80);
            $allowedPath = rtrim($allowedParts['path'] ?? '', '/');

            // Schemes must match
            if ($inputScheme !== $allowedScheme) {
                continue;
            }

            // Hosts must match or be loopback equivalents in local dev
            $isLocalAllowed = in_array($allowedHost, ['127.0.0.1', 'localhost'], true);
            $isLocalInput = in_array($inputHost, ['127.0.0.1', 'localhost'], true);
            $hostsMatch = ($inputHost === $allowedHost) || ($isLocalAllowed && $isLocalInput);

            if (! $hostsMatch) {
                continue;
            }

            // Port match
            if (! $isLocalAllowed && $inputPort !== $allowedPort) {
                continue;
            }

            // Paths must match
            if ($inputPath === $allowedPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify client secret using timing-safe comparison, decryption, or hash checking.
     */
    public function verifySecret(string $secret): bool
    {
        if (empty($secret) || empty($this->client_secret)) {
            return false;
        }

        if (hash_equals($this->client_secret, $secret)) {
            return true;
        }

        try {
            $decrypted = Crypt::decryptString($this->client_secret);
            if (hash_equals($decrypted, $secret)) {
                return true;
            }
        } catch (\Throwable $e) {
            // Not encrypted
        }

        try {
            if (Hash::check($secret, $this->client_secret)) {
                return true;
            }
        } catch (\Throwable $e) {
            // Not a valid hash string
        }

        return false;
    }
}
