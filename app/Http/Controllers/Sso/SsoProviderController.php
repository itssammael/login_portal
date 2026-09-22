<?php

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Models\SsoClient;
use App\Models\SsoUserBinding;
use App\Services\SsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SsoProviderController extends Controller
{
    public function __construct(
        protected SsoService $ssoService
    ) {}

    /**
     * Handle OAuth2 / OIDC SSO Authorization Request.
     */
    public function authorize(Request $request): RedirectResponse|JsonResponse
    {
        $clientId = $request->query('client_id');
        $redirectUri = $request->query('redirect_uri');
        $responseType = $request->query('response_type', 'code');
        $state = $request->query('state');
        $nonce = $request->query('nonce');
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

        // If user is not authenticated, preserve SSO parameters and redirect to existing Jetstream login
        if (! Auth::check()) {
            session(['sso_authorize_params' => $request->all()]);

            return redirect()->route('login');
        }

        $user = Auth::user();

        // Check if user account is banned or suspended
        if (method_exists($user, 'isBanned') && $user->isBanned()) {
            return response()->json([
                'error' => 'access_denied',
                'error_description' => 'Your LGUNET Portal account is suspended.',
            ], 403);
        }

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
                ->with('error', 'This application is not linked to your LGUNET Portal account. Please bind your account first.');
        }

        // Issue single-use short-lived authorization code
        $code = $this->ssoService->issueAuthorizationCode(
            client: $client,
            user: $user,
            redirectUri: $redirectUri,
            state: $state,
            nonce: $nonce,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod ?: 'S256'
        );

        $params = ['code' => $code];
        if (! empty($state)) {
            $params['state'] = $state;
        }

        $query = http_build_query($params);
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

        if (! $client->is_active) {
            return redirect()->route('dashboard')->with('error', 'This SSO Client is currently disabled.');
        }

        $user = $request->user();
        if ($user) {
            $isBound = SsoUserBinding::where('user_id', $user->id)
                ->where('client_id', $client->client_id)
                ->exists();

            if (! $isBound) {
                session(['sso_pending_bind_client' => $client->client_id]);

                return redirect()->route('sso.connected-systems')
                    ->with('error', "This application is not linked to your LGUNET Portal account. Please bind your {$client->name} account first.");
            }
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

        $result = $this->ssoService->exchangeToken(
            clientId: $clientId,
            clientSecret: $clientSecret,
            rawCode: $rawCode,
            redirectUri: $redirectUri,
            codeVerifier: $codeVerifier
        );

        if (! $result['success']) {
            return response()->json([
                'error' => $result['error'],
                'error_description' => $result['error_description'],
            ], $result['status']);
        }

        return response()->json($result['data'], 200);
    }

    /**
     * Userinfo endpoint for standard OIDC compliance.
     */
    public function userinfo(Request $request): JsonResponse
    {
        $authHeader = $request->header('Authorization');
        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

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
