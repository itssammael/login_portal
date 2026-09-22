<?php

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Models\SsoClient;
use App\Models\SsoUserBinding;
use App\Services\SsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ConnectedSystemsController extends Controller
{
    public function __construct(
        protected SsoService $ssoService
    ) {}

    /**
     * Display the Connected Systems binding page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $clients = SsoClient::where('is_active', true)->get();
        $bindings = SsoUserBinding::where('user_id', $user->id)->get()->keyBy('client_id');

        $systems = $clients->map(function ($client) use ($bindings) {
            $binding = $bindings->get($client->client_id);

            return [
                'id' => $client->id,
                'client_id' => $client->client_id,
                'name' => $client->name,
                'icon_url' => $client->icon_url,
                'is_bound' => (bool) $binding,
                'bound_username' => $binding?->external_username,
                'bound_at' => $binding?->created_at?->diffForHumans(),
                'launch_url' => route('sso.launch', $client->client_id),
            ];
        });

        $pendingClient = session('sso_pending_bind_client');

        return Inertia::render('Sso/ConnectedSystems', [
            'systems' => $systems,
            'pendingClient' => $pendingClient,
            'flashMessage' => session('error') ?: session('success'),
        ]);
    }

    /**
     * Bind target system credentials to current Login Portal user account.
     * Credentials entered are strictly verified directly with the Registered SSO Client,
     * with zero checking or referral against the login_portal database.
     */
    public function bind(Request $request, string $clientId): RedirectResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = $request->user();

        $client = SsoClient::findClient($clientId);

        if (! $client) {
            return back()->with('error', 'Target system not found.');
        }

        $candidateUrls = $this->resolveCandidateApiUrls($client, $request);

        $response = null;
        $lastException = null;

        foreach ($candidateUrls as $url) {
            try {
                $res = Http::asForm()->timeout(5)->post($url, [
                    'username' => $request->input('username'),
                    'password' => $request->input('password'),
                    'client_secret' => $client->client_secret,
                ]);

                $response = $res;

                if ($res->successful() && $res->json('success')) {
                    break;
                }
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        if (! $response) {
            Log::error('Failed to verify target system credentials for '.$client->name.': '.($lastException ? $lastException->getMessage() : 'No response'));

            return back()->withErrors(['password' => 'Unable to connect to '.$client->name.' server. Please make sure the system is online.']);
        }

        if (! $response->successful() || ! $response->json('success')) {
            $errorMsg = $response->json('message') ?: 'Invalid credentials for '.$client->name.'.';

            return back()->withErrors(['password' => $errorMsg]);
        }

        $externalUser = $response->json('user');
        $externalUserId = (string) $externalUser['id'];

        // Prevent binding the same external account to multiple portal users
        $existingBinding = SsoUserBinding::where('client_id', $client->client_id)
            ->where('external_user_id', $externalUserId)
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($existingBinding) {
            return back()->withErrors(['username' => 'This '.$client->name.' account is already bound to another Portal user.']);
        }

        SsoUserBinding::updateOrCreate(
            [
                'user_id' => $user->id,
                'client_id' => $client->client_id,
            ],
            [
                'external_user_id' => $externalUserId,
                'external_username' => $externalUser['username'] ?? $externalUser['email'],
                'is_verified' => true,
            ]
        );

        $this->ssoService->logEvent(
            user: $user,
            action: 'sso_user_binding_created',
            targetType: 'sso_client',
            targetId: $client->id,
            details: [
                'client_id' => $client->client_id,
                'external_username' => $externalUser['username'] ?? $externalUser['email'],
            ]
        );

        // If an SSO request was blocked waiting for this binding, resume SSO redirect!
        if (session('sso_pending_bind_client') === $client->client_id && session()->has('sso_authorize_params')) {
            session()->forget('sso_pending_bind_client');
            $params = session()->pull('sso_authorize_params');

            return redirect()->route('sso.authorize', $params);
        }

        return back()->with('success', 'Successfully bound your '.$client->name.' account!');
    }

    /**
     * Resolve candidate verify-credentials API URLs for the target system.
     * Strictly derives candidate endpoints from the client's explicit api_url and registered redirect_uri.
     * Queries the Registered SSO Client directly, with no referral to login_portal database.
     */
    protected function resolveCandidateApiUrls(SsoClient $client, Request $request): array
    {
        $bases = [];
        if (! empty($client->api_url)) {
            $bases[] = rtrim($client->api_url, '/');
        }

        if (! empty($client->redirect_uri)) {
            $redirectUris = array_filter(array_map('trim', explode(',', $client->redirect_uri)));
            foreach ($redirectUris as $uri) {
                $parsed = parse_url($uri);
                if (isset($parsed['host'])) {
                    $scheme = $parsed['scheme'] ?? 'http';
                    $host = $parsed['host'];
                    $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
                    $bases[] = "{$scheme}://{$host}{$port}";
                }
            }
        }

        $bases = array_values(array_unique($bases));
        $urls = [];
        foreach ($bases as $base) {
            $urls[] = "{$base}/api/sso/verify-credentials";
            $urls[] = "{$base}/sso/verify-credentials";
        }

        return array_values(array_unique($urls));
    }

    /**
     * Unbind account for specified target system.
     */
    public function unbind(Request $request, string $clientId): RedirectResponse
    {
        $user = $request->user();
        $client = SsoClient::findClient($clientId);

        if ($client) {
            $deleted = SsoUserBinding::where('user_id', $user->id)
                ->where('client_id', $client->client_id)
                ->delete();

            if ($deleted) {
                $this->ssoService->logEvent(
                    user: $user,
                    action: 'sso_user_binding_removed',
                    targetType: 'sso_client',
                    targetId: $client->id,
                    details: [
                        'client_id' => $client->client_id,
                    ]
                );
            }
        }

        return back()->with('success', 'Unbound account successfully.');
    }
}
