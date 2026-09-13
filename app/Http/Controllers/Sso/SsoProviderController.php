<?php

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Models\SsoAuthorizationCode;
use App\Models\SsoClient;
use App\Models\SsoUserBinding;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SsoProviderController extends Controller
{
    /**
     * Handle OAuth / OIDC SSO Authorization Request.
     */
    public function authorize(Request $request): RedirectResponse|JsonResponse
    {
        $clientId = $request->query('client_id');
        $redirectUri = $request->query('redirect_uri');
        $responseType = $request->query('response_type', 'code');
        $state = $request->query('state');
        $codeChallenge = $request->query('code_challenge');
        $codeChallengeMethod = $request->query('code_challenge_method', 'S256');

        if (! $clientId || ! $redirectUri) {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'Missing required parameter: client_id and redirect_uri are required.',
            ], 400);
        }

        $client = SsoClient::findClient($clientId);
        if (! $client || ! $client->is_active) {
            return response()->json([
                'error' => 'unauthorized_client',
                'error_description' => 'Invalid or inactive client ID.',
            ], 400);
        }

        if (! $client->validateRedirectUri($redirectUri)) {
            return response()->json([
                'error' => 'invalid_redirect_uri',
                'error_description' => 'The provided redirect_uri does not match registered client configuration.',
            ], 400);
        }

        if ($responseType !== 'code') {
            return response()->json([
                'error' => 'unsupported_response_type',
                'error_description' => 'Only response_type=code is supported.',
            ], 400);
        }

        // If user is not authenticated, redirect to login page while preserving SSO parameters in session
        if (! Auth::check()) {
            session(['sso_authorize_params' => $request->all()]);

            return redirect()->route('login');
        }

        $user = Auth::user();

        // Check if user has bound their account for this target system
        $binding = SsoUserBinding::where('user_id', $user->id)
            ->where('client_id', $client->client_id)
            ->first();

        if (! $binding) {
            session([
                'sso_pending_bind_client' => $client->client_id,
                'sso_authorize_params' => $request->all(),
            ]);

            return redirect()->route('sso.connected-systems')
                ->with('error', 'Please bind your '.$client->name.' credentials to your Login Portal account before using Single Sign-On.');
        }

        // Issue single-use short-lived authorization code

        $code = Str::random(40);
        SsoAuthorizationCode::create([
            'code' => hash('sha256', $code), // Stored as hash for additional security
            'client_id' => $clientId,
            'user_id' => $user->id,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'expires_at' => now()->addMinutes(2),
        ]);

        $query = http_build_query([
            'code' => $code,
            'state' => $state,
        ]);

        $delimiter = str_contains($redirectUri, '?') ? '&' : '?';

        return redirect()->away($redirectUri.$delimiter.$query);
    }

    /**
     * Direct redirect launcher from Portal Dashboard.
     */
    public function launch(Request $request, string $clientName): RedirectResponse|JsonResponse
    {
        $client = SsoClient::where('client_id', $clientName)
            ->orWhere('name', $clientName)
            ->orWhere('client_id', 'LIKE', $clientName.'%')
            ->orWhere('name', 'LIKE', '%'.$clientName.'%')
            ->first();

        if (! $client) {
            return redirect()->route('dashboard')->with('error', 'SSO Client not found.');
        }

        $state = Str::random(40);
        $redirectUri = explode(',', $client->redirect_uri)[0];
        $redirectUri = trim($redirectUri);

        $params = [
            'client_id' => $client->client_id,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ];

        return redirect()->route('sso.authorize', $params);
    }

    /**
     * Backchannel token endpoint to exchange code for identity assertion.
     */
    public function token(Request $request): JsonResponse
    {
        $grantType = $request->input('grant_type');
        $clientId = $request->input('client_id');
        $clientSecret = $request->input('client_secret');
        $rawCode = $request->input('code');
        $redirectUri = $request->input('redirect_uri');
        $codeVerifier = $request->input('code_verifier');

        if ($grantType !== 'authorization_code') {
            return response()->json([
                'error' => 'unsupported_grant_type',
                'error_description' => 'Only grant_type=authorization_code is supported.',
            ], 400);
        }

        if (! $clientId || ! $clientSecret || ! $rawCode) {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'Missing required parameters: client_id, client_secret, and code are required.',
            ], 400);
        }

        $client = SsoClient::findClient($clientId);
        if (! $client || ! $client->is_active || ! $client->verifySecret($clientSecret)) {
            return response()->json([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed.',
            ], 401);
        }

        $hashedCode = hash('sha256', $rawCode);
        $authCode = SsoAuthorizationCode::where('code', $hashedCode)
            ->where('client_id', $clientId)
            ->first();

        if (! $authCode) {
            return response()->json([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid authorization code.',
            ], 400);
        }

        if (! $authCode->isValid()) {
            return response()->json([
                'error' => 'invalid_grant',
                'error_description' => $authCode->used_at ? 'Authorization code has already been used.' : 'Authorization code has expired.',
            ], 400);
        }

        if ($redirectUri && ! $client->validateRedirectUri($redirectUri)) {
            return response()->json([
                'error' => 'invalid_grant',
                'error_description' => 'Redirect URI mismatch.',
            ], 400);
        }

        if (! $authCode->verifyPkce($codeVerifier)) {
            return response()->json([
                'error' => 'invalid_grant',
                'error_description' => 'PKCE code verifier verification failed.',
            ], 400);
        }

        // Mark authorization code as used immediately (replay protection)
        $authCode->update(['used_at' => now()]);

        $user = $authCode->user;
        if (! $user || $user->isBanned()) {
            return response()->json([
                'error' => 'access_denied',
                'error_description' => 'User account is invalid or banned.',
            ], 403);
        }

        $binding = SsoUserBinding::where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->first();

        // Generate short-lived access token / user assertion
        $token = Str::random(80);

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_in' => 3600,
            'user' => [
                'id' => (string) $user->id,
                'bound_user_id' => $binding?->external_user_id,
                'bound_username' => $binding?->external_username,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->toIso8601String() : null,
                'profile_photo_url' => $user->profile_photo_url,
            ],
        ]);

    }

    /**
     * Userinfo endpoint for standard OIDC compliance.
     */
    public function userinfo(Request $request): JsonResponse
    {
        // Require client authentication or bearer token
        $authHeader = $request->header('Authorization');
        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // Return current user if logged in via Sanctum or session
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return response()->json([
            'sub' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => ! is_null($user->email_verified_at),
            'picture' => $user->profile_photo_url,
        ]);
    }
}
