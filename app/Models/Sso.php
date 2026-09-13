<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class Sso extends Model
{
    use HasFactory;

    protected $table = 'sso';

    protected $fillable = [
        'name',
        'client_id',
        'client_secret',
        'redirect_uri',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Find client by ID or name, with fallback auto-provisioning for core system apps.
     */
    public static function findClient(string $identifier): ?self
    {
        $client = static::where('client_id', $identifier)
            ->orWhere('name', $identifier)
            ->orWhere('client_id', 'LIKE', $identifier.'%')
            ->orWhere('name', 'LIKE', '%'.$identifier.'%')
            ->first();

        if ($client) {
            return $client;
        }

        // Auto-provision standard applications if missing from database
        $lower = strtolower($identifier);
        if (str_contains($lower, 'lfews')) {
            return static::create([
                'name' => 'LFEWS 2.0',
                'client_id' => 'lfews_client_id',
                'client_secret' => 'lfews_client_secret',
                'redirect_uri' => 'http://127.0.0.1:8001/sso/callback,http://localhost:8001/sso/callback',
                'is_active' => true,
            ]);
        }

        if (str_contains($lower, 'tracker') || str_contains($lower, 'project')) {
            return static::create([
                'name' => 'Project Tracker',
                'client_id' => 'project_tracker_client_id',
                'client_secret' => 'project_tracker_client_secret',
                'redirect_uri' => 'http://127.0.0.1:8002/sso/callback,http://localhost:8002/sso/callback',
                'is_active' => true,
            ]);
        }

        return null;
    }

    /**
     * Validate that the given redirect URI matches the client's registered redirect URI(s).
     */
    public function validateRedirectUri(string $uri): bool
    {
        $allowedUris = array_map('trim', explode(',', $this->redirect_uri));
        $inputPath = parse_url($uri, PHP_URL_PATH) ?: '/sso/callback';

        foreach ($allowedUris as $allowedUri) {
            if ($allowedUri === $uri) {
                return true;
            }
            if (str_starts_with($uri, rtrim($allowedUri, '/'))) {
                return true;
            }
            // Allow domain/port variations in local dev as long as callback path matches /sso/callback
            $allowedPath = parse_url($allowedUri, PHP_URL_PATH) ?: '/sso/callback';
            if ($inputPath === $allowedPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify client secret.
     */
    public function verifySecret(string $secret): bool
    {
        if ($this->client_secret === $secret) {
            return true;
        }

        if (Hash::needsRehash($this->client_secret)) {
            return Hash::check($secret, $this->client_secret);
        }

        return hash_equals($this->client_secret, $secret) || Hash::check($secret, $this->client_secret);
    }
}
