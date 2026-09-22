<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SsoAuthorizationCode;
use App\Models\SsoClient;
use App\Models\SsoUserBinding;
use App\Models\User;
use Illuminate\Support\Str;

class SsoService
{
    /**
     * Generate a cryptographically secure client ID.
     */
    public function generateClientId(string $prefix = 'client_'): string
    {
        do {
            $clientId = $prefix.Str::lower(Str::random(16));
        } while (SsoClient::where('client_id', $clientId)->exists());

        return $clientId;
    }

    /**
     * Generate a cryptographically secure client secret.
     */
    public function generateClientSecret(): string
    {
        return Str::random(64);
    }

    /**
     * Issue a single-use, short-lived authorization code.
     */
    public function issueAuthorizationCode(
        SsoClient $client,
        User $user,
        string $redirectUri,
        ?string $state = null,
        ?string $nonce = null,
        ?string $codeChallenge = null,
        string $codeChallengeMethod = 'S256'
    ): string {
        $rawCode = Str::random(40);
        $codeHash = hash('sha256', $rawCode);

        SsoAuthorizationCode::create([
            'code' => $codeHash,
            'client_id' => $client->client_id,
            'user_id' => $user->id,
            'redirect_uri' => $redirectUri,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'expires_at' => now()->addMinutes(2),
        ]);

        $this->logEvent(
            user: $user,
            action: 'sso_authorization_code_issued',
            targetType: 'sso_client',
            targetId: $client->id,
            details: [
                'client_id' => $client->client_id,
                'redirect_uri' => $redirectUri,
                'has_state' => ! empty($state),
                'has_pkce' => ! empty($codeChallenge),
            ]
        );

        return $rawCode;
    }

    /**
     * Exchange an authorization code for an identity token response.
     *
     * @return array{success: bool, status: int, data?: array, error?: string, error_description?: string}
     */
    public function exchangeToken(
        string $clientId,
        string $clientSecret,
        string $rawCode,
        ?string $redirectUri = null,
        ?string $codeVerifier = null
    ): array {
        $client = SsoClient::findClient($clientId);

        if (! $client || ! $client->is_active || ! $client->verifySecret($clientSecret)) {
            $this->logEvent(
                user: null,
                action: 'sso_token_exchange_failed',
                targetType: 'sso_client',
                targetId: $client?->id,
                details: [
                    'reason' => 'invalid_client',
                    'client_id' => $clientId,
                ]
            );

            return [
                'success' => false,
                'status' => 401,
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed.',
            ];
        }

        $codeHash = hash('sha256', $rawCode);
        $authCode = SsoAuthorizationCode::where('code', $codeHash)
            ->where('client_id', $client->client_id)
            ->first();

        if (! $authCode) {
            $this->logEvent(
                user: null,
                action: 'sso_token_exchange_failed',
                targetType: 'sso_client',
                targetId: $client->id,
                details: [
                    'reason' => 'code_not_found',
                    'client_id' => $client->client_id,
                ]
            );

            return [
                'success' => false,
                'status' => 400,
                'error' => 'invalid_grant',
                'error_description' => 'Invalid authorization code.',
            ];
        }

        if (! $authCode->isValid()) {
            $reason = $authCode->used_at ? 'replayed_authorization_code' : 'expired_authorization_code';

            $this->logEvent(
                user: $authCode->user,
                action: $reason,
                targetType: 'sso_client',
                targetId: $client->id,
                details: [
                    'client_id' => $client->client_id,
                    'code_id' => $authCode->id,
                ]
            );

            return [
                'success' => false,
                'status' => 400,
                'error' => 'invalid_grant',
                'error_description' => $authCode->used_at ? 'Authorization code has already been used.' : 'Authorization code has expired.',
            ];
        }

        if ($redirectUri && ! $client->validateRedirectUri($redirectUri)) {
            $this->logEvent(
                user: $authCode->user,
                action: 'sso_redirect_uri_mismatch',
                targetType: 'sso_client',
                targetId: $client->id,
                details: [
                    'client_id' => $client->client_id,
                    'attempted_redirect_uri' => $redirectUri,
                ]
            );

            return [
                'success' => false,
                'status' => 400,
                'error' => 'invalid_grant',
                'error_description' => 'Redirect URI mismatch.',
            ];
        }

        if (! $authCode->verifyPkce($codeVerifier)) {
            $this->logEvent(
                user: $authCode->user,
                action: 'sso_pkce_verification_failed',
                targetType: 'sso_client',
                targetId: $client->id,
                details: [
                    'client_id' => $client->client_id,
                ]
            );

            return [
                'success' => false,
                'status' => 400,
                'error' => 'invalid_grant',
                'error_description' => 'PKCE code verifier verification failed.',
            ];
        }

        // Invalidate authorization code immediately to prevent replay
        $authCode->update(['used_at' => now()]);

        // Touch client last used timestamp
        $client->update(['last_used_at' => now()]);

        $user = $authCode->user;
        if (! $user || $user->isBanned()) {
            return [
                'success' => false,
                'status' => 403,
                'error' => 'access_denied',
                'error_description' => 'User account is invalid or banned.',
            ];
        }

        $binding = SsoUserBinding::where('user_id', $user->id)
            ->where('client_id', $client->client_id)
            ->first();

        if ($binding) {
            $binding->update(['last_login_at' => now()]);
        }

        $this->logEvent(
            user: $user,
            action: 'sso_token_exchanged',
            targetType: 'sso_client',
            targetId: $client->id,
            details: [
                'client_id' => $client->client_id,
                'user_id' => $user->id,
                'bound_user_id' => $binding?->external_user_id,
            ]
        );

        $token = Str::random(80);

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'token_type' => 'Bearer',
                'access_token' => $token,
                'expires_in' => 3600,
                'nonce' => $authCode->nonce,
                'user' => [
                    'id' => (string) $user->id,
                    'bound_user_id' => $binding?->external_user_id,
                    'bound_username' => $binding?->external_username,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->toIso8601String() : null,
                    'profile_photo_url' => $user->profile_photo_url,
                ],
            ],
        ];
    }

    /**
     * Safely log an SSO audit event without sensitive tokens or passwords.
     */
    public function logEvent(
        ?User $user,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $details = []
    ): ?AuditLog {
        // Redact any accidental sensitive values
        $sanitized = [];
        $sensitiveKeys = ['password', 'secret', 'client_secret', 'token', 'access_token', 'code', 'code_verifier', 'portal_password'];

        foreach ($details as $key => $val) {
            if (in_array(strtolower($key), $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $val;
            }
        }

        // If no user is passed, use first admin or skip admin foreign key requirement
        $adminId = $user?->id;
        if (! $adminId) {
            $fallbackAdmin = User::where('is_admin', true)->first();
            $adminId = $fallbackAdmin?->id;
        }

        if (! $adminId) {
            return null;
        }

        return AuditLog::create([
            'admin_id' => $adminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $sanitized,
        ]);
    }
}
