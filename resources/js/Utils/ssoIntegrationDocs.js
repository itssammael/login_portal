/**
 * SSO Step-by-Step Integration Documentation Generator & PDF Exporter
 * Provides customized integration guides and AI Agent prompts for every supported framework/language.
 */

export const FRAMEWORK_OPTIONS = [
    {
        key: 'laravel_inertia',
        name: 'Laravel + Jetstream + Inertia (Vue.js)',
        shortName: 'Laravel + Inertia',
        icon: '⚡',
        badgeColor: 'bg-red-100 text-red-800 border-red-200',
        description: 'Single-page app with Laravel backend, Jetstream authentication, and Vue 3 frontend.',
    },
    {
        key: 'laravel_livewire',
        name: 'Laravel + Jetstream (Livewire/Blade)',
        shortName: 'Laravel + Livewire',
        icon: '🔥',
        badgeColor: 'bg-rose-100 text-rose-800 border-rose-200',
        description: 'Full-stack reactive components with Laravel, Blade templates, and Livewire.',
    },
    {
        key: 'laravel_blade',
        name: 'Laravel (Standard MVC / Blade)',
        shortName: 'Laravel Blade',
        icon: '🐘',
        badgeColor: 'bg-orange-100 text-orange-800 border-orange-200',
        description: 'Traditional server-rendered Laravel web application using Blade templates and controllers.',
    },
    {
        key: 'php_vanilla',
        name: 'PHP + Vanilla JS / jQuery',
        shortName: 'PHP / jQuery',
        icon: '🌐',
        badgeColor: 'bg-indigo-100 text-indigo-800 border-indigo-200',
        description: 'Standalone PHP application using cURL, PDO, native sessions, and JavaScript / jQuery.',
    },
    {
        key: 'node_express',
        name: 'Node.js / Express',
        shortName: 'Node.js / Express',
        icon: '🟩',
        badgeColor: 'bg-emerald-100 text-emerald-800 border-emerald-300',
        description: 'Server-side Node.js application using Express, express-session, and Axios HTTP client.',
    },
    {
        key: 'python_django',
        name: 'Python / Django',
        shortName: 'Python / Django',
        icon: '🐍',
        badgeColor: 'bg-green-100 text-green-800 border-green-300',
        description: 'Python web application using Django views, ORM, sessions, and requests HTTP library.',
    },
    {
        key: 'dotnet_core',
        name: 'ASP.NET Core (C#)',
        shortName: 'ASP.NET Core',
        icon: '🔷',
        badgeColor: 'bg-blue-100 text-blue-800 border-blue-200',
        description: 'Cross-platform .NET application using ASP.NET Core MVC/Minimal APIs, Cookie Authentication, and EF Core.',
    },
    {
        key: 'spring_boot',
        name: 'Spring Boot (Java)',
        shortName: 'Spring Boot',
        icon: '🍃',
        badgeColor: 'bg-teal-100 text-teal-800 border-teal-200',
        description: 'Enterprise Java web service with Spring Boot, Spring Security, RestTemplate/WebClient, and Spring Data JPA.',
    },
    {
        key: 'nuxt_node',
        name: 'Nuxt.js / Node.js',
        shortName: 'Nuxt.js / Node',
        icon: '💚',
        badgeColor: 'bg-emerald-100 text-emerald-800 border-emerald-300',
        description: 'Server-side rendered (SSR) or hybrid web app with Nuxt 3 and Node.js Nitro server.',
    },
    {
        key: 'vue_spa',
        name: 'Vue.js / React (SPA with Backend Proxy)',
        shortName: 'Vue / React SPA',
        icon: '⚛️',
        badgeColor: 'bg-sky-100 text-sky-800 border-sky-200',
        description: 'Client-side Single Page Application with server-side backend API token exchange proxy.',
    },
    {
        key: 'generic_rest',
        name: 'Generic REST API / Any Language',
        shortName: 'Generic REST API',
        icon: '🛠️',
        badgeColor: 'bg-gray-100 text-gray-800 border-gray-300',
        description: 'Platform-agnostic OAuth 2.0 Authorization Code grant flow specification for any backend stack.',
    },
];

export function getFrameworkMeta(key) {
    return FRAMEWORK_OPTIONS.find(f => f.key === key) || FRAMEWORK_OPTIONS[0];
}

/**
 * Format environment / config placeholders.
 */
function resolveConfigValues({ clientId, clientSecret, redirectUri, portalUrl }) {
    const isPlaceholderId = !clientId || clientId === 'client_sample_id' || clientId === 'client_id';
    const isPlaceholderSecret = !clientSecret || clientSecret === 'sample_client_secret' || clientSecret === 'client_secret';
    const isPlaceholderRedirect = !redirectUri || redirectUri === 'http://localhost:8001/sso/callback';
    const isPlaceholderUrl = !portalUrl || portalUrl === 'http://localhost:8000';

    return {
        portalUrl: isPlaceholderUrl ? 'https://your-login-portal.example' : portalUrl,
        clientId: isPlaceholderId ? 'your_client_id' : clientId,
        clientSecret: isPlaceholderSecret ? 'your_client_secret' : clientSecret,
        redirectUri: isPlaceholderRedirect ? 'https://your-app.example/sso/callback' : redirectUri,
    };
}

/**
 * Build customized integration steps for a client and selected framework.
 */
function buildGuideContent({
    clientName = 'My Application',
    clientId = 'your_client_id',
    clientSecret = 'your_client_secret',
    redirectUri = 'https://your-app.example/sso/callback',
    frameworkKey = 'laravel_inertia',
    portalUrl = 'https://your-login-portal.example',
    meta,
}) {
    const cfg = resolveConfigValues({ clientId, clientSecret, redirectUri, portalUrl });

    switch (frameworkKey) {
        case 'laravel_inertia':
            return {
                title: `${clientName} — SSO Integration Guide for Laravel + Jetstream + Inertia (Vue.js)`,
                framework: meta,
                overview: 'This guide walks through configuring your Laravel + Inertia.js (Vue 3) application to authenticate users centrally via Login Portal Single Sign-On (SSO) using the OAuth 2.0 Authorization Code flow while strictly preserving existing local database accounts and session authentication.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Configure Environment Variables & Services',
                        description: 'Store your Login Portal credentials in .env and expose them via config/services.php. Never hardcode client secrets into source control.',
                        filename: '.env & config/services.php',
                        language: 'bash',
                        code: `# In .env:
LOGIN_PORTAL_URL=${cfg.portalUrl}
LOGIN_PORTAL_CLIENT_ID=${cfg.clientId}
LOGIN_PORTAL_CLIENT_SECRET=${cfg.clientSecret}
LOGIN_PORTAL_REDIRECT_URI=${cfg.redirectUri}

// In config/services.php:
'login_portal' => [
    'url' => env('LOGIN_PORTAL_URL', '${cfg.portalUrl}'),
    'client_id' => env('LOGIN_PORTAL_CLIENT_ID'),
    'client_secret' => env('LOGIN_PORTAL_CLIENT_SECRET'),
    'redirect_uri' => env('LOGIN_PORTAL_REDIRECT_URI'),
],`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Create Identity Mapping Migration & Update User Model',
                        description: 'Add columns to link local users with Login Portal identities. Run `php artisan make:migration add_sso_columns_to_users_table --table=users` then execute `php artisan migrate`. Update $fillable in App\\Models\\User.',
                        filename: 'database/migrations/xxxx_add_sso_columns_to_users_table.php',
                        language: 'php',
                        code: `// Migration:
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->unsignedBigInteger('login_portal_user_id')->nullable()->unique()->after('id');
        $table->string('sso_provider')->nullable()->default('login_portal')->after('login_portal_user_id');
    });
}

// In App\\Models\\User:
protected $fillable = [
    'name',
    'email',
    'password',
    'login_portal_user_id',
    'sso_provider',
];`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Register SSO Web Routes',
                        description: 'Register the redirection and callback routes in routes/web.php. Ensure the callback path exactly matches the registered Redirect URI.',
                        filename: 'routes/web.php',
                        language: 'php',
                        code: `use App\\Http\\Controllers\\Auth\\SsoClientController;

Route::middleware('guest')->group(function () {
    Route::get('/sso/redirect', [SsoClientController::class, 'redirect'])->name('sso.redirect');
    Route::get('/sso/callback', [SsoClientController::class, 'callback'])->name('sso.callback');
});`,
                    },
                    {
                        stepNumber: 4,
                        title: 'Implement SsoClientController',
                        description: 'Create the controller handling state generation, authorization redirect, callback state validation, backchannel authorization-code exchange with grant_type=authorization_code, user matching, safe provisioning, session regeneration, and error handling.',
                        filename: 'app/Http/Controllers/Auth/SsoClientController.php',
                        language: 'php',
                        code: `<?php

namespace App\\Http\\Controllers\\Auth;

use App\\Http\\Controllers\\Controller;
use App\\Models\\User;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Support\\Str;

class SsoClientController extends Controller
{
    public function redirect(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('sso_state', $state);

        $query = http_build_query([
            'client_id' => config('services.login_portal.client_id'),
            'redirect_uri' => config('services.login_portal.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return redirect()->away(rtrim(config('services.login_portal.url'), '/') . '/sso/authorize?' . $query);
    }

    public function callback(Request $request)
    {
        // 1. Validate & Invalidate CSRF State
        $sessionState = $request->session()->pull('sso_state');
        $returnedState = $request->query('state');

        if (! $sessionState || ! $returnedState || ! hash_equals($sessionState, $returnedState)) {
            Log::warning('SSO state validation failed during callback.');
            return redirect()->route('login')->withErrors(['email' => 'Invalid or expired SSO state session. Please try again.']);
        }

        // 2. Validate Authorization Code
        $code = $request->query('code');
        if (! $code) {
            return redirect()->route('login')->withErrors(['email' => 'Authorization code missing from Login Portal response.']);
        }

        // 3. Server-to-Server Backchannel Token Exchange
        try {
            $tokenUrl = rtrim(config('services.login_portal.url'), '/') . '/api/sso/token';
            $response = Http::asForm()->timeout(15)->post($tokenUrl, [
                'grant_type' => 'authorization_code',
                'client_id' => config('services.login_portal.client_id'),
                'client_secret' => config('services.login_portal.client_secret'),
                'redirect_uri' => config('services.login_portal.redirect_uri'),
                'code' => $code,
            ]);
        } catch (\\Exception $e) {
            Log::error('SSO token exchange network exception', ['message' => $e->getMessage()]);
            return redirect()->route('login')->withErrors(['email' => 'Unable to communicate with Login Portal.']);
        }

        if ($response->failed()) {
            Log::error('SSO token exchange failed', ['status' => $response->status()]);
            return redirect()->route('login')->withErrors(['email' => 'SSO authentication failed at provider.']);
        }

        $userData = $response->json('user');
        if (! is_array($userData) || empty($userData['id']) || empty($userData['email'])) {
            return redirect()->route('login')->withErrors(['email' => 'Invalid identity payload received from Login Portal.']);
        }

        // 4. Safe User Matching & Provisioning
        // Primary match: verified Login Portal User ID
        $user = User::where('login_portal_user_id', $userData['id'])->first();

        if (! $user) {
            // Secondary match: existing local account by verified email
            $user = User::where('email', $userData['email'])->first();

            if ($user) {
                // Link existing local account to Login Portal identity
                $user->forceFill([
                    'login_portal_user_id' => $userData['id'],
                    'sso_provider' => 'login_portal',
                ])->save();
            } else {
                // Auto-provision new local user with unguessable random password
                $user = User::create([
                    'name' => $userData['name'] ?? explode('@', $userData['email'])[0],
                    'email' => $userData['email'],
                    'login_portal_user_id' => $userData['id'],
                    'sso_provider' => 'login_portal',
                    'password' => Hash::make(Str::random(32)),
                ]);
            }
        }

        // 5. Establish Local Session with Fixation Protection
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}`,
                    },
                    {
                        stepNumber: 5,
                        title: 'Add SSO Button into Inertia/Vue Login Page',
                        description: 'Insert the "Login with LGUNET Portal" button in resources/js/Pages/Auth/Login.vue. Preserve the existing local username/password credentials form.',
                        filename: 'resources/js/Pages/Auth/Login.vue',
                        language: 'html',
                        code: `<!-- LGUNET Portal Single Sign-On Button -->
<div class="mb-5">
    <a
        :href="route('sso.redirect')"
        class="w-full flex items-center justify-center space-x-2 py-3 px-4 bg-[#1b4332] hover:bg-[#081c15] text-white font-bold text-sm rounded-xl shadow-xs transition duration-150"
    >
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
        </svg>
        <span>Login with LGUNET Portal</span>
    </a>

    <div class="relative flex py-4 items-center">
        <div class="flex-grow border-t border-gray-300"></div>
        <span class="flex-shrink mx-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">or sign in with credentials</span>
        <div class="flex-grow border-t border-gray-300"></div>
    </div>
</div>`,
                    },
                    {
                        stepNumber: 6,
                        title: 'Write Automated Feature Tests (Mocked HTTP)',
                        description: 'Create tests verifying redirect URL, state validation, authorization code exchange, user creation/matching, session regeneration, and local login preservation without depending on a live Login Portal server.',
                        filename: 'tests/Feature/SsoIntegrationTest.php',
                        language: 'php',
                        code: `<?php

namespace Tests\\Feature;

use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Str;
use Tests\\TestCase;

class SsoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sso_redirect_generates_valid_state_and_url()
    {
        $response = $this->get(route('sso.redirect'));
        $response->assertStatus(302);

        $targetUrl = $response->headers->get('Location');
        $this->assertStringContainsString('/sso/authorize', $targetUrl);
        $this->assertStringContainsString('client_id=', $targetUrl);
        $this->assertStringContainsString('response_type=code', $targetUrl);

        $sessionState = session('sso_state');
        $this->assertNotEmpty($sessionState);
        $this->assertStringContainsString('state=' . $sessionState, $targetUrl);
    }

    public function test_sso_callback_rejects_invalid_state()
    {
        session(['sso_state' => 'valid_state_token']);

        $response = $this->get(route('sso.callback', [
            'state' => 'wrong_state',
            'code' => 'auth_code_sample',
        ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_sso_callback_authenticates_and_provisions_new_user()
    {
        $state = Str::random(40);
        session(['sso_state' => $state]);

        Http::fake([
            '*/api/sso/token' => Http::response([
                'access_token' => 'mock_token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'user' => [
                    'id' => 999,
                    'name' => 'Jane Portal User',
                    'email' => 'jane@example.com',
                ],
            ], 200),
        ]);

        $response = $this->get(route('sso.callback', [
            'state' => $state,
            'code' => 'mock_auth_code_123',
        ]));

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals(999, $user->login_portal_user_id);
    }
}`,
                    },
                ],
            };

        case 'laravel_livewire':
        case 'laravel_blade':
            return {
                title: `${clientName} — SSO Integration Guide for ${meta.name}`,
                framework: meta,
                overview: `Integrate Login Portal Single Sign-On (SSO) into your server-rendered Laravel application (${meta.shortName}) using standard Blade views, controllers, and sessions while preserving local authentication.`,
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Environment & Services Configuration',
                        description: 'Configure your credentials in .env and register the service in config/services.php:',
                        filename: '.env & config/services.php',
                        language: 'bash',
                        code: `# In .env:
LOGIN_PORTAL_URL=${cfg.portalUrl}
LOGIN_PORTAL_CLIENT_ID=${cfg.clientId}
LOGIN_PORTAL_CLIENT_SECRET=${cfg.clientSecret}
LOGIN_PORTAL_REDIRECT_URI=${cfg.redirectUri}

// In config/services.php:
'login_portal' => [
    'url' => env('LOGIN_PORTAL_URL', '${cfg.portalUrl}'),
    'client_id' => env('LOGIN_PORTAL_CLIENT_ID'),
    'client_secret' => env('LOGIN_PORTAL_CLIENT_SECRET'),
    'redirect_uri' => env('LOGIN_PORTAL_REDIRECT_URI'),
],`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Add Identity Mapping Columns',
                        description: 'Create migration: php artisan make:migration add_sso_columns_to_users_table --table=users and add login_portal_user_id and sso_provider:',
                        filename: 'database/migrations/xxxx_add_sso_columns_to_users_table.php',
                        language: 'php',
                        code: `public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->unsignedBigInteger('login_portal_user_id')->nullable()->unique()->after('id');
        $table->string('sso_provider')->nullable()->default('login_portal')->after('login_portal_user_id');
    });
}`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Register Routes & SsoClientController',
                        description: 'Register /sso/redirect and /sso/callback in routes/web.php and implement OAuth 2.0 authorization code flow in App\\Http\\Controllers\\Auth\\SsoClientController:',
                        filename: 'app/Http/Controllers/Auth/SsoClientController.php',
                        language: 'php',
                        code: `<?php

namespace App\\Http\\Controllers\\Auth;

use App\\Http\\Controllers\\Controller;
use App\\Models\\User;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Str;

class SsoClientController extends Controller
{
    public function redirect(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('sso_state', $state);

        $params = http_build_query([
            'client_id' => config('services.login_portal.client_id'),
            'redirect_uri' => config('services.login_portal.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return redirect()->away(rtrim(config('services.login_portal.url'), '/') . '/sso/authorize?' . $params);
    }

    public function callback(Request $request)
    {
        $savedState = $request->session()->pull('sso_state');
        if (! $savedState || ! hash_equals($savedState, $request->query('state', ''))) {
            return redirect()->route('login')->withErrors(['email' => 'Invalid SSO state.']);
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect()->route('login')->withErrors(['email' => 'Authorization code missing.']);
        }

        $response = Http::asForm()->post(rtrim(config('services.login_portal.url'), '/') . '/api/sso/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.login_portal.client_id'),
            'client_secret' => config('services.login_portal.client_secret'),
            'redirect_uri' => config('services.login_portal.redirect_uri'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            return redirect()->route('login')->withErrors(['email' => 'SSO Token Exchange failed.']);
        }

        $userData = $response->json('user');
        $user = User::where('login_portal_user_id', $userData['id'])
            ->orWhere('email', $userData['email'])
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => $userData['name'] ?? 'SSO User',
                'email' => $userData['email'],
                'login_portal_user_id' => $userData['id'],
                'sso_provider' => 'login_portal',
                'password' => Hash::make(Str::random(32)),
            ]);
        } else {
            $user->update(['login_portal_user_id' => $userData['id']]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}`,
                    },
                    {
                        stepNumber: 4,
                        title: 'Insert Button into Login Blade View',
                        description: 'Add the SSO login button into resources/views/auth/login.blade.php while preserving the credentials form:',
                        filename: 'resources/views/auth/login.blade.php',
                        language: 'html',
                        code: `<!-- LGUNET Portal Single Sign-On Button -->
<div class="mb-4">
    <a href="{{ route('sso.redirect') }}" class="w-full inline-flex justify-center items-center px-4 py-2.5 bg-[#1b4332] hover:bg-[#081c15] text-white font-bold text-sm rounded-xl shadow-xs transition">
        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
        </svg>
        <span>Login with LGUNET Portal</span>
    </a>
</div>`,
                    },
                    {
                        stepNumber: 5,
                        title: 'Automated Feature Tests',
                        description: 'Add automated tests in tests/Feature/SsoIntegrationTest.php with Http::fake() validating redirection, token exchange, user matching, and session creation.',
                        filename: 'tests/Feature/SsoIntegrationTest.php',
                        language: 'php',
                        code: `public function test_sso_successful_login()
{
    session(['sso_state' => 'test_state']);
    Http::fake([
        '*/api/sso/token' => Http::response(['user' => ['id' => 10, 'email' => 'user@example.com', 'name' => 'User']], 200)
    ]);

    $res = $this->get('/sso/callback?code=valid_code&state=test_state');
    $res->assertRedirect('/dashboard');
    $this->assertAuthenticated();
}`,
                    },
                ],
            };

        case 'php_vanilla':
            return {
                title: `${clientName} — SSO Integration Guide for PHP + Vanilla JS / jQuery`,
                framework: meta,
                overview: 'Direct PHP implementation using cURL to exchange authorization codes for authenticated user sessions using native PHP sessions, PDO, and session fixation protection.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'SSO Configuration File (sso_config.php)',
                        description: 'Store client credentials in an isolated configuration file. Never commit sensitive keys into public repositories:',
                        filename: 'sso_config.php',
                        language: 'php',
                        code: `<?php
define('SSO_PORTAL_URL', getenv('LOGIN_PORTAL_URL') ?: '${cfg.portalUrl}');
define('SSO_CLIENT_ID', getenv('LOGIN_PORTAL_CLIENT_ID') ?: '${cfg.clientId}');
define('SSO_CLIENT_SECRET', getenv('LOGIN_PORTAL_CLIENT_SECRET') ?: '${cfg.clientSecret}');
define('SSO_REDIRECT_URI', getenv('LOGIN_PORTAL_REDIRECT_URI') ?: '${cfg.redirectUri}');
?>`,
                    },
                    {
                        stepNumber: 2,
                        title: 'SSO Initiation Script (sso_redirect.php)',
                        description: 'Generate a cryptographically secure random state token, persist it in $_SESSION, and redirect to the Login Portal authorization endpoint:',
                        filename: 'sso_redirect.php',
                        language: 'php',
                        code: `<?php
require_once __DIR__ . '/sso_config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$state = bin2hex(random_bytes(32));
$_SESSION['sso_state'] = $state;

$params = http_build_query([
    'client_id' => SSO_CLIENT_ID,
    'redirect_uri' => SSO_REDIRECT_URI,
    'response_type' => 'code',
    'state' => $state,
]);

header('Location: ' . rtrim(SSO_PORTAL_URL, '/') . '/sso/authorize?' . $params);
exit;
?>`,
                    },
                    {
                        stepNumber: 3,
                        title: 'SSO Callback Handler (sso_callback.php)',
                        description: 'Validate state against $_SESSION, invalidate it immediately, execute server-to-server cURL token exchange with grant_type=authorization_code, map/provision the user with PDO, and regenerate the session ID:',
                        filename: 'sso_callback.php',
                        language: 'php',
                        code: `<?php
require_once __DIR__ . '/sso_config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Verify and invalidate CSRF state
$sessionState = $_SESSION['sso_state'] ?? null;
unset($_SESSION['sso_state']);
$returnedState = $_GET['state'] ?? '';

if (! $sessionState || ! hash_equals($sessionState, $returnedState)) {
    header('Location: /login.php?error=invalid_state');
    exit;
}

$code = $_GET['code'] ?? null;
if (! $code) {
    header('Location: /login.php?error=missing_code');
    exit;
}

// 2. Server-side token exchange via cURL
$ch = curl_init(rtrim(SSO_PORTAL_URL, '/') . '/api/sso/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => SSO_CLIENT_ID,
        'client_secret' => SSO_CLIENT_SECRET,
        'redirect_uri' => SSO_REDIRECT_URI,
        'code' => $code,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);

$responseBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$tokenData = json_decode($responseBody, true);
if ($httpCode !== 200 || empty($tokenData['user']['id'])) {
    header('Location: /login.php?error=sso_token_exchange_failed');
    exit;
}

$userData = $tokenData['user'];

// 3. User mapping / provisioning via PDO (Example)
// Match existing user by login_portal_user_id or email, else insert new record
// Establish authenticated session with session fixation defense
session_regenerate_id(true);
$_SESSION['auth_user'] = [
    'id' => $userData['id'],
    'name' => $userData['name'],
    'email' => $userData['email'],
    'sso_provider' => 'login_portal',
];

header('Location: /dashboard.php');
exit;
?>`,
                    },
                    {
                        stepNumber: 4,
                        title: 'Insert Button into Login Page (login.php)',
                        description: 'Add the SSO login button into your HTML/PHP login view template while preserving existing credentials authentication:',
                        filename: 'login.php',
                        language: 'html',
                        code: `<!-- LGUNET Portal SSO Login Button -->
<div class="sso-login-wrapper" style="margin-bottom: 20px;">
    <a href="sso_redirect.php" style="display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; padding: 12px 16px; background-color: #1b4332; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
        </svg>
        <span>Login with LGUNET Portal</span>
    </a>
</div>`,
                    },
                ],
            };

        case 'node_express':
            return {
                title: `${clientName} — SSO Integration Guide for Node.js / Express`,
                framework: meta,
                overview: 'Complete OAuth 2.0 Authorization Code Single Sign-On integration for Node.js and Express using express-session, Axios, cryptographically secure state tokens, and session fixation protection.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Environment & Configuration (.env)',
                        description: 'Store credentials in environment variables and load them via dotenv:',
                        filename: '.env & config/sso.js',
                        language: 'javascript',
                        code: `# In .env:
LOGIN_PORTAL_URL=${cfg.portalUrl}
LOGIN_PORTAL_CLIENT_ID=${cfg.clientId}
LOGIN_PORTAL_CLIENT_SECRET=${cfg.clientSecret}
LOGIN_PORTAL_REDIRECT_URI=${cfg.redirectUri}

// In config/sso.js:
require('dotenv').config();

module.exports = {
    portalUrl: process.env.LOGIN_PORTAL_URL || '${cfg.portalUrl}',
    clientId: process.env.LOGIN_PORTAL_CLIENT_ID || '${cfg.clientId}',
    clientSecret: process.env.LOGIN_PORTAL_CLIENT_SECRET,
    redirectUri: process.env.LOGIN_PORTAL_REDIRECT_URI || '${cfg.redirectUri}',
};`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Implement Express SSO Routes (routes/auth.js)',
                        description: 'Create the /sso/redirect and /sso/callback endpoints handling CSRF state, Axios token exchange, user matching/provisioning, and session regeneration:',
                        filename: 'routes/auth.js',
                        language: 'javascript',
                        code: `const express = require('express');
const crypto = require('crypto');
const axios = require('axios');
const router = express.Router();
const config = require('../config/sso');
const User = require('../models/User'); // Your local User model

// 1. SSO Initiation Route
router.get('/sso/redirect', (req, res) => {
    const state = crypto.randomBytes(32).toString('hex');
    req.session.ssoState = state;

    const authUrl = new URL(rtrim(config.portalUrl, '/') + '/sso/authorize');
    authUrl.searchParams.set('client_id', config.clientId);
    authUrl.searchParams.set('redirect_uri', config.redirectUri);
    authUrl.searchParams.set('response_type', 'code');
    authUrl.searchParams.set('state', state);

    res.redirect(authUrl.toString());
});

// 2. SSO Callback Route
router.get('/sso/callback', async (req, res) => {
    const { state, code } = req.query;
    const sessionState = req.session.ssoState;
    delete req.session.ssoState; // Invalidate state immediately

    if (!sessionState || !state || sessionState !== state) {
        return res.redirect('/login?error=invalid_state');
    }
    if (!code) {
        return res.redirect('/login?error=missing_code');
    }

    try {
        // Backchannel token exchange
        const tokenResponse = await axios.post(
            rtrim(config.portalUrl, '/') + '/api/sso/token',
            new URLSearchParams({
                grant_type: 'authorization_code',
                client_id: config.clientId,
                client_secret: config.clientSecret,
                redirect_uri: config.redirectUri,
                code: code,
            }),
            { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, timeout: 15000 }
        );

        const userData = tokenResponse.data.user;
        if (!userData || !userData.id || !userData.email) {
            return res.redirect('/login?error=invalid_user_data');
        }

        // Match or provision local user
        let user = await User.findOne({ where: { login_portal_user_id: userData.id } });
        if (!user) {
            user = await User.findOne({ where: { email: userData.email } });
            if (user) {
                await user.update({ login_portal_user_id: userData.id, sso_provider: 'login_portal' });
            } else {
                user = await User.create({
                    name: userData.name || userData.email.split('@')[0],
                    email: userData.email,
                    login_portal_user_id: userData.id,
                    sso_provider: 'login_portal',
                    password: crypto.randomBytes(32).toString('hex'), // Secure random local password
                });
            }
        }

        // Regenerate session to protect against session fixation
        req.session.regenerate((err) => {
            if (err) return res.redirect('/login?error=session_error');
            req.session.user = { id: user.id, email: user.email, name: user.name };
            res.redirect('/dashboard');
        });
    } catch (error) {
        console.error('SSO callback failed:', error.message);
        res.redirect('/login?error=sso_failed');
    }
});

function rtrim(str, ch) { return str.endsWith(ch) ? str.slice(0, -1) : str; }

module.exports = router;`,
                    },
                    {
                        stepNumber: 3,
                        title: 'UI Button Integration',
                        description: 'Add the SSO login button into your Express view template (EJS / Pug / HTML) while preserving existing credentials form:',
                        filename: 'views/login.ejs',
                        language: 'html',
                        code: `<div class="sso-login-box mb-4">
    <a href="/sso/redirect" class="btn btn-sso" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;background:#1b4332;color:#fff;border-radius:8px;text-decoration:none;font-weight:bold;">
        <span>Login with LGUNET Portal</span>
    </a>
    <div style="text-align:center;margin:15px 0;color:#888;">— or sign in with credentials —</div>
</div>`,
                    },
                    {
                        stepNumber: 4,
                        title: 'Automated Tests with Jest & Supertest',
                        description: 'Write integration tests mocking Axios token exchange to verify authorization code flow and session creation:',
                        filename: 'tests/sso.test.js',
                        language: 'javascript',
                        code: `const request = require('supertest');
const app = require('../app');
const axios = require('axios');
jest.mock('axios');

test('rejects invalid state parameter', async () => {
    const res = await request(app).get('/sso/callback?state=invalid&code=abc');
    expect(res.header.location).toContain('error=invalid_state');
});`,
                    },
                ],
            };

        case 'python_django':
            return {
                title: `${clientName} — SSO Integration Guide for Python / Django`,
                framework: meta,
                overview: 'Integrate Login Portal Single Sign-On (SSO) into a Python Django web application using standard Django authentication, sessions, and the requests library while preserving local user accounts.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Configure Settings (settings.py) & Environment',
                        description: 'Add Login Portal configuration parameters in settings.py:',
                        filename: 'myproject/settings.py',
                        language: 'python',
                        code: `import os

LOGIN_PORTAL_URL = os.getenv('LOGIN_PORTAL_URL', '${cfg.portalUrl}')
LOGIN_PORTAL_CLIENT_ID = os.getenv('LOGIN_PORTAL_CLIENT_ID', '${cfg.clientId}')
LOGIN_PORTAL_CLIENT_SECRET = os.getenv('LOGIN_PORTAL_CLIENT_SECRET', '${cfg.clientSecret}')
LOGIN_PORTAL_REDIRECT_URI = os.getenv('LOGIN_PORTAL_REDIRECT_URI', '${cfg.redirectUri}')`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Custom User Model / Identity Fields',
                        description: 'Add login_portal_user_id to your User model or user profile model:',
                        filename: 'accounts/models.py',
                        language: 'python',
                        code: `from django.contrib.auth.models import AbstractUser
from django.db import models

class User(AbstractUser):
    login_portal_user_id = models.BigIntegerField(null=True, blank=True, unique=True)
    sso_provider = models.CharField(max_length=50, default='login_portal', blank=True)`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Create SSO Views (accounts/views.py)',
                        description: 'Implement authorization redirect, secure state generation, backchannel token exchange with grant_type=authorization_code, user provisioning, and session authentication:',
                        filename: 'accounts/views.py',
                        language: 'python',
                        code: `import secrets
import requests
from django.conf import settings
from django.contrib.auth import login, get_user_model
from django.shortcuts import redirect
from urllib.parse import urlencode

User = get_user_model()

def sso_redirect(request):
    state = secrets.token_urlsafe(32)
    request.session['sso_state'] = state

    params = {
        'client_id': settings.LOGIN_PORTAL_CLIENT_ID,
        'redirect_uri': settings.LOGIN_PORTAL_REDIRECT_URI,
        'response_type': 'code',
        'state': state,
    }
    url = f"{settings.LOGIN_PORTAL_URL.rstrip('/')}/sso/authorize?{urlencode(params)}"
    return redirect(url)

def sso_callback(request):
    session_state = request.session.pop('sso_state', None)
    returned_state = request.GET.get('state')
    code = request.GET.get('code')

    if not session_state or not returned_state or session_state != returned_state:
        return redirect('/accounts/login/?error=invalid_state')
    if not code:
        return redirect('/accounts/login/?error=missing_code')

    # Backchannel token exchange
    token_url = f"{settings.LOGIN_PORTAL_URL.rstrip('/')}/api/sso/token"
    try:
        resp = requests.post(token_url, data={
            'grant_type': 'authorization_code',
            'client_id': settings.LOGIN_PORTAL_CLIENT_ID,
            'client_secret': settings.LOGIN_PORTAL_CLIENT_SECRET,
            'redirect_uri': settings.LOGIN_PORTAL_REDIRECT_URI,
            'code': code,
        }, timeout=15)
        resp.raise_for_status()
        token_data = resp.json()
    except Exception:
        return redirect('/accounts/login/?error=token_exchange_failed')

    user_info = token_data.get('user', {})
    portal_user_id = user_info.get('id')
    email = user_info.get('email')

    if not portal_user_id or not email:
        return redirect('/accounts/login/?error=invalid_user_data')

    # Match or provision local user
    user = User.objects.filter(login_portal_user_id=portal_user_id).first()
    if not user:
        user = User.objects.filter(email=email).first()
        if user:
            user.login_portal_user_id = portal_user_id
            user.sso_provider = 'login_portal'
            user.save(update_fields=['login_portal_user_id', 'sso_provider'])
        else:
            username = email.split('@')[0]
            # Ensure unique username
            base_username = username
            counter = 1
            while User.objects.filter(username=username).exists():
                username = f"{base_username}_{counter}"
                counter += 1
            user = User.objects.create_user(
                username=username,
                email=email,
                first_name=user_info.get('name', ''),
                login_portal_user_id=portal_user_id,
                sso_provider='login_portal',
                password=secrets.token_urlsafe(32),
            )

    login(request, user)
    request.session.cycle_key() # Session fixation protection
    return redirect('/dashboard/')`,
                    },
                    {
                        stepNumber: 4,
                        title: 'Register URLs and UI Button',
                        description: 'Wire up views in urls.py and add the SSO button into your Django login template:',
                        filename: 'accounts/urls.py & templates/registration/login.html',
                        language: 'html',
                        code: `<!-- templates/registration/login.html -->
<div class="sso-section mb-4">
    <a href="{% url 'sso_redirect' %}" class="btn w-100" style="background:#1b4332;color:#fff;padding:12px;font-weight:bold;border-radius:8px;text-align:center;display:block;text-decoration:none;">
        Login with LGUNET Portal
    </a>
    <div style="text-align:center;margin:15px 0;color:#666;">or sign in with password</div>
</div>`,
                    },
                ],
            };

        case 'dotnet_core':
            return {
                title: `${clientName} — SSO Integration Guide for ASP.NET Core (C#)`,
                framework: meta,
                overview: 'OAuth 2.0 Authorization Code Single Sign-On implementation in ASP.NET Core (.NET 8/9) using Cookie Authentication, IHttpClientFactory, secure cryptographic state validation, and EF Core.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Configure appsettings.json',
                        description: 'Add Login Portal configuration options into appsettings.json:',
                        filename: 'appsettings.json',
                        language: 'json',
                        code: `{
  "LoginPortal": {
    "Url": "${cfg.portalUrl}",
    "ClientId": "${cfg.clientId}",
    "ClientSecret": "${cfg.clientSecret}",
    "RedirectUri": "${cfg.redirectUri}"
  }
}`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Implement SsoController.cs',
                        description: 'Handle authorization redirection, session-based state checking, backchannel token exchange with grant_type=authorization_code, user mapping, and Cookie Authentication:',
                        filename: 'Controllers/SsoController.cs',
                        language: 'csharp',
                        code: `using System.Security.Claims;
using System.Security.Cryptography;
using Microsoft.AspNetCore.Authentication;
using Microsoft.AspNetCore.Authentication.Cookies;
using Microsoft.AspNetCore.Mvc;

[Route("sso")]
public class SsoController : Controller
{
    private readonly IConfiguration _config;
    private readonly IHttpClientFactory _httpClientFactory;

    public SsoController(IConfiguration config, IHttpClientFactory httpClientFactory)
    {
        _config = config;
        _httpClientFactory = httpClientFactory;
    }

    [HttpGet("redirect")]
    public IActionResult RedirectToPortal()
    {
        var stateBytes = new byte[32];
        RandomNumberGenerator.Fill(stateBytes);
        var state = Convert.ToHexString(stateBytes);
        HttpContext.Session.SetString("sso_state", state);

        var portalUrl = _config["LoginPortal:Url"].TrimEnd('/');
        var clientId = _config["LoginPortal:ClientId"];
        var redirectUri = Uri.EscapeDataString(_config["LoginPortal:RedirectUri"]);

        return Redirect($"{portalUrl}/sso/authorize?client_id={clientId}&redirect_uri={redirectUri}&response_type=code&state={state}");
    }

    [HttpGet("callback")]
    public async Task<IActionResult> Callback(string? code, string? state)
    {
        var savedState = HttpContext.Session.GetString("sso_state");
        HttpContext.Session.Remove("sso_state");

        if (string.IsNullOrEmpty(savedState) || savedState != state)
            return Redirect("/Account/Login?error=invalid_state");
        if (string.IsNullOrEmpty(code))
            return Redirect("/Account/Login?error=missing_code");

        var client = _httpClientFactory.CreateClient();
        var tokenUrl = $"{_config["LoginPortal:Url"].TrimEnd('/')}/api/sso/token";

        var payload = new Dictionary<string, string>
        {
            { "grant_type", "authorization_code" },
            { "client_id", _config["LoginPortal:ClientId"] },
            { "client_secret", _config["LoginPortal:ClientSecret"] },
            { "redirect_uri", _config["LoginPortal:RedirectUri"] },
            { "code", code }
        };

        var resp = await client.PostAsync(tokenUrl, new FormUrlEncodedContent(payload));
        if (!resp.IsSuccessStatusCode)
            return Redirect("/Account/Login?error=token_exchange_failed");

        var result = await resp.Content.ReadFromJsonAsync<LoginPortalTokenResponse>();
        if (result?.User == null || string.IsNullOrEmpty(result.User.Email))
            return Redirect("/Account/Login?error=invalid_user");

        // Establish Cookie Authentication Session
        var claims = new List<Claim>
        {
            new Claim(ClaimTypes.NameIdentifier, result.User.Id.ToString()),
            new Claim(ClaimTypes.Name, result.User.Name ?? result.User.Email),
            new Claim(ClaimTypes.Email, result.User.Email),
            new Claim("sso_provider", "login_portal")
        };

        var identity = new ClaimsIdentity(claims, CookieAuthenticationDefaults.AuthenticationScheme);
        await HttpContext.SignInAsync(CookieAuthenticationDefaults.AuthenticationScheme, new ClaimsPrincipal(identity));

        return Redirect("/Dashboard");
    }
}

public class LoginPortalTokenResponse
{
    public LoginPortalUser? User { get; set; }
}

public class LoginPortalUser
{
    public long Id { get; set; }
    public string? Name { get; set; }
    public string? Email { get; set; }
}`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Insert Button into Login View (Login.cshtml)',
                        description: 'Add the SSO login button into your ASP.NET Core MVC or Razor Pages login view:',
                        filename: 'Views/Account/Login.cshtml',
                        language: 'html',
                        code: `<div class="mb-3">
    <a href="/sso/redirect" class="btn btn-success w-100 py-2 fw-bold" style="background-color:#1b4332;border:none;">
        Login with LGUNET Portal
    </a>
</div>`,
                    },
                ],
            };

        case 'spring_boot':
            return {
                title: `${clientName} — SSO Integration Guide for Spring Boot (Java)`,
                framework: meta,
                overview: 'Enterprise Java implementation with Spring Boot 3, Spring Security, RestTemplate/WebClient, and session fixation protection.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Configure application.yml',
                        description: 'Add Login Portal configuration properties in application.yml:',
                        filename: 'src/main/resources/application.yml',
                        language: 'yaml',
                        code: `loginportal:
  url: ${cfg.portalUrl}
  client-id: ${cfg.clientId}
  client-secret: ${cfg.clientSecret}
  redirect-uri: ${cfg.redirectUri}`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Create SsoController.java',
                        description: 'Implement /sso/redirect and /sso/callback with state validation, token exchange with grant_type=authorization_code, and Spring Security session creation:',
                        filename: 'src/main/java/com/example/controller/SsoController.java',
                        language: 'java',
                        code: `package com.example.controller;

import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpSession;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.*;
import org.springframework.security.authentication.UsernamePasswordAuthenticationToken;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.stereotype.Controller;
import org.springframework.util.LinkedMultiValueMap;
import org.springframework.util.MultiValueMap;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.client.RestTemplate;

import java.security.SecureRandom;
import java.util.*;

@Controller
public class SsoController {

    @Value("\${loginportal.url}") private String portalUrl;
    @Value("\${loginportal.client-id}") private String clientId;
    @Value("\${loginportal.client-secret}") private String clientSecret;
    @Value("\${loginportal.redirect-uri}") private String redirectUri;

    private final SecureRandom random = new SecureRandom();

    @GetMapping("/sso/redirect")
    public String ssoRedirect(HttpSession session) {
        byte[] bytes = new byte[32];
        random.nextBytes(bytes);
        String state = HexFormat.of().formatHex(bytes);
        session.setAttribute("sso_state", state);

        return "redirect:" + portalUrl.replaceAll("/$", "") + "/sso/authorize"
                + "?client_id=" + clientId
                + "&redirect_uri=" + redirectUri
                + "&response_type=code"
                + "&state=" + state;
    }

    @GetMapping("/sso/callback")
    public String ssoCallback(@RequestParam(required = false) String code,
                              @RequestParam(required = false) String state,
                              HttpSession session,
                              HttpServletRequest request) {
        String savedState = (String) session.getAttribute("sso_state");
        session.removeAttribute("sso_state");

        if (savedState == null || !savedState.equals(state) || code == null) {
            return "redirect:/login?error=invalid_sso";
        }

        RestTemplate restTemplate = new RestTemplate();
        HttpHeaders headers = new HttpHeaders();
        headers.setContentType(MediaType.APPLICATION_FORM_URLENCODED);

        MultiValueMap<String, String> body = new LinkedMultiValueMap<>();
        body.add("grant_type", "authorization_code");
        body.add("client_id", clientId);
        body.add("client_secret", clientSecret);
        body.add("redirect_uri", redirectUri);
        body.add("code", code);

        ResponseEntity<Map> resp = restTemplate.postForEntity(
                portalUrl.replaceAll("/$", "") + "/api/sso/token",
                new HttpEntity<>(body, headers),
                Map.class
        );

        if (resp.getStatusCode() != HttpStatus.OK || resp.getBody() == null) {
            return "redirect:/login?error=sso_failed";
        }

        Map<String, Object> userMap = (Map<String, Object>) resp.getBody().get("user");
        String email = (String) userMap.get("email");

        // Authenticate in Spring Security Context and rotate session
        var auth = new UsernamePasswordAuthenticationToken(email, null, List.of(new SimpleGrantedAuthority("ROLE_USER")));
        SecurityContextHolder.getContext().setAuthentication(auth);
        request.changeSessionId(); // Protect against session fixation

        return "redirect:/dashboard";
    }
}`,
                    },
                ],
            };

        case 'nuxt_node':
            return {
                title: `${clientName} — SSO Integration Guide for Nuxt.js / Node.js`,
                framework: meta,
                overview: 'Server-side route handler in Nuxt 3 Nitro server for Login Portal OAuth 2.0 authorization code flow with secure HTTP-only cookies and state verification.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Nuxt Runtime Config (nuxt.config.ts)',
                        description: 'Define SSO credentials in your Nuxt runtime configuration and .env file:',
                        filename: 'nuxt.config.ts',
                        language: 'typescript',
                        code: `export default defineNuxtConfig({
  runtimeConfig: {
    ssoPortalUrl: process.env.LOGIN_PORTAL_URL || '${cfg.portalUrl}',
    ssoClientId: process.env.LOGIN_PORTAL_CLIENT_ID || '${cfg.clientId}',
    ssoClientSecret: process.env.LOGIN_PORTAL_CLIENT_SECRET || '${cfg.clientSecret}',
    ssoRedirectUri: process.env.LOGIN_PORTAL_REDIRECT_URI || '${cfg.redirectUri}',
  }
})`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Server Redirect Endpoint (server/api/auth/sso/login.get.ts)',
                        description: 'Generate secure state token, store in HTTP-only cookie, and redirect to authorization endpoint:',
                        filename: 'server/api/auth/sso/login.get.ts',
                        language: 'typescript',
                        code: `import crypto from 'crypto'

export default defineEventHandler((event) => {
  const config = useRuntimeConfig()
  const state = crypto.randomBytes(32).toString('hex')

  setCookie(event, 'sso_state', state, { httpOnly: true, secure: process.env.NODE_ENV === 'production', maxAge: 300 })

  const params = new URLSearchParams({
    client_id: config.ssoClientId,
    redirect_uri: config.ssoRedirectUri,
    response_type: 'code',
    state: state,
  })

  return sendRedirect(event, \`\${config.ssoPortalUrl.replace(/\\/$/, '')}/sso/authorize?\${params.toString()}\`)
})`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Server Callback Route (server/routes/sso/callback.ts)',
                        description: 'Validate state cookie, execute token exchange with grant_type=authorization_code, and establish session cookie:',
                        filename: 'server/routes/sso/callback.ts',
                        language: 'typescript',
                        code: `export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const config = useRuntimeConfig()
  const savedState = getCookie(event, 'sso_state')
  deleteCookie(event, 'sso_state')

  if (!query.code || !query.state || query.state !== savedState) {
    return sendRedirect(event, '/login?error=invalid_state')
  }

  try {
    const tokenData = await $fetch<any>(\`\${config.ssoPortalUrl.replace(/\\/$/, '')}/api/sso/token\`, {
      method: 'POST',
      body: {
        grant_type: 'authorization_code',
        client_id: config.ssoClientId,
        client_secret: config.ssoClientSecret,
        redirect_uri: config.ssoRedirectUri,
        code: query.code,
      }
    })

    if (!tokenData?.user?.id) {
      return sendRedirect(event, '/login?error=invalid_user')
    }

    // Set authenticated session cookie
    setCookie(event, 'auth_user', JSON.stringify(tokenData.user), { httpOnly: true, secure: true, sameSite: 'lax' })
    return sendRedirect(event, '/dashboard')
  } catch (err) {
    return sendRedirect(event, '/login?error=sso_failed')
  }
})`,
                    },
                    {
                        stepNumber: 4,
                        title: 'Insert Button into Nuxt Login Page',
                        description: 'Add the SSO login button into your Nuxt login template:',
                        filename: 'pages/login.vue',
                        language: 'html',
                        code: `<template>
  <div class="sso-auth-container mb-4">
    <a
      href="/api/auth/sso/login"
      class="w-full flex items-center justify-center space-x-2 py-3 px-4 bg-[#1b4332] hover:bg-[#081c15] text-white font-bold text-sm rounded-xl shadow-xs transition duration-150"
    >
      <span>Login with LGUNET Portal</span>
    </a>
  </div>
</template>`,
                    },
                ],
            };

        case 'vue_spa':
            return {
                title: `${clientName} — SSO Integration Guide for Vue.js / React (SPA with Backend Proxy)`,
                framework: meta,
                overview: 'Client-side Single Page Application architecture. IMPORTANT: Client secrets must NEVER be exposed in frontend JavaScript. This guide details how the SPA initiates the flow and relies on its backend API proxy for secure token exchange.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Security Architecture Notice: Backend API Proxy Required',
                        description: 'OAuth 2.0 Authorization Code exchange with client_secret MUST occur on a backend service. Never embed client_secret inside browser JavaScript or SPA bundles.',
                        filename: 'Architecture Specification',
                        language: 'bash',
                        code: `[Browser / SPA] ─── 1. Redirect to /sso/authorize ───► [Login Portal]
[Browser / SPA] ◄── 2. Callback with Code & State ──── [Login Portal]
[Browser / SPA] ─── 3. POST /api/auth/sso/callback ──► [Your Backend API]
                                                         │ (client_secret)
                                                         ▼
[Your Backend API] ─── 4. POST /api/sso/token ───────► [Login Portal]
[Browser / SPA] ◄── 5. Set Auth Cookie / Session ───── [Your Backend API]`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Frontend Initiation Helper (src/services/sso.js)',
                        description: 'Generate a cryptographically secure random state, store in sessionStorage, and redirect to the Login Portal:',
                        filename: 'src/services/sso.js',
                        language: 'javascript',
                        code: `export function redirectToLoginPortal() {
  const array = new Uint8Array(20);
  crypto.getRandomValues(array);
  const state = Array.from(array, b => b.toString(16).padStart(2, '0')).join('');
  sessionStorage.setItem('sso_state', state);

  const params = new URLSearchParams({
    client_id: '${cfg.clientId}',
    redirect_uri: '${cfg.redirectUri}',
    response_type: 'code',
    state: state,
  });

  window.location.href = '${cfg.portalUrl}/sso/authorize?' + params.toString();
}`,
                    },
                    {
                        stepNumber: 3,
                        title: 'SPA Callback Route (src/views/SsoCallback.vue)',
                        description: 'Receive the authorization code, verify state from sessionStorage, forward code to your backend proxy for token exchange, and handle session establishment:',
                        filename: 'src/views/SsoCallback.vue',
                        language: 'javascript',
                        code: `<script setup>
import { onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';

const route = useRoute();
const router = useRouter();

onMounted(async () => {
  const { code, state } = route.query;
  const savedState = sessionStorage.getItem('sso_state');
  sessionStorage.removeItem('sso_state');

  if (!code || !state || state !== savedState) {
    router.push('/login?error=invalid_sso_state');
    return;
  }

  // Forward authorization code to your backend service to securely exchange for session
  const res = await fetch('/api/auth/sso/callback', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ code }),
  });

  if (res.ok) {
    router.push('/dashboard');
  } else {
    router.push('/login?error=sso_failed');
  }
});
</script>`,
                    },
                    {
                        stepNumber: 4,
                        title: 'Insert Button into SPA Login Component',
                        description: 'Add the SSO button into your login view component:',
                        filename: 'src/views/Login.vue',
                        language: 'html',
                        code: `<button
    type="button"
    @click="redirectToLoginPortal"
    class="w-full flex items-center justify-center space-x-2 py-3 px-4 bg-[#1b4332] hover:bg-[#081c15] text-white font-bold text-sm rounded-xl shadow-xs transition"
>
    <span>Login with LGUNET Portal</span>
</button>`,
                    },
                ],
            };

        case 'generic_rest':
        default:
            return {
                title: `${clientName} — SSO Integration Guide (${meta.name})`,
                framework: meta,
                overview: 'Universal OAuth 2.0 Authorization Code flow specification. Covers authorization redirect, backchannel token exchange with grant_type=authorization_code, identity mapping, session creation, and security standards.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Authorization Request (Browser Redirect)',
                        description: 'Redirect user to the authorization endpoint with client credentials and unique state:',
                        filename: 'HTTP GET Request',
                        language: 'bash',
                        code: `GET ${cfg.portalUrl}/sso/authorize?client_id=${cfg.clientId}&redirect_uri=${encodeURIComponent(cfg.redirectUri)}&response_type=code&state=CRYPTOGRAPHIC_RANDOM_STATE

# Required Query Parameters:
# client_id: Registered public client identifier
# redirect_uri: Registered callback endpoint URL
# response_type: Must be strictly "code"
# state: Cryptographically secure random CSRF token (stored in session)`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Backchannel Token Exchange Request (Server-to-Server)',
                        description: 'Exchange the returned authorization code for verified user identity via POST. Must contain grant_type=authorization_code:',
                        filename: 'HTTP POST Request',
                        language: 'bash',
                        code: `POST ${cfg.portalUrl}/api/sso/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code&client_id=${cfg.clientId}&client_secret=${cfg.clientSecret}&redirect_uri=${encodeURIComponent(cfg.redirectUri)}&code=RECEIVED_AUTH_CODE`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Expected JSON Response Payload',
                        description: 'Upon successful verification, Login Portal returns tokens and authenticated user identity payload:',
                        filename: 'HTTP Response (200 OK)',
                        language: 'json',
                        code: `{
  "access_token": "sso_auth_bearer_token...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": 42,
    "name": "Juan Dela Cruz",
    "email": "juan@bayawancity.gov.ph",
    "role": "admin"
  }
}`,
                    },
                    {
                        stepNumber: 4,
                        title: 'User Provisioning & Session Lifecycle',
                        description: 'Match user by login_portal_user_id or verified email. Provision new account if not found with an unguessable password. Establish native authenticated session and regenerate session ID to prevent session fixation.',
                        filename: 'Integration Logic',
                        language: 'bash',
                        code: `1. Check database for local user with login_portal_user_id == user.id.
2. If absent, check for user with email == user.email.
3. If absent, provision new local record with random password.
4. Establish local session and regenerate session ID.
5. Redirect user to intended application dashboard.`,
                    },
                    {
                        stepNumber: 5,
                        title: 'Insert Button into Application Login Page',
                        description: 'Render the SSO button on your login screen preserving existing credentials inputs:',
                        filename: 'login.html',
                        language: 'html',
                        code: `<!-- LGUNET Portal Single Sign-On Button -->
<div class="sso-container" style="margin-bottom: 20px;">
    <a
        href="${cfg.portalUrl}/sso/authorize?client_id=${cfg.clientId}&redirect_uri=${encodeURIComponent(cfg.redirectUri)}&response_type=code&state=RANDOM_SECURE_TOKEN"
        style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 12px 20px; background-color: #1b4332; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; border-radius: 8px;"
    >
        <span>Login with LGUNET Portal</span>
    </a>
</div>`,
                    },
                ],
            };
    }
}

/**
 * Generate a comprehensive AI Agent Prompt specifically designed for AI assistants
 * (e.g. Google Antigravity, Claude Code, Cursor, GitHub Copilot, ChatGPT)
 * to autonomously implement SSO into the target project according to its framework.
 */
export function getAiPrompt(frameworkKey, {
    clientName = 'My Application',
    clientId = 'your_client_id',
    clientSecret = 'your_client_secret',
    redirectUri = 'https://your-app.example/sso/callback',
    portalUrl = 'https://your-login-portal.example',
} = {}) {
    const meta = getFrameworkMeta(frameworkKey);
    const cfg = resolveConfigValues({ clientId, clientSecret, redirectUri, portalUrl });

    switch (frameworkKey) {
        case 'laravel_inertia':
            return `Task: Integrate Single Sign-On (SSO) with Central Login Portal
Target Application: ${clientName}
Tech Stack: Laravel + Jetstream + Inertia.js (Vue 3)

You are an expert full-stack Laravel & Inertia.js engineer. Integrate OAuth 2.0 Single Sign-On (SSO) into this repository so users can sign in using the central Login Portal while preserving existing local users and session authentication.

### SSO Credentials & Endpoints (Use Environment Configuration)
- Login Portal Base URL: \${LOGIN_PORTAL_URL} (e.g. ${cfg.portalUrl})
- Client ID: \${LOGIN_PORTAL_CLIENT_ID} (e.g. ${cfg.clientId})
- Client Secret: \${LOGIN_PORTAL_CLIENT_SECRET} (e.g. ${cfg.clientSecret})
- Redirect URI: \${LOGIN_PORTAL_REDIRECT_URI} (e.g. ${cfg.redirectUri})
- Authorization Endpoint: \${LOGIN_PORTAL_URL}/sso/authorize
- Token Exchange Endpoint: \${LOGIN_PORTAL_URL}/api/sso/token
- User Profile API: \${LOGIN_PORTAL_URL}/api/user

### Implementation Instructions

1. Environment & Configuration:
   - In .env, add placeholders:
     LOGIN_PORTAL_URL=${cfg.portalUrl}
     LOGIN_PORTAL_CLIENT_ID=${cfg.clientId}
     LOGIN_PORTAL_CLIENT_SECRET=${cfg.clientSecret}
     LOGIN_PORTAL_REDIRECT_URI=${cfg.redirectUri}
   - In config/services.php, register:
     'login_portal' => [
         'url' => env('LOGIN_PORTAL_URL', '${cfg.portalUrl}'),
         'client_id' => env('LOGIN_PORTAL_CLIENT_ID'),
         'client_secret' => env('LOGIN_PORTAL_CLIENT_SECRET'),
         'redirect_uri' => env('LOGIN_PORTAL_REDIRECT_URI'),
     ],
   - Never hardcode the client secret into source code.

2. User Identity Mapping & Database Migration:
   - Create migration: php artisan make:migration add_sso_columns_to_users_table --table=users
   - Add 'login_portal_user_id' (unsignedBigInteger, nullable, unique, after id).
   - Add 'sso_provider' (string, nullable, default 'login_portal', after login_portal_user_id).
   - In App\\Models\\User, add 'login_portal_user_id' and 'sso_provider' to $fillable.

3. SSO Client Controller (App\\Http\\Controllers\\Auth\\SsoClientController):
   - redirect(Request $request):
     - Generate a cryptographically secure random state (Str::random(40)).
     - Store state in session: $request->session()->put('sso_state', $state).
     - Redirect to config('services.login_portal.url') . '/sso/authorize' with query:
       client_id, redirect_uri, response_type=code, state.
   - callback(Request $request):
     - Retrieve returned state and compare against $request->session()->pull('sso_state') using hash_equals().
     - If state is missing or invalid, terminate SSO safely and redirect to login with an error message.
     - Invalidate/consume the session state immediately to prevent CSRF replay.
     - Retrieve the returned 'code'. If missing, terminate safely with an error message.
     - Exchange authorization code server-side via backchannel POST to config('services.login_portal.url') . '/api/sso/token':
       Include: grant_type=authorization_code, client_id, client_secret, redirect_uri, code.
     - Validate HTTP response. On failure, log safely (redacting secrets and tokens) and redirect to login with error.
     - Extract 'user' object from response. Verify required 'id' and 'email' attributes exist.
     - Match user:
       1. Search for existing local user with matching 'login_portal_user_id'.
       2. If not found, match by verified 'email'. Link 'login_portal_user_id' to the account.
       3. If still not found, auto-provision a new local User with name, email, login_portal_user_id, sso_provider, and a secure random password (Hash::make(Str::random(32))).
     - Call Auth::login($user, remember: true).
     - Protect against session fixation: call $request->session()->regenerate().
     - Redirect to intended destination: redirect()->intended('/dashboard').

4. Routes:
   - In routes/web.php, register:
     Route::get('/sso/redirect', [App\\Http\\Controllers\\Auth\\SsoClientController::class, 'redirect'])->name('sso.redirect');
     Route::get('/sso/callback', [App\\Http\\Controllers\\Auth\\SsoClientController::class, 'callback'])->name('sso.callback');

5. UI Integration:
   - In resources/js/Pages/Auth/Login.vue:
     - Add button "Login with LGUNET Portal" linking to route('sso.redirect').
     - Preserve existing username/email and password form fields so local login remains operational.
     - Display flash/validation errors safely if authentication fails.

6. Automated Testing:
   - In tests/Feature/SsoIntegrationTest.php, create feature tests using Http::fake():
     1. Test SSO initiation generates state and correct authorization URL.
     2. Test callback rejects invalid or missing state parameter.
     3. Test callback rejects missing code parameter.
     4. Test successful code exchange provisions new user and authenticates session.
     5. Test successful code exchange matches and links existing local user by email.
     6. Test token exchange failure redirects to login with error and preserves guest state.
     7. Test session ID regeneration upon successful login.
     8. Never rely on a live Login Portal server during automated test execution.`;

        case 'laravel_livewire':
            return `Task: Integrate Single Sign-On (SSO) with Central Login Portal
Target Application: ${clientName}
Tech Stack: Laravel + Jetstream (Livewire / Blade)

You are an expert Laravel & Livewire engineer. Implement OAuth 2.0 Single Sign-On (SSO) into this application so users can sign in using the central Login Portal while preserving existing local users and password authentication.

### SSO Endpoints & Configuration
- Login Portal URL: \${LOGIN_PORTAL_URL} (${cfg.portalUrl})
- Client ID: \${LOGIN_PORTAL_CLIENT_ID} (${cfg.clientId})
- Client Secret: \${LOGIN_PORTAL_CLIENT_SECRET}
- Redirect URI: \${LOGIN_PORTAL_REDIRECT_URI} (${cfg.redirectUri})
- Authorize: \${LOGIN_PORTAL_URL}/sso/authorize
- Token: \${LOGIN_PORTAL_URL}/api/sso/token

### Implementation Instructions
1. Configure .env and config/services.php with login_portal keys (url, client_id, client_secret, redirect_uri).
2. Create migration adding 'login_portal_user_id' (unsignedBigInteger, nullable, unique) and 'sso_provider' to 'users' table. Update User model fillable.
3. Implement App\\Http\\Controllers\\Auth\\SsoClientController:
   - redirect(): generate random state Str::random(40), store in session('sso_state'), redirect to /sso/authorize with client_id, redirect_uri, response_type=code, state.
   - callback(): validate returned state against session()->pull('sso_state'). Exchange code via Http::asForm()->post('/api/sso/token') with grant_type=authorization_code, client_id, client_secret, redirect_uri, code. Extract user payload, match by login_portal_user_id or email, provision new user if missing with Hash::make(Str::random(32)), login via Auth::login($user, true), call $request->session()->regenerate(), redirect to dashboard.
4. Register routes /sso/redirect and /sso/callback in routes/web.php.
5. In resources/views/auth/login.blade.php: Insert button "Login with LGUNET Portal" linking to route('sso.redirect') while keeping local credentials form intact.
6. Write feature tests in tests/Feature/SsoIntegrationTest.php with Http::fake() mocking token responses.`;

        case 'laravel_blade':
            return `Task: Integrate Single Sign-On (SSO) with Central Login Portal
Target Application: ${clientName}
Tech Stack: Laravel (Standard MVC / Blade)

You are an expert Laravel engineer. Integrate Central Login Portal Single Sign-On (SSO) using the OAuth 2.0 Authorization Code flow into this Laravel MVC application.

### SSO Configuration
- Base URL: ${cfg.portalUrl}
- Client ID: ${cfg.clientId}
- Redirect URI: ${cfg.redirectUri}
- Endpoints: /sso/authorize (Authorize), /api/sso/token (Token Exchange)

### Instructions
1. In .env, define LOGIN_PORTAL_URL, LOGIN_PORTAL_CLIENT_ID, LOGIN_PORTAL_CLIENT_SECRET, LOGIN_PORTAL_REDIRECT_URI.
2. In config/services.php, register 'login_portal' configuration array.
3. Run migration adding 'login_portal_user_id' (nullable, unique) and 'sso_provider' to users table.
4. Implement SsoClientController handling state generation (Str::random(40)), session storage, state verification/invalidation on callback, server-side POST to /api/sso/token with grant_type=authorization_code, user matching/provisioning, Auth::login(), and session regeneration.
5. Register routes in routes/web.php.
6. Add "Login with LGUNET Portal" button in resources/views/auth/login.blade.php preserving the existing login form.
7. Add feature tests in tests/Feature/SsoTest.php using Http::fake().`;

        case 'php_vanilla':
            return `Task: Integrate Single Sign-On (SSO) in Native PHP Application
Target Application: ${clientName}
Tech Stack: PHP + Vanilla JavaScript / jQuery

You are an expert PHP engineer. Integrate OAuth 2.0 Single Sign-On (SSO) into this native PHP project using standard PHP sessions, PDO, and cURL while preserving local user authentication.

### SSO Configuration
- Portal URL: ${cfg.portalUrl}
- Client ID: ${cfg.clientId}
- Client Secret: ${cfg.clientSecret}
- Redirect URI: ${cfg.redirectUri}
- Endpoints: /sso/authorize, /api/sso/token

### Instructions
1. Store credentials in sso_config.php reading from getenv() or local configuration. Never commit production secrets.
2. In sso_redirect.php:
   - Call session_start().
   - Generate state: bin2hex(random_bytes(32)). Save to $_SESSION['sso_state'].
   - Redirect to SSO_PORTAL_URL . '/sso/authorize' with client_id, redirect_uri, response_type=code, state.
3. In sso_callback.php:
   - Call session_start().
   - Validate $_GET['state'] against $_SESSION['sso_state'] using hash_equals(). Invalidate state immediately.
   - Read $_GET['code']. If missing, fail safely.
   - Execute cURL POST to SSO_PORTAL_URL . '/api/sso/token' with grant_type=authorization_code, client_id, client_secret, redirect_uri, and code.
   - Parse JSON response. Validate user ID and email.
   - Match or provision user in local database via PDO.
   - Call session_regenerate_id(true) to prevent session fixation.
   - Store authenticated user in $_SESSION and redirect to dashboard.php.
4. In login.php: Add "Login with LGUNET Portal" button linking to sso_redirect.php while keeping local username/password form.
5. Create test script verifying state rejection, code exchange, and session creation.`;

        case 'node_express':
            return `Task: Integrate Single Sign-On (SSO) in Node.js / Express
Target Application: ${clientName}
Tech Stack: Node.js + Express + express-session + Axios

You are an expert Node.js and Express backend engineer. Implement OAuth 2.0 Authorization Code Single Sign-On (SSO) into this Express application.

### SSO Configuration
- Portal URL: ${cfg.portalUrl}
- Client ID: ${cfg.clientId}
- Client Secret: ${cfg.clientSecret}
- Redirect URI: ${cfg.redirectUri}

### Instructions
1. Load credentials from .env using dotenv into config/sso.js.
2. Ensure express-session middleware is enabled.
3. In routes/auth.js:
   - GET /sso/redirect: Generate crypto.randomBytes(32).toString('hex'). Store in req.session.ssoState. Redirect to /sso/authorize with client_id, redirect_uri, response_type=code, state.
   - GET /sso/callback: Compare req.query.state with req.session.ssoState. Delete req.session.ssoState immediately.
   - Make backchannel POST request via Axios to /api/sso/token with grant_type=authorization_code, client_id, client_secret, redirect_uri, code.
   - Parse response.user. Find or provision local user with hashed random password.
   - Call req.session.regenerate() to prevent session fixation, store authenticated user, and redirect to /dashboard.
4. In login view template: Insert button "Login with LGUNET Portal" pointing to /sso/redirect.
5. Add automated unit/integration tests with Jest & Supertest mocking Axios.`;

        case 'python_django':
            return `Task: Integrate Single Sign-On (SSO) in Python / Django
Target Application: ${clientName}
Tech Stack: Python + Django + Django Sessions + Requests

You are an expert Django engineer. Integrate Central Login Portal Single Sign-On (SSO) into this Django application.

### SSO Configuration
- Portal URL: ${cfg.portalUrl}
- Client ID: ${cfg.clientId}
- Client Secret: ${cfg.clientSecret}
- Redirect URI: ${cfg.redirectUri}

### Instructions
1. In settings.py, configure LOGIN_PORTAL_URL, LOGIN_PORTAL_CLIENT_ID, LOGIN_PORTAL_CLIENT_SECRET, LOGIN_PORTAL_REDIRECT_URI.
2. In User model (or profile model), add login_portal_user_id (BigIntegerField, unique, null=True) and sso_provider.
3. Implement views in accounts/views.py:
   - sso_redirect(request): Generate secrets.token_urlsafe(32), save in request.session['sso_state'], redirect to /sso/authorize with client_id, redirect_uri, response_type=code, state.
   - sso_callback(request): Validate state with request.session.pop('sso_state', None). Send POST request using requests.post() to /api/sso/token with grant_type=authorization_code, client_id, client_secret, redirect_uri, code. Match user by login_portal_user_id or email. If not found, provision user with create_user() and random password. Log in using django.contrib.auth.login(request, user), call request.session.cycle_key() to prevent fixation, redirect to dashboard.
4. Wire URLs in urls.py for /sso/redirect and /sso/callback.
5. In login.html: Add "Login with LGUNET Portal" button while preserving existing authentication form.
6. Write tests using django.test.TestCase with unittest.mock.patch mocking requests.post.`;

        case 'dotnet_core':
            return `Task: Integrate Single Sign-On (SSO) in ASP.NET Core
Target Application: ${clientName}
Tech Stack: C# + ASP.NET Core + Cookie Authentication + EF Core

You are an expert .NET Core engineer. Implement OAuth 2.0 Single Sign-On (SSO) using Login Portal into this ASP.NET Core application.

### SSO Configuration
- Portal URL: ${cfg.portalUrl}
- Client ID: ${cfg.clientId}
- Redirect URI: ${cfg.redirectUri}

### Instructions
1. Configure LoginPortal section in appsettings.json and User Secrets.
2. Update ApplicationUser model and EF Core migration with LoginPortalUserId and SsoProvider.
3. In SsoController:
   - RedirectToPortal(): Generate 32-byte secure random state via RandomNumberGenerator, save in HttpContext.Session, redirect to /sso/authorize with client_id, redirect_uri, response_type=code, state.
   - Callback(): Validate state against session, clear session state. Post FormUrlEncodedContent to /api/sso/token with grant_type=authorization_code, client_id, client_secret, redirect_uri, code. Extract user payload, match or provision local user, create ClaimsPrincipal, call HttpContext.SignInAsync(CookieAuthenticationDefaults.AuthenticationScheme).
4. Add "Login with LGUNET Portal" link in login view.
5. Write integration tests using WebApplicationFactory mocking HttpMessageHandler.`;

        case 'spring_boot':
            return `Task: Integrate Single Sign-On (SSO) in Spring Boot
Target Application: ${clientName}
Tech Stack: Java + Spring Boot 3 + Spring Security + JPA

You are an expert Spring Boot engineer. Implement OAuth 2.0 Authorization Code Single Sign-On (SSO) with the central Login Portal.

### SSO Configuration
- Portal URL: ${cfg.portalUrl}
- Client ID: ${cfg.clientId}
- Redirect URI: ${cfg.redirectUri}

### Instructions
1. In application.yml, define loginportal properties (url, client-id, client-secret, redirect-uri).
2. Update User entity with loginPortalUserId and ssoProvider.
3. Implement SsoController:
   - /sso/redirect: Generate SecureRandom state, store in HttpSession, redirect to /sso/authorize.
   - /sso/callback: Verify and invalidate session state. Execute RestTemplate/WebClient POST to /api/sso/token with grant_type=authorization_code, client_id, client_secret, redirect_uri, code. Match or provision User in database. Set SecurityContextHolder authentication, call request.changeSessionId(), redirect to /dashboard.
4. Add "Login with LGUNET Portal" button in Thymeleaf login.html.
5. Add automated tests with @WebMvcTest and MockRestServiceServer.`;

        case 'nuxt_node':
            return `Task: Integrate Single Sign-On (SSO) in Nuxt 3 / Node.js
Target Application: ${clientName}
Tech Stack: Nuxt 3 + Nitro Server (Node.js)

You are an expert Nuxt 3 & Node.js full-stack engineer. Implement OAuth 2.0 Single Sign-On (SSO) into this Nuxt 3 application with secure server-side routes and session management.

### SSO Configuration
- Login Portal URL: ${cfg.portalUrl}
- Client ID: ${cfg.clientId}
- Client Secret: ${cfg.clientSecret}
- Redirect URI: ${cfg.redirectUri}

### Instructions
1. Add environment variables to .env and runtimeConfig in nuxt.config.ts.
2. Create server/api/auth/sso/login.get.ts to generate crypto.randomBytes(32), set secure HTTP-only sso_state cookie, and redirect to /sso/authorize with client_id, redirect_uri, response_type=code, state.
3. Create server/routes/sso/callback.ts to check sso_state cookie, delete cookie, execute server-side $fetch POST to /api/sso/token with grant_type=authorization_code, client_id, client_secret, redirect_uri, code. Set authenticated session cookie and redirect to /dashboard.
4. In pages/login.vue: Insert button "Login with LGUNET Portal" linking to /api/auth/sso/login while keeping local form.
5. Write Vitest tests with @nuxt/test-utils mocking the token endpoint.`;

        case 'vue_spa':
            return `Task: Integrate Single Sign-On (SSO) in Vue.js / React Single Page Application (SPA)
Target Application: ${clientName}
Tech Stack: Vue.js / React (Client-Side SPA with Backend API Proxy)

You are an expert frontend and API engineer. Implement OAuth 2.0 Authorization Code Single Sign-On (SSO) for this SPA application.

### Security Architecture Requirement
Client secrets must NEVER be placed in frontend code. The SPA initiates redirection, and the backend API proxy performs the code-for-token exchange.

### Instructions
1. Frontend initiation: On SSO button click, generate crypto.getRandomValues() state in sessionStorage, redirect browser to:
   ${cfg.portalUrl}/sso/authorize?client_id=${cfg.clientId}&redirect_uri=${encodeURIComponent(cfg.redirectUri)}&response_type=code&state={state}
2. Frontend callback route (/sso/callback):
   - Compare 'state' query param with sessionStorage state. Invalidate sessionStorage state.
   - Send 'code' via POST to your application's backend API endpoint (e.g. /api/auth/sso/callback).
3. Backend API Proxy:
   - Receives code from SPA.
   - Executes server-to-server POST to ${cfg.portalUrl}/api/sso/token with grant_type=authorization_code, client_id, client_secret, redirect_uri, and code.
   - Provisions local user and returns local session cookie / bearer token to SPA.
4. In Login.vue: Add "Login with LGUNET Portal" button.
5. Write unit tests verifying state validation and proxy dispatch.`;

        case 'generic_rest':
        default:
            return `Task: Implement OAuth 2.0 Single Sign-On (SSO) Integration
Target Application: ${clientName}
Tech Stack: ${meta.name}

You are an expert software engineer. Implement RFC 6749 compliant OAuth 2.0 Authorization Code Single Sign-On (SSO) with the central Login Portal while preserving existing local users and session security.

### SSO Credentials & Endpoints
- Login Portal Base URL: ${cfg.portalUrl}
- Client ID: ${cfg.clientId}
- Client Secret: ${cfg.clientSecret}
- Redirect URI: ${cfg.redirectUri}
- Authorize Endpoint: ${cfg.portalUrl}/sso/authorize
- Token Endpoint: ${cfg.portalUrl}/api/sso/token
- User Profile Endpoint: ${cfg.portalUrl}/api/user

### Implementation Instructions
1. Configuration: Store credentials in environment variables. Keep client secret strictly server-side.
2. Step 1 (Authorize Redirect): Generate a cryptographically secure state parameter and store in server session. Redirect user to:
   ${cfg.portalUrl}/sso/authorize?client_id=${cfg.clientId}&redirect_uri=${encodeURIComponent(cfg.redirectUri)}&response_type=code&state={state}
3. Step 2 (Token Exchange): In callback handler at ${cfg.redirectUri}, validate state against session and invalidate it. Send backchannel POST request to:
   POST ${cfg.portalUrl}/api/sso/token
   Content-Type: application/x-www-form-urlencoded
   grant_type=authorization_code&client_id=${cfg.clientId}&client_secret=${cfg.clientSecret}&redirect_uri=${encodeURIComponent(cfg.redirectUri)}&code={code}
4. Step 3 (User Mapping & Session): Validate returned JSON response containing 'user'. Match existing local account by login_portal_user_id or verified email. Provision new account if not found with unguessable random password. Establish local session and regenerate session ID to prevent session fixation.
5. Step 4 (UI Integration): Insert "Login with LGUNET Portal" button in login form while preserving existing local credentials option.
6. Step 5 (Automated Testing): Test flow using HTTP mocks for redirect URL generation, invalid state rejection, missing code rejection, token exchange, and local session creation.`;
    }
}

/**
 * Main entry point: build complete guide data with steps, metadata, and AI Agent prompt.
 */
export function getIntegrationGuide({
    clientName = 'My Application',
    clientId = 'your_client_id',
    clientSecret = 'your_client_secret',
    redirectUri = 'https://your-app.example/sso/callback',
    frameworkKey = 'laravel_inertia',
    portalUrl = window?.location?.origin || 'https://your-login-portal.example',
}) {
    const meta = getFrameworkMeta(frameworkKey);
    const content = buildGuideContent({
        clientName,
        clientId,
        clientSecret,
        redirectUri,
        frameworkKey,
        portalUrl,
        meta,
    });

    const aiPrompt = getAiPrompt(frameworkKey, {
        clientName,
        clientId,
        clientSecret,
        redirectUri,
        portalUrl,
    });

    return {
        ...content,
        clientName,
        clientId,
        clientSecret,
        redirectUri,
        portalUrl,
        frameworkName: meta.name,
        aiPrompt,
    };
}

/**
 * Print / Save as PDF via executive styled browser printable window.
 */
export function printIntegrationGuide(docData) {
    const printWindow = window.open('', '_blank', 'width=1000,height=950');
    if (!printWindow) {
        alert('Please allow pop-ups to print or save the integration guide as PDF.');
        return;
    }

    const aiPrompt = docData.aiPrompt || getAiPrompt(docData.framework?.key || 'laravel_inertia', {
        clientName: docData.clientName || 'My Application',
        clientId: docData.clientId || 'your_client_id',
        clientSecret: docData.clientSecret || 'your_client_secret',
        redirectUri: docData.redirectUri || 'https://your-app.example/sso/callback',
        portalUrl: docData.portalUrl || window?.location?.origin || 'https://your-login-portal.example',
    });

    const portalUrl = docData.portalUrl || window?.location?.origin || 'https://your-login-portal.example';
    const cleanTitle = (docData.clientName || docData.title || 'SSO Integration Guide')
        .replace(/[^a-zA-Z0-9_-]/g, '_')
        .substring(0, 40);

    const html = `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSO_Integration_Guide_${cleanTitle}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e293b; background: #f8fafc; line-height: 1.55; padding: 24px;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .page-container {
            max-width: 860px; margin: 0 auto; background: #ffffff; border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06); border: 1px solid #e2e8f0;
            overflow: hidden; padding: 36px 42px;
        }
        .action-bar {
            background: #1b4332; color: #ffffff; padding: 12px 20px; border-radius: 10px;
            margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;
            gap: 12px; box-shadow: 0 4px 12px rgba(27, 67, 50, 0.2);
        }
        .action-bar-title { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .action-btns { display: flex; gap: 10px; }
        .btn-action {
            display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
            font-size: 12px; font-weight: 700; border-radius: 8px; cursor: pointer; border: none;
        }
        .btn-primary { background: #d4a373; color: #1b4332; }
        .btn-secondary { background: rgba(255, 255, 255, 0.15); color: #ffffff; }
        .header { border-bottom: 3px solid #1b4332; padding-bottom: 20px; margin-bottom: 24px; }
        .header-org { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #1b4332; margin-bottom: 4px; }
        h1 { font-size: 23px; font-weight: 800; color: #081c15; line-height: 1.25; margin-bottom: 6px; }
        .header-subtitle { font-size: 13px; color: #475569; margin-bottom: 12px; }
        .meta-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .badge {
            display: inline-flex; align-items: center; padding: 4px 10px; font-size: 11px; font-weight: 700;
            border-radius: 6px; background: #e8f5e9; color: #1b4332; border: 1px solid #c8e6c9;
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .badge-navy { background: #f1f5f9; color: #0f172a; border-color: #cbd5e1; }
        .badge-gold { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .overview-box {
            background: #fdfbf7; border: 1px solid #e7dfd5; border-left: 4px solid #1b4332;
            padding: 14px 18px; border-radius: 6px; margin-bottom: 24px; font-size: 13.5px;
            color: #334155; line-height: 1.55;
        }
        .specs-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 28px; }
        .specs-header {
            background: #f1f5f9; padding: 10px 16px; font-size: 11.5px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 0.5px; color: #0f172a; border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; justify-content: space-between;
        }
        .specs-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .specs-table tr { border-bottom: 1px solid #f1f5f9; }
        .specs-table tr:nth-child(even) { background: #f8fafc; }
        .specs-table tr:last-child { border-bottom: none; }
        .specs-table td { padding: 8px 14px; vertical-align: middle; }
        .specs-key { font-weight: 700; color: #475569; width: 22%; white-space: nowrap; }
        .specs-val { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; color: #0f172a; font-size: 11.5px; word-break: break-all; }
        .section-divider {
            margin-top: 32px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2.5px solid #1b4332;
            display: flex; align-items: center; gap: 12px;
        }
        .section-badge { background: #1b4332; color: #ffffff; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 6px; letter-spacing: 0.5px; }
        .section-title { font-size: 17px; font-weight: 800; color: #081c15; margin: 0; }
        .step-card { margin-bottom: 24px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px 20px; page-break-inside: avoid; }
        .step-header { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
        .step-num {
            background: #1b4332; color: #ffffff; width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; flex-shrink: 0;
        }
        .step-title { font-size: 15px; font-weight: 800; color: #0f172a; }
        .step-desc { font-size: 13px; color: #475569; margin-bottom: 10px; line-height: 1.5; }
        .file-label {
            font-size: 11px; font-weight: 700; color: #1b4332; background: #e8f5e9;
            display: inline-block; padding: 3px 8px; border-radius: 4px; margin-bottom: 8px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .code-box {
            background: #0f172a; color: #e2e8f0; padding: 14px 16px; border-radius: 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 11.5px; white-space: pre-wrap; word-break: break-all; line-height: 1.45; border: 1px solid #1e293b;
        }
        .ai-section { margin-top: 36px; page-break-before: always; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 22px; }
        .checklist-box {
            background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #d97706;
            border-radius: 6px; padding: 16px 20px; margin-top: 28px; page-break-inside: avoid;
        }
        .checklist-title { font-size: 13px; font-weight: 800; color: #92400e; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .checklist-item { font-size: 12.5px; color: #78350f; margin-bottom: 6px; line-height: 1.45; }
        .footer {
            margin-top: 36px; border-top: 1px solid #e2e8f0; padding-top: 16px;
            font-size: 11px; color: #64748b; display: flex; align-items: center; justify-content: space-between;
        }
        @media print {
            body { background: #ffffff; padding: 0; }
            .page-container { box-shadow: none; border: none; padding: 0; max-width: 100%; }
            .no-print { display: none !important; }
            .step-card { page-break-inside: avoid; }
            .ai-section { page-break-before: always; }
            @page { size: A4 portrait; margin: 14mm 12mm 16mm 12mm; }
        }
    </style>
</head>
<body>
    <div class="page-container">
        <!-- Interactive Non-Print Header Bar -->
        <div class="action-bar no-print">
            <div class="action-bar-title">
                <span>📄</span>
                <span>SSO Integration Manual — Ready to Print / Export</span>
            </div>
            <div class="action-btns">
                <button onclick="window.print()" class="btn-action btn-primary">
                    🖨️ Save as PDF / Print
                </button>
                <button onclick="window.close()" class="btn-action btn-secondary">
                    ✕ Close
                </button>
            </div>
        </div>

        <!-- Document Cover & Header -->
        <div class="header">
            <div class="header-top">
                <div>
                    <div class="header-org">Republic of the Philippines • Central Authentication Infrastructure</div>
                    <h1>${escapeHtml(docData.title)}</h1>
                    <div class="header-subtitle">
                        Official Technical Integration & Implementation Specification for Single Sign-On (SSO)
                    </div>
                </div>
            </div>
            <div class="meta-badges">
                <span class="badge">${escapeHtml(docData.framework?.name || docData.frameworkName)}</span>
                <span class="badge badge-navy">OAuth 2.0 Auth Code Grant</span>
                <span class="badge badge-gold">Part 1: Manual + Part 2: AI Agent</span>
            </div>
        </div>

        <!-- Overview -->
        <div class="overview-box">
            <strong>Executive Overview:</strong> ${escapeHtml(docData.overview)}
        </div>

        <!-- Specifications & Credentials Table -->
        <div class="specs-card">
            <div class="specs-header">
                <span>Registered Application Configuration & Target Endpoints</span>
                <span style="font-size: 10.5px; font-weight: normal; color: #64748b;">CONFIDENTIAL</span>
            </div>
            <table class="specs-table">
                <tbody>
                    <tr>
                        <td class="specs-key">Client ID:</td>
                        <td class="specs-val">${escapeHtml(docData.clientId || 'your_client_id')}</td>
                        <td class="specs-key">Authorization URL:</td>
                        <td class="specs-val">${escapeHtml(portalUrl)}/sso/authorize</td>
                    </tr>
                    <tr>
                        <td class="specs-key">Client Secret:</td>
                        <td class="specs-val">${escapeHtml(docData.clientSecret || 'your_client_secret')}</td>
                        <td class="specs-key">Token Endpoint:</td>
                        <td class="specs-val">${escapeHtml(portalUrl)}/api/sso/token</td>
                    </tr>
                    <tr>
                        <td class="specs-key">Redirect URI:</td>
                        <td class="specs-val">${escapeHtml(docData.redirectUri || 'https://your-app.example/sso/callback')}</td>
                        <td class="specs-key">User Profile API:</td>
                        <td class="specs-val">${escapeHtml(portalUrl)}/api/user</td>
                    </tr>
                    <tr>
                        <td class="specs-key">Grant Type:</td>
                        <td class="specs-val">authorization_code</td>
                        <td class="specs-key">Default Scopes:</td>
                        <td class="specs-val">openid profile email</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Part 1 Divider -->
        <div class="section-divider">
            <span class="section-badge">PART 1</span>
            <h2 class="section-title">Step-by-Step Manual Integration Guide</h2>
        </div>

        <!-- Part 1 Steps -->
        ${docData.steps.map(step => `
            <div class="step-card">
                <div class="step-header">
                    <div class="step-num">${step.stepNumber}</div>
                    <div class="step-title">${escapeHtml(step.title)}</div>
                </div>
                <div class="step-desc">${escapeHtml(step.description)}</div>
                ${step.filename ? `<div class="file-label">📁 TARGET FILE: ${escapeHtml(step.filename)}</div>` : ''}
                <div class="code-box">${escapeHtml(step.code)}</div>
            </div>
        `).join('')}

        <!-- Security & Production Readiness Checklist -->
        <div class="checklist-box">
            <div class="checklist-title">🛡️ Security & Production Readiness Checklist</div>
            <div class="checklist-item">• <strong>HTTPS / TLS Mandatory:</strong> Production environments MUST serve all redirect URIs and API calls over encrypted HTTPS.</div>
            <div class="checklist-item">• <strong>CSRF State Verification & Invalidation:</strong> Always generate a cryptographically secure random state token, store in protected session, verify on callback, and invalidate immediately.</div>
            <div class="checklist-item">• <strong>Server-Side Secret Protection:</strong> Client secret must NEVER be exposed to frontend browser JavaScript or committed to git. Perform token exchange server-side.</div>
            <div class="checklist-item">• <strong>Safe Identity Mapping:</strong> Match returning users by login_portal_user_id first; fallback to verified email. Never silently merge unverified accounts.</div>
            <div class="checklist-item">• <strong>Session Fixation Protection:</strong> Always regenerate the application session ID immediately upon authenticating the user locally.</div>
            <div class="checklist-item">• <strong>Safe Logging & Redaction:</strong> Never log client secrets, access tokens, or authorization codes in application or web server logs.</div>
            <div class="checklist-item">• <strong>Automated Test Isolation:</strong> Always mock Login Portal responses in automated test suites (e.g. Http::fake). Never depend on live portal servers in CI.</div>
            <div class="checklist-item">• <strong>Preserve Local Authentication:</strong> Ensure local username/password login remains available alongside SSO unless explicitly deprecated.</div>
        </div>

        <!-- Part 2 Divider & AI Agent Section -->
        <div class="ai-section">
            <div class="section-divider" style="margin-top: 0; border-color: #1e293b;">
                <span class="section-badge" style="background: #1e293b;">PART 2</span>
                <h2 class="section-title">Integration using AI Coding Agent</h2>
            </div>
            <p style="font-size: 13px; color: #475569; margin-bottom: 14px; line-height: 1.5;">
                Copy and paste the following autonomous prompt into your AI coding assistant (Google Antigravity, Claude Code, Cursor, GitHub Copilot) to automatically implement and test the SSO integration into your repository:
            </p>
            <div class="code-box" style="white-space: pre-wrap; font-size: 11px; line-height: 1.45; background: #0b1329;">${escapeHtml(aiPrompt)}</div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <span>Login Portal Central Authentication System • Enterprise SSO Engine</span>
            <span>Generated: ${new Date().toLocaleString()}</span>
        </div>
    </div>

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 350);
        };
    </script>
</body>
</html>`;

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * Pure JavaScript Vector PDF 1.4 Layout Engine (Zero External Dependencies)
 * Generates an executive-grade, multi-font, colored, paginated PDF document (.pdf).
 * Contains BOTH:
 *  1. Step-by-Step Manual Integration Guide
 *  2. Integration using AI Agent Prompt
 */
export class StructuredPdfBuilder {
    constructor(title = 'SSO Integration Guide') {
        this.title = title;
        this.pageWidth = 612; // Letter width in pt
        this.pageHeight = 792; // Letter height in pt
        this.margin = 36;
        this.contentWidth = this.pageWidth - (this.margin * 2); // 540 pt
        this.topMargin = 750;
        this.bottomMargin = 46;

        this.pages = [];
        this.currentPage = null;
        this.y = this.topMargin;
        this.pageNumber = 0;

        this.startNewPage();
    }

    startNewPage() {
        this.pageNumber++;
        this.currentPage = {
            num: this.pageNumber,
            ops: [],
            textOps: [],
        };
        this.pages.push(this.currentPage);
        this.y = this.topMargin;

        // Running header on pages 2+
        if (this.pageNumber > 1) {
            this.addRect(this.margin, 762, this.contentWidth, 1.5, [0.106, 0.263, 0.196], null);
            this.addText('LOGIN PORTAL - ENTERPRISE SSO INTEGRATION SPECIFICATION', this.margin, 767, '/F2', 7.5, [0.106, 0.263, 0.196]);
            this.addText(this.title, this.pageWidth - this.margin, 767, '/F1', 7.5, [0.4, 0.4, 0.4], 'right');
            this.y = 745;
        }
    }

    ensureSpace(neededHeight) {
        if (this.y - neededHeight < this.bottomMargin) {
            this.startNewPage();
        }
    }

    addRect(x, y, w, h, fillRgb = null, strokeRgb = null, lineWidth = 1) {
        let op = '';
        if (fillRgb) {
            op += `${fillRgb[0].toFixed(3)} ${fillRgb[1].toFixed(3)} ${fillRgb[2].toFixed(3)} rg `;
        }
        if (strokeRgb) {
            op += `${strokeRgb[0].toFixed(3)} ${strokeRgb[1].toFixed(3)} ${strokeRgb[2].toFixed(3)} RG ${lineWidth} w `;
        }
        op += `${x.toFixed(1)} ${y.toFixed(1)} ${w.toFixed(1)} ${h.toFixed(1)} re `;
        if (fillRgb && strokeRgb) {
            op += 'B\n';
        } else if (fillRgb) {
            op += 'f\n';
        } else if (strokeRgb) {
            op += 'S\n';
        }
        this.currentPage.ops.push(op);
    }

    addLine(x1, y1, x2, y2, strokeRgb = [0.8, 0.8, 0.8], lineWidth = 1) {
        const op = `${strokeRgb[0].toFixed(3)} ${strokeRgb[1].toFixed(3)} ${strokeRgb[2].toFixed(3)} RG ${lineWidth} w ${x1.toFixed(1)} ${y1.toFixed(1)} m ${x2.toFixed(1)} ${y2.toFixed(1)} l S\n`;
        this.currentPage.ops.push(op);
    }

    addText(text, x, y, font = '/F1', size = 9, colorRgb = [0.1, 0.1, 0.1], align = 'left') {
        const clean = escapePdfText(text);
        let posX = x;
        if (align === 'right') {
            const charW = (font.includes('/F4') || font.includes('/F5')) ? (size * 0.6) : (size * 0.5);
            posX = x - (clean.length * charW);
        } else if (align === 'center') {
            const charW = (font.includes('/F4') || font.includes('/F5')) ? (size * 0.6) : (size * 0.5);
            posX = x - ((clean.length * charW) / 2);
        }

        const op = `BT ${font} ${size} Tf ${colorRgb[0].toFixed(3)} ${colorRgb[1].toFixed(3)} ${colorRgb[2].toFixed(3)} rg ${posX.toFixed(1)} ${y.toFixed(1)} Td (${clean}) Tj ET\n`;
        this.currentPage.textOps.push(op);
    }

    wrapText(text, maxWidth, charWidth) {
        const maxChars = Math.max(20, Math.floor(maxWidth / charWidth));
        const words = (text || '').split(/\\s+/);
        const lines = [];
        let currentLine = '';

        for (const word of words) {
            if (!currentLine) {
                currentLine = word;
            } else if ((currentLine + ' ' + word).length <= maxChars) {
                currentLine += ' ' + word;
            } else {
                lines.push(currentLine);
                currentLine = word;
            }
        }
        if (currentLine) {
            lines.push(currentLine);
        }
        return lines.length > 0 ? lines : [''];
    }

    drawBanner(clientName, frameworkName, portalUrl) {
        const h = 76;
        this.ensureSpace(h);
        const topY = this.y;
        const botY = topY - h;

        this.addRect(this.margin, botY, this.contentWidth, h, [0.106, 0.263, 0.196], null);
        this.addRect(this.margin, topY - 3, this.contentWidth, 3, [0.84, 0.65, 0.25], null);

        this.addText('LOGIN PORTAL - ENTERPRISE SSO INTEGRATION GUIDE', this.margin + 16, topY - 20, '/F2', 13, [1, 1, 1]);
        this.addText(`Target System: ${clientName}  |  Stack: ${frameworkName}`, this.margin + 16, topY - 36, '/F1', 9.5, [0.95, 0.97, 0.95]);
        this.addText(`Portal Base URL: ${portalUrl}  |  Protocol: OAuth 2.0 Auth Code  |  Confidential`, this.margin + 16, topY - 50, '/F1', 8, [0.82, 0.9, 0.82]);
        this.addText(`Date: ${new Date().toLocaleDateString()}`, this.margin + this.contentWidth - 16, topY - 50, '/F1', 8, [0.82, 0.9, 0.82], 'right');

        this.addRect(this.margin + 16, botY + 7, 360, 14, [0.06, 0.18, 0.13], null);
        this.addText('COMPLETE PACKAGE: PART 1 (MANUAL STEPS) & PART 2 (AI AGENT PROMPT)', this.margin + 22, botY + 11, '/F2', 6.8, [0.9, 0.96, 0.9]);

        this.y = botY - 12;
    }

    drawOverviewBox(overviewText) {
        const charW = 8 * 0.52;
        const wrapped = this.wrapText(overviewText, this.contentWidth - 28, charW);
        const h = 22 + (wrapped.length * 10.5);
        this.ensureSpace(h);
        const botY = this.y - h;

        this.addRect(this.margin, botY, this.contentWidth, h, [0.97, 0.98, 0.97], [0.8, 0.86, 0.82], 1);
        this.addRect(this.margin, botY, 4, h, [0.106, 0.263, 0.196], null);

        this.addText('EXECUTIVE ARCHITECTURE OVERVIEW', this.margin + 14, this.y - 12, '/F2', 8.5, [0.106, 0.263, 0.196]);
        let lineY = this.y - 23;
        wrapped.forEach(l => {
            this.addText(l, this.margin + 14, lineY, '/F1', 8, [0.22, 0.22, 0.22]);
            lineY -= 10.5;
        });

        this.y = botY - 10;
    }

    drawCredentialsCard(credentials) {
        const rows = [
            ['Client ID', credentials.clientId || 'your_client_id', 'Authorization URL', `${credentials.portalUrl}/sso/authorize`],
            ['Client Secret', credentials.clientSecret || 'your_client_secret', 'Token Endpoint', `${credentials.portalUrl}/api/sso/token`],
            ['Redirect URI', credentials.redirectUri || 'https://your-app.example/sso/callback', 'User Profile Endpoint', `${credentials.portalUrl}/api/user`],
            ['Grant Type', 'authorization_code', 'Default Scopes', 'openid profile email'],
        ];

        const rowH = 16;
        const h = 22 + (rows.length * rowH);
        this.ensureSpace(h);
        const botY = this.y - h;

        this.addRect(this.margin, botY, this.contentWidth, h, [0.98, 0.98, 0.99], [0.82, 0.85, 0.88], 1);
        this.addRect(this.margin, this.y - 16, this.contentWidth, 16, [0.92, 0.94, 0.97], null);
        this.addText('REGISTERED CLIENT CONFIGURATION & TARGET ENDPOINTS', this.margin + 10, this.y - 11, '/F2', 7.8, [0.15, 0.2, 0.3]);

        let curY = this.y - 26;
        rows.forEach((r, idx) => {
            if (idx % 2 === 1) {
                this.addRect(this.margin, curY - 4, this.contentWidth, rowH, [0.96, 0.97, 0.98], null);
            }
            this.addText(r[0] + ':', this.margin + 10, curY, '/F2', 7.5, [0.35, 0.4, 0.45]);
            this.addText(r[1], this.margin + 75, curY, '/F4', 7.2, [0.1, 0.15, 0.2]);

            this.addText(r[2] + ':', this.margin + 265, curY, '/F2', 7.5, [0.35, 0.4, 0.45]);
            this.addText(r[3], this.margin + 365, curY, '/F4', 7.2, [0.1, 0.15, 0.2]);

            curY -= rowH;
        });

        this.y = botY - 12;
    }

    drawSectionHeading(partLabel, title, bgRgb = [0.106, 0.263, 0.196]) {
        const h = 22;
        this.ensureSpace(h + 20);
        const botY = this.y - h;

        this.addLine(this.margin, botY, this.margin + this.contentWidth, botY, bgRgb, 1.5);
        this.addRect(this.margin, botY + 2, 54, 16, bgRgb, null);
        this.addText(partLabel, this.margin + 27, botY + 6, '/F2', 8, [1, 1, 1], 'center');
        this.addText(title, this.margin + 64, botY + 6, '/F2', 10.5, [0.08, 0.12, 0.1]);

        this.y = botY - 12;
    }

    drawStep(step) {
        const descCharW = 8 * 0.52;
        const descLines = this.wrapText(step.description, this.contentWidth - 20, descCharW);
        const codeLines = (step.code || '').split('\n');

        const headerH = 24;
        const descH = (descLines.length * 10) + 4;
        const fileLabelH = step.filename ? 12 : 0;
        const isLongCode = codeLines.length > 50;
        const codeLineH = isLongCode ? 8.0 : 8.6;
        const codeFontSize = isLongCode ? 6.5 : 6.8;
        const minSpaceNeeded = headerH + descH + fileLabelH + 40;

        this.ensureSpace(minSpaceNeeded);

        // Step Header Bar
        this.addRect(this.margin, this.y - 17, 56, 16, [0.106, 0.263, 0.196], null);
        this.addText(`STEP ${step.stepNumber}`, this.margin + 28, this.y - 12, '/F2', 8, [1, 1, 1], 'center');
        this.addText(step.title, this.margin + 64, this.y - 12, '/F2', 9.5, [0.08, 0.12, 0.1]);
        this.y -= 25;

        // Description
        descLines.forEach(dl => {
            this.addText(dl, this.margin + 8, this.y, '/F1', 8, [0.25, 0.25, 0.25]);
            this.y -= 10;
        });

        // File label
        if (step.filename) {
            this.addText(`TARGET FILE: ${step.filename}`, this.margin + 8, this.y, '/F2', 7.5, [0.3, 0.4, 0.35]);
            this.y -= 12;
        }

        // Render code lines with multi-page handling
        let lineIdx = 0;
        while (lineIdx < codeLines.length) {
            const availH = this.y - this.bottomMargin - 18;
            let linesThisBatch = Math.floor((availH - 12) / codeLineH);

            if (linesThisBatch < 4) {
                this.startNewPage();
                if (step.filename) {
                    this.addText(`TARGET FILE (CONTINUED): ${step.filename}`, this.margin + 8, this.y, '/F2', 7.5, [0.3, 0.4, 0.35]);
                    this.y -= 12;
                }
                linesThisBatch = Math.floor((this.y - this.bottomMargin - 30) / codeLineH);
            }

            const batch = codeLines.slice(lineIdx, lineIdx + linesThisBatch);
            const boxH = (batch.length * codeLineH) + 10;
            const boxY = this.y - boxH;

            this.addRect(this.margin, boxY, this.contentWidth, boxH, [0.95, 0.96, 0.98], [0.82, 0.85, 0.9], 0.75);

            let curCodeY = this.y - 8;
            batch.forEach(cl => {
                const tr = cl.substring(0, 115);
                this.addText(tr, this.margin + 10, curCodeY, '/F4', codeFontSize, [0.1, 0.14, 0.2]);
                curCodeY -= codeLineH;
            });

            this.y = boxY - 12;
            lineIdx += batch.length;

            if (lineIdx < codeLines.length) {
                this.startNewPage();
                this.addText(`STEP ${step.stepNumber}: ${step.title} (CONTINUED)`, this.margin + 8, this.y, '/F2', 8.5, [0.106, 0.263, 0.196]);
                this.y -= 14;
            }
        }
    }

    drawAiPromptSection(aiPrompt) {
        this.startNewPage();
        this.drawSectionHeading('PART 2', 'INTEGRATION USING AI CODING AGENT', [0.15, 0.23, 0.38]);

        const introText = 'You can pass this structured prompt directly to an autonomous AI coding assistant (Google Antigravity, Claude Code, Cursor, GitHub Copilot) to automatically inspect your codebase, create necessary controllers, register routes, and wire the OAuth 2.0 flow.';
        const wrappedIntro = this.wrapText(introText, this.contentWidth - 20, 8 * 0.52);

        wrappedIntro.forEach(l => {
            this.addText(l, this.margin + 6, this.y, '/F1', 8, [0.2, 0.2, 0.25]);
            this.y -= 10.5;
        });
        this.y -= 4;

        // Prompt container with multi-page handling
        const promptLines = (aiPrompt || '').split('\n');
        const codeLineH = promptLines.length > 60 ? 7.6 : 8.2;
        const codeFontSize = promptLines.length > 60 ? 6.2 : 6.5;
        let lineIdx = 0;

        while (lineIdx < promptLines.length) {
            const availH = this.y - this.bottomMargin - 18;
            let linesThisBatch = Math.floor((availH - 12) / codeLineH);

            if (linesThisBatch < 6) {
                this.startNewPage();
                linesThisBatch = Math.floor((this.y - this.bottomMargin - 24) / codeLineH);
            }

            const batch = promptLines.slice(lineIdx, lineIdx + linesThisBatch);
            const boxH = (batch.length * codeLineH) + 10;
            const boxY = this.y - boxH;

            this.addRect(this.margin, boxY, this.contentWidth, boxH, [0.96, 0.97, 0.98], [0.8, 0.83, 0.88], 0.75);

            let curY = this.y - 8;
            batch.forEach(line => {
                const tr = line.substring(0, 115);
                this.addText(tr, this.margin + 10, curY, '/F4', codeFontSize, [0.1, 0.12, 0.16]);
                curY -= codeLineH;
            });

            this.y = boxY - 12;
            lineIdx += batch.length;

            if (lineIdx < promptLines.length) {
                this.startNewPage();
                this.addText('PART 2: AI AGENT PROMPT (CONTINUED)', this.margin + 8, this.y, '/F2', 8.5, [0.15, 0.23, 0.38]);
                this.y -= 14;
            }
        }
    }

    drawVerificationNotes() {
        this.ensureSpace(120);
        const h = 112;
        const botY = this.y - h;

        this.addRect(this.margin, botY, this.contentWidth, h, [0.98, 0.97, 0.94], [0.88, 0.82, 0.72], 1);
        this.addRect(this.margin, botY, 4, h, [0.84, 0.65, 0.25], null);

        this.addText('SECURITY & PRODUCTION READINESS CHECKLIST', this.margin + 14, this.y - 12, '/F2', 8.2, [0.55, 0.35, 0.1]);

        const checks = [
            '1. HTTPS Requirement: Production environments MUST serve all redirect URIs and API calls over encrypted HTTPS/TLS.',
            '2. CSRF State Token: Always generate a cryptographically secure state token before redirecting; verify and invalidate on callback.',
            '3. Client Secret Storage: Never expose client_secret to browser JS or commit to git. Store strictly in server environment (.env).',
            '4. Safe Identity Mapping: Match returning users by login_portal_user_id first; fallback to verified email. Never merge unsafely.',
            '5. Session Fixation Defense: Always regenerate application session ID immediately after authenticating local user session.',
            '6. Safe Logging: Redact client secrets, authorization codes, and access tokens from all application and web server logs.',
            '7. Mocked Automated Tests: Test the full SSO flow using HTTP mocks (e.g. Http::fake). Never depend on live portal servers in CI.',
            '8. Preserve Local Auth: Keep standard username/password credentials login available alongside SSO unless explicitly deprecated.',
        ];

        let curY = this.y - 24;
        checks.forEach(c => {
            this.addText(c, this.margin + 14, curY, '/F1', 7.3, [0.3, 0.25, 0.15]);
            curY -= 10.5;
        });

        this.y = botY - 12;
    }

    compile() {
        const totalPages = this.pages.length;

        // Apply running footers to all pages
        this.pages.forEach((p, idx) => {
            const footSep = `0.85 0.85 0.85 RG 0.5 w ${this.margin.toFixed(1)} 36.0 m ${(this.pageWidth - this.margin).toFixed(1)} 36.0 l S\n`;
            p.ops.push(footSep);

            const leftText = `BT /F1 7.5 Tf 0.45 0.45 0.45 rg ${this.margin.toFixed(1)} 26.0 Td (LOGIN PORTAL SSO SPECIFICATION  -  CONFIDENTIAL DEVELOPER GUIDE) Tj ET\n`;
            const pageStr = `Page ${idx + 1} of ${totalPages}`;
            const rightPos = this.pageWidth - this.margin - (pageStr.length * 4.2);
            const rightText = `BT /F2 7.5 Tf 0.3 0.3 0.3 rg ${rightPos.toFixed(1)} 26.0 Td (${pageStr}) Tj ET\n`;

            p.textOps.push(leftText);
            p.textOps.push(rightText);
        });

        const objects = [];
        let objCount = 0;
        function addObj(content) {
            objCount++;
            objects.push({ num: objCount, content });
            return objCount;
        }

        const f1 = addObj(`<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>`);
        const f2 = addObj(`<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>`);
        const f3 = addObj(`<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique >>`);
        const f4 = addObj(`<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>`);
        const f5 = addObj(`<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>`);

        const fontResources = `<< /F1 ${f1} 0 R /F2 ${f2} 0 R /F3 ${f3} 0 R /F4 ${f4} 0 R /F5 ${f5} 0 R >>`;

        const pageObjNums = [];

        this.pages.forEach(p => {
            const streamContent = p.ops.join('') + p.textOps.join('');
            const streamLen = streamContent.length;
            const contentObjNum = addObj(`<< /Length ${streamLen} >>\nstream\n${streamContent}endstream`);
            const pageObjNum = addObj(`<< /Type /Page /Parent 0 0 R /MediaBox [0 0 ${this.pageWidth} ${this.pageHeight}] /Contents ${contentObjNum} 0 R /Resources << /Font ${fontResources} >> >>`);
            pageObjNums.push(pageObjNum);
        });

        const pagesKids = pageObjNums.map(n => `${n} 0 R`).join(' ');
        const pagesObjNum = addObj(`<< /Type /Pages /Kids [${pagesKids}] /Count ${pageObjNums.length} >>`);

        pageObjNums.forEach(pNum => {
            const pObj = objects.find(o => o.num === pNum);
            pObj.content = pObj.content.replace('/Parent 0 0 R', `/Parent ${pagesObjNum} 0 R`);
        });

        const catalogObjNum = addObj(`<< /Type /Catalog /Pages ${pagesObjNum} 0 R >>`);

        let output = `%PDF-1.4\n`;
        const offsets = [];

        objects.forEach(obj => {
            offsets.push(output.length);
            output += `${obj.num} 0 obj\n${obj.content}\nendobj\n`;
        });

        const xrefOffset = output.length;
        output += `xref\n0 ${objCount + 1}\n0000000000 65535 f \n`;

        offsets.forEach(off => {
            const padded = String(off).padStart(10, '0');
            output += `${padded} 00000 n \n`;
        });

        output += `trailer\n<< /Size ${objCount + 1} /Root ${catalogObjNum} 0 R >>\nstartxref\n${xrefOffset}\n%%EOF\n`;

        // Accurate binary Uint8Array conversion for universal browser compatibility
        const bytes = new Uint8Array(output.length);
        for (let i = 0; i < output.length; i++) {
            bytes[i] = output.charCodeAt(i) & 0xff;
        }

        return new Blob([bytes], { type: 'application/pdf' });
    }
}

function escapePdfText(str) {
    if (!str) return '';
    return String(str)
        .replace(/[\u2018\u2019]/g, "'")
        .replace(/[\u201C\u201D]/g, '"')
        .replace(/[\u2013\u2014]/g, ' - ')
        .replace(/[\u2022]/g, '*')
        .replace(/[^\x20-\x7E\t\r\n]/g, ' ')
        .replace(/\\/g, '\\\\')
        .replace(/\(/g, '\\(')
        .replace(/\)/g, '\\)');
}

/**
 * Generate a standalone publication-quality binary PDF file (.pdf) directly in browser.
 * Contains BOTH:
 *  1. Step-by-Step Manual Integration Guide
 *  2. Integration using AI Agent Prompt
 */
export function downloadIntegrationPdf(docData) {
    try {
        const clientName = docData.clientName || docData.title || 'My Application';
        const frameworkName = docData.framework?.name || docData.frameworkName || 'Web Application';
        const portalUrl = docData.portalUrl || window?.location?.origin || 'https://your-login-portal.example';

        const aiPrompt = docData.aiPrompt || getAiPrompt(docData.framework?.key || 'laravel_inertia', {
            clientName,
            clientId: docData.clientId || 'your_client_id',
            clientSecret: docData.clientSecret || 'your_client_secret',
            redirectUri: docData.redirectUri || 'https://your-app.example/sso/callback',
            portalUrl,
        });

        const builder = new StructuredPdfBuilder(clientName);
        builder.drawBanner(clientName, frameworkName, portalUrl);
        builder.drawOverviewBox(docData.overview || 'Central authentication specification using Login Portal OAuth 2.0 Single Sign-On.');
        builder.drawCredentialsCard({
            ...docData,
            portalUrl,
        });
        builder.drawSectionHeading('PART 1', 'STEP-BY-STEP MANUAL INTEGRATION GUIDE');

        if (Array.isArray(docData.steps)) {
            docData.steps.forEach(step => {
                builder.drawStep(step);
            });
        }

        builder.drawVerificationNotes();
        builder.drawAiPromptSection(aiPrompt);

        const pdfBlob = builder.compile();
        const url = URL.createObjectURL(pdfBlob);
        const a = document.createElement('a');
        a.href = url;
        const cleanName = clientName.replace(/[^a-zA-Z0-9_-]/g, '_').substring(0, 40);
        a.download = `SSO_Integration_Guide_${cleanName}.pdf`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch (e) {
        console.error('Failed to generate binary PDF, falling back to print dialog:', e);
        printIntegrationGuide(docData);
    }
}
