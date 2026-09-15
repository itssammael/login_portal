<?php

namespace App\Http\Controllers\Sso;

use App\Http\Controllers\Controller;
use App\Models\SsoClient;
use App\Models\SsoUserBinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ConnectedSystemsController extends Controller
{
    /**
     * Display the Connected Systems binding page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Ensure default clients exist
        SsoClient::findClient('lfews_client_id');
        SsoClient::findClient('project_tracker_client_id');

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
                break;
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

        SsoUserBinding::updateOrCreate(
            [
                'user_id' => $user->id,
                'client_id' => $client->client_id,
            ],
            [
                'external_user_id' => (string) $externalUser['id'],
                'external_username' => $externalUser['username'] ?? $externalUser['email'],
                'is_verified' => true,
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
     * Resolve candidate API URLs for target system verification.
     */
    protected function resolveCandidateApiUrls(SsoClient $client, Request $request): array
    {
        $urls = [];
        $redirectUris = array_map('trim', explode(',', $client->redirect_uri ?: ''));

        foreach ($redirectUris as $uri) {
            if (empty($uri)) {
                continue;
            }

            $parsed = parse_url($uri);
            if (! isset($parsed['host'])) {
                continue;
            }

            $scheme = $parsed['scheme'] ?? $request->getScheme();
            $host = $parsed['host'];
            $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
            $path = '/api/sso/verify-credentials';

            $urls[] = "{$scheme}://{$host}{$port}{$path}";

            // If configured host is localhost/127.0.0.1 and request came via network IP/domain, add request host candidate
            $reqHost = $request->getHost();
            if (in_array($host, ['127.0.0.1', 'localhost']) && ! in_array($reqHost, ['127.0.0.1', 'localhost'])) {
                $urls[] = "{$scheme}://{$reqHost}{$port}{$path}";
            }

            // If configured host is network IP and request came via localhost/127.0.0.1, add 127.0.0.1 candidate
            if (! in_array($host, ['127.0.0.1', 'localhost']) && in_array($reqHost, ['127.0.0.1', 'localhost'])) {
                $urls[] = "{$scheme}://127.0.0.1{$port}{$path}";
            }
        }

        // Additional fallbacks based on known default client ports
        $lowerId = strtolower($client->client_id);
        $reqHost = $request->getHost();
        $scheme = $request->getScheme();

        if (str_contains($lowerId, 'lfews')) {
            $urls[] = "{$scheme}://{$reqHost}:8001/api/sso/verify-credentials";
            $urls[] = 'http://127.0.0.1:8001/api/sso/verify-credentials';
        } elseif (str_contains($lowerId, 'tracker') || str_contains($lowerId, 'project')) {
            $urls[] = "{$scheme}://{$reqHost}:8002/api/sso/verify-credentials";
            $urls[] = 'http://127.0.0.1:8002/api/sso/verify-credentials';
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
            SsoUserBinding::where('user_id', $user->id)
                ->where('client_id', $client->client_id)
                ->delete();
        }

        return back()->with('success', 'Unbound account successfully.');
    }
}
