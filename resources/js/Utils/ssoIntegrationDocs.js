/**
 * SSO Step-by-Step Integration Documentation Generator & PDF Exporter
 * Provides customized integration guides, AI Agent prompts, and troubleshooting tips
 * for every supported framework/language, synchronized with the SSO Server implementation.
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
        description: 'Server-side rendered (SSR) or hybrid web app with Nuxt 3/4 and Node.js Nitro server.',
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
 * Shared Step 1: [SSO Server] Register and Configure the Client on the SSO Server.
 */
function buildServerRegistrationStep(clientName, cfg, frameworkLabel) {
    return {
        stepNumber: 1,
        title: '[SSO Server] [Required] Register Client & Obtain Credentials',
        description: 'Purpose: Register your client application in the SSO Portal Admin Console so the SSO server recognizes your client_id, validates your allowed callback URLs, and can verify user credentials during Connected Systems account binding. Note: The SSO server owns sso_clients, sso_authorization_codes, sso_user_bindings, and ssos; your client database does NOT create those tables.',
        filename: '[SSO Server] Admin Console (/admin/sso)',
        language: 'text',
        code: `# Where this step runs: [SSO Server] (${cfg.portalUrl}/admin/sso)
# 1. Sign in to the SSO Server as an administrator and open Admin -> SSO Management (/admin/sso).
# 2. Click "Register New Application" and configure:
#    - Application Name : ${clientName} (used as {clientName} slug in GET /sso/launch/{clientName})
#    - Redirect URI     : ${cfg.redirectUri}
#                         (exact scheme, host, port, and path; multiple allowed URIs may be comma-separated)
#    - API Base URL     : Base URL of your client app (e.g. https://your-app.example)
#                         Used by ConnectedSystemsController to call POST /api/sso/verify-credentials
#    - Tech Stack       : ${frameworkLabel}
#    - Active Status    : Enabled (is_active = true)
#
# 3. Save and copy the generated credentials:
#    - Client ID     (32-char UUID-like hex, SsoClient::generateClientId()): ${cfg.clientId}
#    - Client Secret (64-char alphanumeric, SsoClient::generateClientSecret()): ${cfg.clientSecret}
#      (Copy immediately from the modal; rotating via Regenerate Secret immediately invalidates the old secret.)
#
# Verification ([SSO Server]):
# Confirm "${clientName}" appears with status "Active" in /admin/sso and in /connected-systems.`,
    };
}

/**
 * Shared Step 2 for Laravel stacks: Environment Variables & Service Configuration.
 */
function buildLaravelEnvAndConfigStep(cfg) {
    return {
        stepNumber: 2,
        title: '[Client Application] [Required] Configure Environment Variables & Services',
        description: 'Purpose: Store your Login Portal credentials and session cookie settings in .env and expose them via config/services.php so configuration caching (php artisan config:cache) works reliably in production. Prerequisites: PHP ^8.2 (8.3 recommended), Laravel 10.x/11.x/12.x/13.x, ext-curl, ext-json, ext-mbstring, ext-openssl. Never hardcode secrets in source files.',
        filename: '[Client Application] .env & config/services.php',
        language: 'php',
        code: `# 1. In [Client Application] .env:
# Base URL of the central SSO Server (no trailing slash)
LOGIN_PORTAL_URL=${cfg.portalUrl}

# 32-character Client ID and 64-character Client Secret from Step 1
LOGIN_PORTAL_CLIENT_ID=${cfg.clientId}
LOGIN_PORTAL_CLIENT_SECRET=${cfg.clientSecret}

# Callback URL registered on the SSO Server (must match character-for-character)
LOGIN_PORTAL_REDIRECT_URI=${cfg.redirectUri}

# Authorization boundary: true = auto-create local User on first SSO login;
# false = only allow pre-existing local accounts to sign in
LOGIN_PORTAL_AUTO_PROVISION=true

# Session & Cookie settings required so session('sso_state') survives the cross-site redirect
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false   # Set to true in HTTPS production
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax         # MUST be 'lax' (NOT 'strict')

// 2. In [Client Application] config/services.php:
'login_portal' => [
    'url' => rtrim((string) env('LOGIN_PORTAL_URL', '${cfg.portalUrl}'), '/'),
    'client_id' => env('LOGIN_PORTAL_CLIENT_ID'),
    'client_secret' => env('LOGIN_PORTAL_CLIENT_SECRET'),
    'redirect_uri' => env('LOGIN_PORTAL_REDIRECT_URI'),
    'auto_provision' => (bool) env('LOGIN_PORTAL_AUTO_PROVISION', true),
],

# 3. Verification ([Client Application] terminal):
# php artisan config:clear
# php artisan config:show services.login_portal`,
    };
}

/**
 * Shared Step 3 for Laravel stacks: Database Schema & Non-Destructive Migrations (New vs Existing Client).
 */
function buildLaravelDatabaseStep() {
    return {
        stepNumber: 3,
        title: '[Client Application] [Required] Database Schema & Migrations (New vs. Existing App)',
        description: 'Purpose: Ensure the client has the users and sessions tables and adds login_portal_user_id (nullable, unique) and sso_provider to users. Do NOT create sso_clients, sso_authorization_codes, sso_user_bindings, or ssos on the client. Use Option A for a brand-new client or Option B to adapt an existing users table without losing data.',
        filename: '[Client Application] database/migrations/xxxx_add_sso_columns_to_users_table.php',
        language: 'php',
        code: `<?php
// Where this runs: [Client Application]
// Option A (New Client App): Ensure default Laravel create_users_table (with sessions table) is present,
// or run Option B below on top of the framework's default users migration.
// Option B (Existing Client App - Non-Destructive):
// Run: php artisan make:migration add_sso_columns_to_users_table --table=users --no-interaction
// If using SESSION_DRIVER=database and 'sessions' table is missing:
// Run: php artisan session:table --no-interaction

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'login_portal_user_id')) {
                $table->unsignedBigInteger('login_portal_user_id')
                    ->nullable()
                    ->unique('users_login_portal_user_id_unique')
                    ->after('id');
            }

            if (! Schema::hasColumn('users', 'sso_provider')) {
                $table->string('sso_provider', 64)
                    ->nullable()
                    ->default('login_portal')
                    ->index('users_sso_provider_index')
                    ->after('login_portal_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'login_portal_user_id')) {
                $table->dropUnique('users_login_portal_user_id_unique');
                $table->dropColumn('login_portal_user_id');
            }

            if (Schema::hasColumn('users', 'sso_provider')) {
                $table->dropIndex('users_sso_provider_index');
                $table->dropColumn('sso_provider');
            }
        });
    }
};

// In [Client Application] app/Models/User.php:
// Add 'login_portal_user_id' and 'sso_provider' to $fillable, and cast 'login_portal_user_id' => 'integer'.
//
// Verification ([Client Application] terminal):
// php artisan migrate --no-interaction
// php artisan tinker --execute "dump(Schema::getColumnListing('users'));"`,
    };
}

/**
 * Shared Step 4 for Laravel stacks: Account Binding Credential Verification Endpoint.
 */
function buildLaravelBindEndpointStep() {
    return {
        stepNumber: 4,
        title: '[Client Application] [Required] Implement Connected Systems Account Binding Endpoint',
        description: 'Purpose: Before the SSO Server issues an authorization code at GET /sso/authorize, it requires an active SsoUserBinding record linking the Portal user to a local account in your client app. When a user binds their account in the Portal (/connected-systems), the Portal sends a server-to-server POST request (application/x-www-form-urlencoded, 5s timeout) to /api/sso/verify-credentials with username, password, and client_secret.',
        filename: '[Client Application] app/Http/Controllers/Auth/SsoVerifyCredentialsController.php & routes/api.php',
        language: 'php',
        code: `<?php
// File 1: [Client Application] app/Http/Controllers/Auth/SsoVerifyCredentialsController.php

namespace App\\Http\\Controllers\\Auth;

use App\\Http\\Controllers\\Controller;
use App\\Models\\User;
use Illuminate\\Http\\JsonResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Schema;

class SsoVerifyCredentialsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configuredSecret = (string) config('services.login_portal.client_secret');

        if ($configuredSecret === '') {
            return response()->json(['valid' => false, 'message' => 'SSO client secret is not configured.'], 500);
        }

        // Validate shared client_secret in constant time
        $providedSecret = (string) $request->input('client_secret', '');
        if ($providedSecret === '' || ! hash_equals($configuredSecret, $providedSecret)) {
            return response()->json(['valid' => false, 'message' => 'Invalid client secret.'], 401);
        }

        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifier = $validated['username'];
        $query = User::query()->where('email', $identifier);
        if (Schema::hasColumn('users', 'username')) {
            $query->orWhere('username', $identifier);
        }

        $user = $query->first();
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['valid' => false, 'message' => 'Invalid username or password for this system.'], 401);
        }

        if (isset($user->status) && in_array($user->status, ['banned', 'suspended', 'inactive'], true)) {
            return response()->json(['valid' => false, 'message' => 'This local account is inactive or suspended.'], 403);
        }

        return response()->json([
            'valid' => true,
            'user_id' => (string) $user->getKey(), // Stored by Portal as bound_user_id
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }
}

// File 2: [Client Application] routes/api.php (and optionally routes/web.php without CSRF for /sso/verify-credentials):
// use App\\Http\\Controllers\\Auth\\SsoVerifyCredentialsController;
// Route::post('/sso/verify-credentials', SsoVerifyCredentialsController::class)->name('api.sso.verify-credentials');
//
// Verification ([Client Application] terminal):
// php artisan route:list --path=sso/verify-credentials`,
    };
}

/**
 * Shared Step 5 for Laravel stacks: SsoClientController (Redirect, PKCE, State, Nonce, Callback, Token Exchange, 4-Tier Identity Mapping).
 */
function buildLaravelControllerStep() {
    return {
        stepNumber: 5,
        title: '[Client Application] [Required] Implement SsoClientController & Web Routes',
        description: 'Purpose: Handle GET /sso/redirect (CSRF state + PKCE S256 code_challenge + OIDC nonce) and GET /sso/callback (state validation, backchannel POST /api/sso/token with code_verifier, nonce verification, 4-tier user resolution from response.user, authorization boundary checks, and session regeneration). Important: Consume response.user directly from POST /api/sso/token; do NOT call GET /api/user or GET /api/sso/userinfo with access_token because the SSO server returns an ephemeral token not stored in Sanctum.',
        filename: '[Client Application] app/Http/Controllers/Auth/SsoClientController.php & routes/web.php',
        language: 'php',
        code: `<?php
// File 1: [Client Application] routes/web.php
use App\\Http\\Controllers\\Auth\\SsoClientController;
use Illuminate\\Support\\Facades\\Route;

Route::get('/sso/redirect', [SsoClientController::class, 'redirect'])->name('sso.redirect');
Route::get('/sso/callback', [SsoClientController::class, 'callback'])->name('sso.callback');

// File 2: [Client Application] app/Http/Controllers/Auth/SsoClientController.php
namespace App\\Http\\Controllers\\Auth;

use App\\Http\\Controllers\\Controller;
use App\\Models\\User;
use Illuminate\\Http\\Client\\ConnectionException;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Support\\Str;

class SsoClientController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $portalUrl = (string) config('services.login_portal.url');
        $clientId = (string) config('services.login_portal.client_id');
        $redirectUri = (string) config('services.login_portal.redirect_uri');

        if ($portalUrl === '' || $clientId === '' || $redirectUri === '') {
            Log::error('SSO configuration is incomplete.');
            return redirect()->route('login')->withErrors([
                'email' => 'Single Sign-On is not configured on this application.',
            ]);
        }

        // Generate CSRF state, OIDC nonce, and PKCE S256 verifier + challenge
        $state = Str::random(40);
        $nonce = Str::random(40);
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $request->session()->put([
            'sso_state' => $state,
            'sso_nonce' => $nonce,
            'sso_code_verifier' => $codeVerifier,
        ]);

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid profile email',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'nonce' => $nonce,
        ]);

        return redirect()->away("{$portalUrl}/sso/authorize?{$query}");
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            $request->session()->forget(['sso_state', 'sso_nonce', 'sso_code_verifier']);
            $desc = (string) $request->query('error_description', 'Access was denied by the Login Portal.');
            return redirect()->route('login')->withErrors(['email' => $desc]);
        }

        $expectedState = (string) $request->session()->pull('sso_state', '');
        $expectedNonce = (string) $request->session()->pull('sso_nonce', '');
        $codeVerifier = (string) $request->session()->pull('sso_code_verifier', '');
        $returnedState = (string) $request->query('state', '');

        // Support IdP-initiated launch (/sso/launch/{clientName}) where no local sso_state exists yet:
        // If you want seamless dashboard launch, redirect once to route('sso.redirect') when $expectedState === ''
        if ($expectedState === '' || $returnedState === '' || ! hash_equals($expectedState, $returnedState)) {
            return redirect()->route('login')->withErrors([
                'email' => 'Invalid or expired SSO session state. Please try signing in again.',
            ]);
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->route('login')->withErrors([
                'email' => 'Authorization code was not provided by the Login Portal.',
            ]);
        }

        $portalUrl = (string) config('services.login_portal.url');
        $clientId = (string) config('services.login_portal.client_id');
        $clientSecret = (string) config('services.login_portal.client_secret');
        $redirectUri = (string) config('services.login_portal.redirect_uri');

        try {
            $payload = [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ];
            if ($codeVerifier !== '') {
                $payload['code_verifier'] = $codeVerifier;
            }

            $tokenResponse = Http::asForm()->acceptJson()->timeout(15)->post("{$portalUrl}/api/sso/token", $payload);
        } catch (ConnectionException $e) {
            Log::error('SSO token exchange connection failed', ['message' => $e->getMessage()]);
            return redirect()->route('login')->withErrors([
                'email' => 'The central Login Portal is currently unreachable. Please try again later.',
            ]);
        }

        if (! $tokenResponse->successful()) {
            Log::warning('SSO token exchange rejected', [
                'status' => $tokenResponse->status(),
                'error' => $tokenResponse->json('error'),
                'error_description' => $tokenResponse->json('error_description'),
            ]);
            return redirect()->route('login')->withErrors([
                'email' => 'Unable to verify your Single Sign-On credentials. Please try again.',
            ]);
        }

        $data = $tokenResponse->json();
        if ($expectedNonce !== '' && (! isset($data['nonce']) || ! hash_equals($expectedNonce, (string) $data['nonce']))) {
            return redirect()->route('login')->withErrors([
                'email' => 'SSO security nonce mismatch. Please try signing in again.',
            ]);
        }

        // Extract identity directly from the token response ('user' key)
        $portalUser = $data['user'] ?? null;
        if (! is_array($portalUser) || empty($portalUser['id']) || empty($portalUser['email'])) {
            return redirect()->route('login')->withErrors([
                'email' => 'The Login Portal returned an incomplete user profile.',
            ]);
        }

        $user = $this->resolveLocalUser($portalUser);
        if (! $user) {
            return redirect()->route('login')->withErrors([
                'email' => 'Your Portal account is not authorized to access this application.',
            ]);
        }

        // Authorization boundary check: verify local account status before establishing session
        if (isset($user->status) && in_array($user->status, ['banned', 'suspended', 'inactive'], true)) {
            return redirect()->route('login')->withErrors([
                'email' => 'Your local account in this application has been suspended or deactivated.',
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    protected function resolveLocalUser(array $portalUser): ?User
    {
        $portalId = (int) $portalUser['id'];
        $boundUserId = $portalUser['bound_user_id'] ?? null;
        $email = (string) $portalUser['email'];
        $name = (string) ($portalUser['name'] ?? $email);

        // Tier 1: Match by explicit bound_user_id from sso_user_bindings
        $user = null;
        if (! empty($boundUserId)) {
            $user = User::find($boundUserId);
        }

        // Tier 2: Match by stable login_portal_user_id
        if (! $user) {
            $user = User::where('login_portal_user_id', $portalId)->first();
        }

        // Tier 3: Match by email and link login_portal_user_id
        if (! $user) {
            $user = User::where('email', $email)->first();
        }

        if ($user) {
            $updates = [];
            if ($user->login_portal_user_id !== $portalId) {
                $updates['login_portal_user_id'] = $portalId;
            }
            if (empty($user->sso_provider)) {
                $updates['sso_provider'] = 'login_portal';
            }
            if (! empty($updates)) {
                $user->forceFill($updates)->save();
            }
            return $user;
        }

        // Tier 4: First-login auto-provisioning (if enabled)
        if (! config('services.login_portal.auto_provision', true)) {
            return null;
        }

        $newUser = new User([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'login_portal_user_id' => $portalId,
            'sso_provider' => 'login_portal',
        ]);

        if (! empty($portalUser['email_verified_at'])) {
            $newUser->email_verified_at = now();
        }

        $newUser->save();
        return $newUser;
    }
}`,
    };
}

/**
 * Shared Step 7 for Laravel stacks: Local vs SSO Logout, Authorization Boundaries & Production Deployment.
 */
function buildLaravelLogoutAndDeploymentStep(cfg) {
    return {
        stepNumber: 7,
        title: '[Client Application] [Optional / Production] Logout Scope, Authorization & Deployment',
        description: 'Purpose: Understand token lifecycle, Local Logout vs. SSO Logout scope, authorization boundaries, and production HTTPS/session configuration. The SSO server does not issue refresh_tokens or run OIDC Single Logout (SLO). Local POST /logout ends the client session only; logging out of the Portal ends the central session only.',
        filename: '[Client Application] Production & Logout Reference',
        language: 'bash',
        code: `# 1. Token Lifecycle & Logout Scope:
# - Authorization Code: 2-minute TTL (SsoAuthorizationCode::EXPIRE_MINUTES = 2), single-use (used = true).
# - Access Token: Ephemeral 80-char token with expires_in = 3600; identity is returned directly in POST /api/sso/token.
# - Local Logout: Calling Auth::guard('web')->logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
#   terminates ONLY the client session. To let the user also sign out of the central Portal after local logout,
#   you may optionally redirect them to: ${cfg.portalUrl}
#
# 2. Authorization Boundaries:
# - Passing SSO authentication proves WHO the user is on the central Portal.
# - For restricted internal systems where new users must NOT be auto-created, set in [Client Application] .env:
#   LOGIN_PORTAL_AUTO_PROVISION=false
# - Always enforce application-specific roles/permissions via Laravel Policies, Gates, or middleware.
#
# 3. Production Deployment Commands ([Client Application]):
# Ensure HTTPS is active, TrustProxies is configured if behind Nginx/Cloudflare, and .env has:
# SESSION_SECURE_COOKIE=true
# SESSION_HTTP_ONLY=true
# SESSION_SAME_SITE=lax
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm run build`,
    };
}

/**
 * Shared Step 8 for Laravel stacks: End-to-End Verification & Mocked Feature Tests.
 */
function buildLaravelAutomatedTestsStep() {
    return {
        stepNumber: 8,
        title: '[Client Application] [Required] End-to-End Verification & Automated Feature Tests',
        description: 'Purpose: Verify the complete SSO integration (redirect with state + PKCE + nonce, state mismatch rejection, new user provisioning, existing user account-linking, token failure handling, and /api/sso/verify-credentials binding verification) using Http::fake() without calling a live SSO server in CI.',
        filename: '[Client Application] tests/Feature/SsoIntegrationTest.php',
        language: 'php',
        code: `<?php
// Create test in [Client Application]: php artisan make:test --phpunit SsoIntegrationTest --no-interaction

namespace Tests\\Feature;

use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class SsoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.login_portal.url' => 'https://portal.example.test',
            'services.login_portal.client_id' => 'test_client_id_32_characters_hex',
            'services.login_portal.client_secret' => 'test_client_secret_64_characters_alphanumeric_value_for_testing_1',
            'services.login_portal.redirect_uri' => 'http://localhost/sso/callback',
            'services.login_portal.auto_provision' => true,
        ]);
    }

    public function test_sso_redirect_stores_state_pkce_and_nonce_and_redirects_to_portal(): void
    {
        $response = $this->get(route('sso.redirect'));
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://portal.example.test/sso/authorize?', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
        $response->assertSessionHas(['sso_state', 'sso_nonce', 'sso_code_verifier']);
    }

    public function test_sso_callback_rejects_invalid_state(): void
    {
        $response = $this->withSession(['sso_state' => 'expected_state'])
            ->get(route('sso.callback', ['code' => 'valid_code', 'state' => 'tampered_state']));
        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sso_callback_provisions_and_authenticates_user(): void
    {
        Http::fake([
            'https://portal.example.test/api/sso/token' => Http::response([
                'access_token' => str_repeat('a', 80),
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'nonce' => 'expected_nonce',
                'user' => [
                    'id' => 42,
                    'bound_user_id' => null,
                    'name' => 'Juan Dela Cruz',
                    'email' => 'juan@lgu.gov.ph',
                    'email_verified_at' => now()->toIso8601String(),
                ],
            ], 200),
        ]);

        $response = $this->withSession([
            'sso_state' => 'valid_state',
            'sso_nonce' => 'expected_nonce',
            'sso_code_verifier' => 'verifier_value',
        ])->get(route('sso.callback', ['code' => 'valid_code', 'state' => 'valid_state']));

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'juan@lgu.gov.ph', 'login_portal_user_id' => 42]);
    }

    public function test_verify_credentials_endpoint_authenticates_local_user_for_portal_binding(): void
    {
        $user = User::factory()->create([
            'email' => 'bind@lgu.gov.ph',
            'password' => Hash::make('MyLocalPass123!'),
        ]);

        $response = $this->postJson('/api/sso/verify-credentials', [
            'username' => 'bind@lgu.gov.ph',
            'password' => 'MyLocalPass123!',
            'client_secret' => config('services.login_portal.client_secret'),
        ]);

        $response->assertOk()->assertJson([
            'valid' => true,
            'user_id' => (string) $user->id,
            'email' => 'bind@lgu.gov.ph',
        ]);
    }
}

# Run verification in [Client Application]:
# php artisan test --compact --filter=SsoIntegrationTest`,
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
                overview: 'Complete execution-order manual guide for integrating a new or existing Laravel + Jetstream + Inertia.js (Vue 3) client application with the central Login Portal SSO Server using OAuth 2.0 Authorization Code + PKCE (S256), CSRF state protection, Connected Systems account binding (/api/sso/verify-credentials), and 4-tier user identity mapping.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, 'Laravel + Jetstream + Inertia (Vue.js)'),
                    buildLaravelEnvAndConfigStep(cfg),
                    buildLaravelDatabaseStep(),
                    buildLaravelBindEndpointStep(),
                    buildLaravelControllerStep(),
                    {
                        stepNumber: 6,
                        title: '[Client Application] [Required] Add SSO Button to Inertia Vue Login Page',
                        description: 'Purpose: Add a full-page browser navigation link (<a :href="route(\'sso.redirect\')">) to your Inertia Login.vue page while keeping the existing email/password form intact. Important: Do NOT use Inertia <Link> or XHR for /sso/redirect because the browser must perform a full top-level navigation to the external Login Portal.',
                        filename: '[Client Application] resources/js/Pages/Auth/Login.vue',
                        language: 'html',
                        code: `<!-- Inside [Client Application] resources/js/Pages/Auth/Login.vue -->
<!-- Place below or above your existing local login <form> -->
<div class="mt-6">
    <div class="relative flex items-center justify-center my-4">
        <div class="border-t border-gray-200 w-full"></div>
        <span class="bg-white px-3 text-xs font-semibold uppercase tracking-wider text-gray-400">Or</span>
        <div class="border-t border-gray-200 w-full"></div>
    </div>

    <!-- IMPORTANT: Use a standard <a> tag (NOT Inertia <Link>) for external OAuth redirect -->
    <a
        :href="route('sso.redirect')"
        class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-emerald-900 hover:bg-emerald-950 text-white text-sm font-semibold rounded-lg shadow-xs transition"
    >
        <span>Sign in with Central Login Portal (SSO)</span>
    </a>
</div>

# Verification ([Client Application] terminal):
# npm run build (or npm run dev) and visit /login to confirm the SSO button renders and redirects to ${cfg.portalUrl}/sso/authorize.`,
                    },
                    buildLaravelLogoutAndDeploymentStep(cfg),
                    buildLaravelAutomatedTestsStep(),
                ],
            };

        case 'laravel_livewire':
            return {
                title: `${clientName} — SSO Integration Guide for Laravel + Jetstream (Livewire/Blade)`,
                framework: meta,
                overview: 'Complete execution-order manual guide for integrating a new or existing Laravel + Jetstream (Livewire / Blade) client application with the central Login Portal SSO Server using OAuth 2.0 Authorization Code + PKCE (S256), CSRF state protection, Connected Systems account binding (/api/sso/verify-credentials), and 4-tier user identity mapping.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, 'Laravel + Jetstream (Livewire/Blade)'),
                    buildLaravelEnvAndConfigStep(cfg),
                    buildLaravelDatabaseStep(),
                    buildLaravelBindEndpointStep(),
                    buildLaravelControllerStep(),
                    {
                        stepNumber: 6,
                        title: '[Client Application] [Required] Add SSO Button to Livewire / Blade Login View',
                        description: 'Purpose: Add a standard <a href="{{ route(\'sso.redirect\') }}"> button (do NOT use wire:navigate) to resources/views/auth/login.blade.php while preserving the existing local credentials form.',
                        filename: '[Client Application] resources/views/auth/login.blade.php',
                        language: 'html',
                        code: `{{-- Inside [Client Application] resources/views/auth/login.blade.php --}}
<div class="mt-6">
    <div class="relative flex items-center justify-center my-4">
        <div class="border-t border-gray-200 w-full"></div>
        <span class="bg-white px-3 text-xs font-semibold uppercase text-gray-400">Or</span>
        <div class="border-t border-gray-200 w-full"></div>
    </div>

    {{-- Do NOT add wire:navigate; external OAuth redirects require full browser navigation --}}
    <a href="{{ route('sso.redirect') }}"
       class="w-full inline-flex items-center justify-center px-4 py-2.5 bg-emerald-900 hover:bg-emerald-950 text-white text-sm font-semibold rounded-lg shadow-xs transition">
        {{ __('Sign in with Central Login Portal (SSO)') }}
    </a>
</div>`,
                    },
                    buildLaravelLogoutAndDeploymentStep(cfg),
                    buildLaravelAutomatedTestsStep(),
                ],
            };

        case 'laravel_blade':
            return {
                title: `${clientName} — SSO Integration Guide for Laravel (Standard MVC / Blade)`,
                framework: meta,
                overview: 'Complete execution-order manual guide for integrating a new or existing Laravel MVC / Blade client application with the central Login Portal SSO Server using OAuth 2.0 Authorization Code + PKCE (S256), CSRF state protection, Connected Systems account binding (/api/sso/verify-credentials), and 4-tier user identity mapping.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, 'Laravel (Standard MVC / Blade)'),
                    buildLaravelEnvAndConfigStep(cfg),
                    buildLaravelDatabaseStep(),
                    buildLaravelBindEndpointStep(),
                    buildLaravelControllerStep(),
                    {
                        stepNumber: 6,
                        title: '[Client Application] [Required] Add SSO Button to Blade Login Template',
                        description: 'Purpose: Add the "Sign in with Central Login Portal (SSO)" button to resources/views/auth/login.blade.php while keeping existing username/password login fields and error alerts.',
                        filename: '[Client Application] resources/views/auth/login.blade.php',
                        language: 'html',
                        code: `{{-- Inside [Client Application] resources/views/auth/login.blade.php --}}
@if ($errors->any())
    <div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-sm text-red-700">
        {{ $errors->first() }}
    </div>
@endif

<div class="mt-6">
    <a href="{{ route('sso.redirect') }}"
       class="w-full inline-flex items-center justify-center px-4 py-2.5 bg-emerald-900 hover:bg-emerald-950 text-white text-sm font-semibold rounded-lg">
        Sign in with Central Login Portal (SSO)
    </a>
</div>`,
                    },
                    buildLaravelLogoutAndDeploymentStep(cfg),
                    buildLaravelAutomatedTestsStep(),
                ],
            };

        case 'php_vanilla':
            return {
                title: `${clientName} — SSO Integration Guide for PHP + Vanilla JS / jQuery`,
                framework: meta,
                overview: 'Complete execution-order manual guide for integrating a native PHP (PDO + cURL + PHP Sessions) application with the central Login Portal SSO Server using OAuth 2.0 Authorization Code + PKCE (S256), Connected Systems account binding (/api/sso/verify-credentials), and 4-tier user identity mapping.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, 'PHP + Vanilla JS / jQuery'),
                    {
                        stepNumber: 2,
                        title: '[Client Application] [Required] Environment Config & Database Schema (New vs. Existing App)',
                        description: 'Purpose: Configure environment variables, session cookie security (SameSite=Lax, HttpOnly), and the users table schema. Do NOT create sso_clients, sso_authorization_codes, or sso_user_bindings on the client database. Prerequisites: PHP ^8.2 with ext-curl, ext-pdo, ext-json, ext-openssl.',
                        filename: '[Client Application] sso_config.php & database_schema.sql',
                        language: 'php',
                        code: `<?php
// File 1: [Client Application] sso_config.php
define('SSO_PORTAL_URL', rtrim(getenv('LOGIN_PORTAL_URL') ?: '${cfg.portalUrl}', '/'));
define('SSO_CLIENT_ID', getenv('LOGIN_PORTAL_CLIENT_ID') ?: '${cfg.clientId}');
define('SSO_CLIENT_SECRET', getenv('LOGIN_PORTAL_CLIENT_SECRET') ?: '${cfg.clientSecret}');
define('SSO_REDIRECT_URI', getenv('LOGIN_PORTAL_REDIRECT_URI') ?: '${cfg.redirectUri}');
define('SSO_AUTO_PROVISION', filter_var(getenv('LOGIN_PORTAL_AUTO_PROVISION') ?: 'true', FILTER_VALIDATE_BOOLEAN));

// Ensure session cookies survive cross-site OAuth redirects:
session_set_cookie_params([
    'lifetime' => 7200,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax',
]);

/*
-- File 2: [Client Application] SQL Schema
-- Option A (New Client App):
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login_portal_user_id BIGINT UNSIGNED NULL UNIQUE,
    sso_provider VARCHAR(64) NULL DEFAULT 'login_portal',
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX users_sso_provider_index (sso_provider)
);

-- Option B (Existing Client App - Non-Destructive):
ALTER TABLE users
    ADD COLUMN login_portal_user_id BIGINT UNSIGNED NULL UNIQUE AFTER id,
    ADD COLUMN sso_provider VARCHAR(64) NULL DEFAULT 'login_portal' AFTER login_portal_user_id,
    ADD INDEX users_sso_provider_index (sso_provider);
*/`,
                    },
                    {
                        stepNumber: 3,
                        title: '[Client Application] [Required] Connected Systems Account Binding Endpoint',
                        description: 'Purpose: Expose POST /api/sso/verify-credentials (or /sso/verify-credentials) so the SSO Server can verify a user\'s local username/email and password when they bind their account in /connected-systems.',
                        filename: '[Client Application] api/sso/verify-credentials.php',
                        language: 'php',
                        code: `<?php
// [Client Application] api/sso/verify-credentials.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../sso_config.php';
require_once __DIR__ . '/../../db.php'; // Provides $pdo (PDO instance)

$providedSecret = (string) ($_POST['client_secret'] ?? '');
if ($providedSecret === '' || !hash_equals(SSO_CLIENT_SECRET, $providedSecret)) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'message' => 'Invalid client secret.']);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

$stmt = $pdo->prepare('SELECT id, name, email, password, status FROM users WHERE email = :u LIMIT 1');
$stmt->execute(['u' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'message' => 'Invalid username or password.']);
    exit;
}

if (in_array($user['status'] ?? 'active', ['banned', 'suspended', 'inactive'], true)) {
    http_response_code(403);
    echo json_encode(['valid' => false, 'message' => 'Local account is suspended.']);
    exit;
}

echo json_encode([
    'valid' => true,
    'user_id' => (string) $user['id'],
    'name' => $user['name'],
    'email' => $user['email'],
]);`,
                    },
                    {
                        stepNumber: 4,
                        title: '[Client Application] [Required] SSO Redirect & Callback Handlers (PKCE + 4-Tier Mapping)',
                        description: 'Purpose: Generate CSRF state, OIDC nonce, and PKCE S256 challenge in sso_redirect.php; verify state, exchange code at POST /api/sso/token, consume response.user directly (do NOT call /api/user), map or provision the local user, and regenerate the PHP session ID in sso_callback.php.',
                        filename: '[Client Application] sso_redirect.php & sso_callback.php',
                        language: 'php',
                        code: `<?php
// === File 1: [Client Application] sso_redirect.php ===
require_once __DIR__ . '/sso_config.php';
session_start();

$state = bin2hex(random_bytes(20));
$nonce = bin2hex(random_bytes(20));
$codeVerifier = bin2hex(random_bytes(32));
$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

$_SESSION['sso_state'] = $state;
$_SESSION['sso_nonce'] = $nonce;
$_SESSION['sso_code_verifier'] = $codeVerifier;

$query = http_build_query([
    'client_id' => SSO_CLIENT_ID,
    'redirect_uri' => SSO_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'openid profile email',
    'state' => $state,
    'code_challenge' => $codeChallenge,
    'code_challenge_method' => 'S256',
    'nonce' => $nonce,
]);

header('Location: ' . SSO_PORTAL_URL . '/sso/authorize?' . $query);
exit;

// === File 2: [Client Application] sso_callback.php ===
require_once __DIR__ . '/sso_config.php';
require_once __DIR__ . '/db.php'; // Provides $pdo
session_start();

$expectedState = (string) ($_SESSION['sso_state'] ?? '');
$expectedNonce = (string) ($_SESSION['sso_nonce'] ?? '');
$codeVerifier = (string) ($_SESSION['sso_code_verifier'] ?? '');
unset($_SESSION['sso_state'], $_SESSION['sso_nonce'], $_SESSION['sso_code_verifier']);

if (!empty($_GET['error']) || $expectedState === '' || !hash_equals($expectedState, (string) ($_GET['state'] ?? ''))) {
    header('Location: /login.php?error=invalid_state');
    exit;
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    header('Location: /login.php?error=missing_code');
    exit;
}

$ch = curl_init(SSO_PORTAL_URL . '/api/sso/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => SSO_CLIENT_ID,
        'client_secret' => SSO_CLIENT_SECRET,
        'redirect_uri' => SSO_REDIRECT_URI,
        'code' => $code,
        'code_verifier' => $codeVerifier,
    ]),
]);
$raw = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode((string) $raw, true);
if ($status !== 200 || empty($data['user']['id']) || empty($data['user']['email'])) {
    header('Location: /login.php?error=sso_exchange_failed');
    exit;
}

if ($expectedNonce !== '' && (!isset($data['nonce']) || !hash_equals($expectedNonce, (string) $data['nonce']))) {
    header('Location: /login.php?error=nonce_mismatch');
    exit;
}

$pUser = $data['user'];
$portalId = (int) $pUser['id'];
$boundId = $pUser['bound_user_id'] ?? null;

// 4-tier lookup: bound_user_id -> login_portal_user_id -> email -> auto-provision
$user = null;
if (!empty($boundId)) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $boundId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$user) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE login_portal_user_id = :pid LIMIT 1');
    $stmt->execute(['pid' => $portalId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$user) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $pUser['email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($user) {
    $pdo->prepare('UPDATE users SET login_portal_user_id = :pid, sso_provider = "login_portal" WHERE id = :id')
        ->execute(['pid' => $portalId, 'id' => $user['id']]);
} elseif (SSO_AUTO_PROVISION) {
    $hash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users (login_portal_user_id, sso_provider, name, email, password) VALUES (:pid, "login_portal", :name, :email, :pw)')
        ->execute(['pid' => $portalId, 'name' => $pUser['name'] ?? $pUser['email'], 'email' => $pUser['email'], 'pw' => $hash]);
    $user = ['id' => $pdo->lastInsertId(), 'name' => $pUser['name'], 'email' => $pUser['email'], 'status' => 'active'];
} else {
    header('Location: /login.php?error=not_authorized');
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
header('Location: /dashboard.php');
exit;`,
                    },
                    {
                        stepNumber: 5,
                        title: '[Client Application] [Required] Login Button, Logout Scope & Verification',
                        description: 'Purpose: Add the SSO button to login.php while preserving local login, implement local session logout in logout.php, and verify the end-to-end flow.',
                        filename: '[Client Application] login.php & logout.php',
                        language: 'html',
                        code: `<!-- In [Client Application] login.php: -->
<a href="/sso_redirect.php" class="btn-sso">Sign in with Central Login Portal (SSO)</a>

<?php
// In [Client Application] logout.php (Local Logout only; does not terminate central Portal session):
session_start();
$_SESSION = [];
session_destroy();
header('Location: /login.php');
exit;`,
                    },
                ],
            };

        case 'node_express':
            return {
                title: `${clientName} — SSO Integration Guide for Node.js / Express`,
                framework: meta,
                overview: 'Complete execution-order manual guide for integrating a Node.js + Express client application with the central Login Portal SSO Server using OAuth 2.0 Authorization Code + PKCE (S256), Connected Systems account binding (/api/sso/verify-credentials), and 4-tier user identity mapping.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, 'Node.js / Express'),
                    {
                        stepNumber: 2,
                        title: '[Client Application] [Required] Environment Variables, Dependencies & Database Schema',
                        description: 'Purpose: Install required dependencies (express-session, axios, dotenv, bcrypt), configure .env and session cookies (sameSite: "lax", httpOnly: true), and add login_portal_user_id (nullable, unique) and sso_provider to your users table (new or existing).',
                        filename: '[Client Application] .env & database migration',
                        language: 'bash',
                        code: `# Run in [Client Application]:
npm install express-session axios dotenv bcrypt

# In [Client Application] .env:
LOGIN_PORTAL_URL=${cfg.portalUrl}
LOGIN_PORTAL_CLIENT_ID=${cfg.clientId}
LOGIN_PORTAL_CLIENT_SECRET=${cfg.clientSecret}
LOGIN_PORTAL_REDIRECT_URI=${cfg.redirectUri}
LOGIN_PORTAL_AUTO_PROVISION=true
SESSION_SECRET=replace_with_64_char_random_secret

# Database Schema (SQL / Knex / Prisma):
# Ensure 'users' table has:
# - login_portal_user_id BIGINT UNSIGNED NULL UNIQUE
# - sso_provider VARCHAR(64) NULL DEFAULT 'login_portal'
# Do NOT create sso_clients, sso_authorization_codes, or sso_user_bindings on the client.`,
                    },
                    {
                        stepNumber: 3,
                        title: '[Client Application] [Required] Express SSO Routes (Binding, Redirect & Callback)',
                        description: 'Purpose: Implement POST /api/sso/verify-credentials for Connected Systems binding, GET /sso/redirect (with state + PKCE S256 + nonce), and GET /sso/callback (exchanging the code via POST /api/sso/token, reading response.data.user directly without calling /api/user, 4-tier user resolution, and req.session.regenerate()).',
                        filename: '[Client Application] routes/sso.js',
                        language: 'javascript',
                        code: `// [Client Application] routes/sso.js
const express = require('express');
const crypto = require('crypto');
const axios = require('axios');
const bcrypt = require('bcrypt');
const db = require('../db'); // Your database / ORM user repository
const router = express.Router();

const PORTAL_URL = (process.env.LOGIN_PORTAL_URL || '${cfg.portalUrl}').replace(/\\/$/, '');
const CLIENT_ID = process.env.LOGIN_PORTAL_CLIENT_ID;
const CLIENT_SECRET = process.env.LOGIN_PORTAL_CLIENT_SECRET;
const REDIRECT_URI = process.env.LOGIN_PORTAL_REDIRECT_URI;
const AUTO_PROVISION = process.env.LOGIN_PORTAL_AUTO_PROVISION !== 'false';

// 1. Connected Systems Account Binding Endpoint
router.post('/api/sso/verify-credentials', express.urlencoded({ extended: false }), express.json(), async (req, res) => {
    const providedSecret = String(req.body.client_secret || '');
    if (!CLIENT_SECRET || providedSecret.length !== CLIENT_SECRET.length ||
        !crypto.timingSafeEqual(Buffer.from(providedSecret), Buffer.from(CLIENT_SECRET))) {
        return res.status(401).json({ valid: false, message: 'Invalid client secret.' });
    }
    const user = await db.findUserByEmailOrUsername(String(req.body.username || ''));
    if (!user || !(await bcrypt.compare(String(req.body.password || ''), user.password))) {
        return res.status(401).json({ valid: false, message: 'Invalid credentials.' });
    }
    return res.json({ valid: true, user_id: String(user.id), name: user.name, email: user.email });
});

// 2. Initiate SSO Redirect with CSRF state, PKCE S256 & nonce
router.get('/sso/redirect', (req, res) => {
    const state = crypto.randomBytes(20).toString('hex');
    const nonce = crypto.randomBytes(20).toString('hex');
    const codeVerifier = crypto.randomBytes(32).toString('hex');
    const codeChallenge = crypto.createHash('sha256').update(codeVerifier).digest('base64url');

    req.session.ssoState = state;
    req.session.ssoNonce = nonce;
    req.session.ssoCodeVerifier = codeVerifier;

    const params = new URLSearchParams({
        client_id: CLIENT_ID,
        redirect_uri: REDIRECT_URI,
        response_type: 'code',
        scope: 'openid profile email',
        state,
        code_challenge: codeChallenge,
        code_challenge_method: 'S256',
        nonce,
    });
    req.session.save(() => res.redirect(\`\${PORTAL_URL}/sso/authorize?\${params.toString()}\`));
});

// 3. Handle SSO Callback & Backchannel Token Exchange
router.get('/sso/callback', async (req, res) => {
    const expectedState = req.session.ssoState;
    const expectedNonce = req.session.ssoNonce;
    const codeVerifier = req.session.ssoCodeVerifier;
    delete req.session.ssoState;
    delete req.session.ssoNonce;
    delete req.session.ssoCodeVerifier;

    if (req.query.error || !expectedState || req.query.state !== expectedState || !req.query.code) {
        return res.redirect('/login?error=invalid_sso_callback');
    }

    try {
        const body = new URLSearchParams({
            grant_type: 'authorization_code',
            client_id: CLIENT_ID,
            client_secret: CLIENT_SECRET,
            redirect_uri: REDIRECT_URI,
            code: String(req.query.code),
            code_verifier: String(codeVerifier || ''),
        });
        const { data } = await axios.post(\`\${PORTAL_URL}/api/sso/token\`, body.toString(), {
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
            timeout: 15000,
        });

        if (expectedNonce && data.nonce !== expectedNonce) {
            return res.redirect('/login?error=nonce_mismatch');
        }

        const pUser = data.user; // Consume user directly from /api/sso/token response
        const user = await db.resolveOrProvisionSsoUser(pUser, { autoProvision: AUTO_PROVISION });
        if (!user) return res.redirect('/login?error=not_authorized');

        req.session.regenerate((err) => {
            if (err) return res.redirect('/login?error=session_error');
            req.session.userId = user.id;
            req.session.save(() => res.redirect('/dashboard'));
        });
    } catch (err) {
        return res.redirect('/login?error=token_exchange_failed');
    }
});

module.exports = router;`,
                    },
                    {
                        stepNumber: 4,
                        title: '[Client Application] [Required] Login View, Logout & Automated Tests (Jest + Supertest)',
                        description: 'Purpose: Add the SSO button to your login template, implement local session logout, and test the integration with mocked axios calls.',
                        filename: '[Client Application] tests/sso.test.js',
                        language: 'javascript',
                        code: `// In [Client Application] tests/sso.test.js:
// Mock axios.post to return { data: { access_token: '...', nonce: '...', user: { id: 42, email: 'user@lgu.gov.ph', name: 'Test User' } } }
// Verify GET /sso/redirect sets ssoState + code_challenge_method=S256,
// GET /sso/callback rejects state mismatch and authenticates valid code,
// and POST /api/sso/verify-credentials validates client_secret and local user credentials.`,
                    },
                ],
            };

        case 'python_django':
            return {
                title: `${clientName} — SSO Integration Guide for Python / Django`,
                framework: meta,
                overview: 'Complete execution-order manual guide for integrating a Python + Django client application with the central Login Portal SSO Server using OAuth 2.0 Authorization Code + PKCE (S256), Connected Systems account binding (/api/sso/verify-credentials), and 4-tier user identity mapping.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, 'Python / Django'),
                    {
                        stepNumber: 2,
                        title: '[Client Application] [Required] Django Settings & User Model Migration',
                        description: 'Purpose: Configure LOGIN_PORTAL_* settings and SESSION_COOKIE_SAMESITE = "Lax" in settings.py, and add login_portal_user_id (BigIntegerField, null=True, unique=True) and sso_provider to your User/Profile model.',
                        filename: '[Client Application] settings.py & accounts/models.py',
                        language: 'python',
                        code: `# In [Client Application] settings.py:
import os
LOGIN_PORTAL_URL = os.environ.get('LOGIN_PORTAL_URL', '${cfg.portalUrl}').rstrip('/')
LOGIN_PORTAL_CLIENT_ID = os.environ.get('LOGIN_PORTAL_CLIENT_ID', '${cfg.clientId}')
LOGIN_PORTAL_CLIENT_SECRET = os.environ.get('LOGIN_PORTAL_CLIENT_SECRET', '${cfg.clientSecret}')
LOGIN_PORTAL_REDIRECT_URI = os.environ.get('LOGIN_PORTAL_REDIRECT_URI', '${cfg.redirectUri}')
LOGIN_PORTAL_AUTO_PROVISION = os.environ.get('LOGIN_PORTAL_AUTO_PROVISION', 'true').lower() == 'true'

SESSION_COOKIE_SAMESITE = 'Lax'
SESSION_COOKIE_HTTPONLY = True

# In [Client Application] accounts/models.py:
# Add login_portal_user_id = models.BigIntegerField(null=True, blank=True, unique=True)
# Add sso_provider = models.CharField(max_length=64, null=True, blank=True, default='login_portal')
# Run: python manage.py makemigrations && python manage.py migrate`,
                    },
                    {
                        stepNumber: 3,
                        title: '[Client Application] [Required] Django Views & URLs (Binding, Redirect & Callback)',
                        description: 'Purpose: Implement POST /api/sso/verify-credentials (CSRF-exempt backchannel verification for Connected Systems), GET /sso/redirect (state + PKCE S256 + nonce), and GET /sso/callback (exchanging code at /api/sso/token, reading user from token payload, 4-tier mapping, and request.session.cycle_key()).',
                        filename: '[Client Application] accounts/views.py & urls.py',
                        language: 'python',
                        code: `# [Client Application] accounts/views.py
import base64, hashlib, hmac, secrets, urllib.parse, requests
from django.conf import settings
from django.contrib import messages
from django.contrib.auth import authenticate, get_user_model, login
from django.http import JsonResponse
from django.shortcuts import redirect
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_POST

User = get_user_model()

@csrf_exempt
@require_POST
def sso_verify_credentials(request):
    provided = request.POST.get('client_secret', '')
    expected = settings.LOGIN_PORTAL_CLIENT_SECRET or ''
    if not expected or not hmac.compare_digest(provided, expected):
        return JsonResponse({'valid': False, 'message': 'Invalid client secret.'}, status=401)

    username = request.POST.get('username', '')
    password = request.POST.get('password', '')
    user = User.objects.filter(email=username).first() or User.objects.filter(username=username).first()
    if not user or not user.check_password(password):
        return JsonResponse({'valid': False, 'message': 'Invalid credentials.'}, status=401)
    if not user.is_active:
        return JsonResponse({'valid': False, 'message': 'Account inactive.'}, status=403)

    return JsonResponse({'valid': True, 'user_id': str(user.pk), 'name': user.get_full_name() or user.username, 'email': user.email})

def sso_redirect(request):
    state = secrets.token_hex(20)
    nonce = secrets.token_hex(20)
    verifier = secrets.token_urlsafe(48)
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b'=').decode()

    request.session['sso_state'] = state
    request.session['sso_nonce'] = nonce
    request.session['sso_code_verifier'] = verifier

    query = urllib.parse.urlencode({
        'client_id': settings.LOGIN_PORTAL_CLIENT_ID,
        'redirect_uri': settings.LOGIN_PORTAL_REDIRECT_URI,
        'response_type': 'code',
        'scope': 'openid profile email',
        'state': state,
        'code_challenge': challenge,
        'code_challenge_method': 'S256',
        'nonce': nonce,
    })
    return redirect(f"{settings.LOGIN_PORTAL_URL}/sso/authorize?{query}")

def sso_callback(request):
    expected_state = request.session.pop('sso_state', '')
    expected_nonce = request.session.pop('sso_nonce', '')
    verifier = request.session.pop('sso_code_verifier', '')
    returned_state = request.GET.get('state', '')
    code = request.GET.get('code', '')

    if request.GET.get('error') or not expected_state or not hmac.compare_digest(expected_state, returned_state) or not code:
        messages.error(request, 'Invalid or expired SSO callback state.')
        return redirect('login')

    resp = requests.post(f"{settings.LOGIN_PORTAL_URL}/api/sso/token", data={
        'grant_type': 'authorization_code',
        'client_id': settings.LOGIN_PORTAL_CLIENT_ID,
        'client_secret': settings.LOGIN_PORTAL_CLIENT_SECRET,
        'redirect_uri': settings.LOGIN_PORTAL_REDIRECT_URI,
        'code': code,
        'code_verifier': verifier,
    }, timeout=15)

    if resp.status_code != 200:
        messages.error(request, 'SSO token exchange failed.')
        return redirect('login')

    payload = resp.json()
    if expected_nonce and not hmac.compare_digest(expected_nonce, str(payload.get('nonce', ''))):
        messages.error(request, 'SSO nonce mismatch.')
        return redirect('login')

    p_user = payload.get('user') or {}
    portal_id = int(p_user['id'])
    bound_id = p_user.get('bound_user_id')

    user = (User.objects.filter(pk=bound_id).first() if bound_id else None) \\
        or User.objects.filter(login_portal_user_id=portal_id).first() \\
        or User.objects.filter(email=p_user['email']).first()

    if user:
        user.login_portal_user_id = portal_id
        user.sso_provider = 'login_portal'
        user.save(update_fields=['login_portal_user_id', 'sso_provider'])
    elif settings.LOGIN_PORTAL_AUTO_PROVISION:
        user = User.objects.create_user(
            username=f"sso_{portal_id}_{secrets.token_hex(3)}",
            email=p_user['email'],
            password=secrets.token_urlsafe(32),
            login_portal_user_id=portal_id,
            sso_provider='login_portal',
        )
    else:
        messages.error(request, 'Your account is not authorized for this application.')
        return redirect('login')

    user.backend = 'django.contrib.auth.backends.ModelBackend'
    login(request, user)
    request.session.cycle_key()
    return redirect('/dashboard/')`,
                    },
                    {
                        stepNumber: 4,
                        title: '[Client Application] [Required] URLs, Login Template & Unit Tests',
                        description: 'Purpose: Wire /sso/redirect, /sso/callback, and /api/sso/verify-credentials in urls.py, add the SSO button to login.html, and run mocked tests with python manage.py test.',
                        filename: '[Client Application] urls.py & tests.py',
                        language: 'python',
                        code: `# In [Client Application] urls.py:
# path('sso/redirect', views.sso_redirect, name='sso_redirect'),
# path('sso/callback', views.sso_callback, name='sso_callback'),
# path('api/sso/verify-credentials', views.sso_verify_credentials, name='sso_verify_credentials'),`,
                    },
                ],
            };

        case 'dotnet_core':
            return {
                title: `${clientName} — SSO Integration Guide for ASP.NET Core (C#)`,
                framework: meta,
                overview: 'Complete execution-order manual guide for integrating an ASP.NET Core (C#) client application with the central Login Portal SSO Server using OAuth 2.0 Authorization Code + PKCE (S256), Connected Systems account binding (/api/sso/verify-credentials), and 4-tier user identity mapping.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, 'ASP.NET Core (C#)'),
                    {
                        stepNumber: 2,
                        title: '[Client Application] [Required] appsettings.json, Program.cs & EF Core Schema',
                        description: 'Purpose: Configure LoginPortal credentials in appsettings.json / environment variables, enable Session + Cookie Authentication (SameSiteMode.Lax) in Program.cs, and add LoginPortalUserId (long?) and SsoProvider (string?) to ApplicationUser.',
                        filename: '[Client Application] appsettings.json & Program.cs',
                        language: 'csharp',
                        code: `// 1. In [Client Application] appsettings.json:
"LoginPortal": {
  "Url": "${cfg.portalUrl}",
  "ClientId": "${cfg.clientId}",
  "ClientSecret": "${cfg.clientSecret}",
  "RedirectUri": "${cfg.redirectUri}",
  "AutoProvision": true
}

// 2. In [Client Application] Program.cs:
builder.Services.AddHttpClient();
builder.Services.AddDistributedMemoryCache();
builder.Services.AddSession(o => {
    o.Cookie.HttpOnly = true;
    o.Cookie.SameSite = SameSiteMode.Lax;
    o.IdleTimeout = TimeSpan.FromMinutes(30);
});
// Ensure app.UseSession() is placed BEFORE app.UseAuthentication() and app.UseAuthorization().
//
// 3. EF Core Migration ([Client Application]):
// Add public long? LoginPortalUserId { get; set; } and public string? SsoProvider { get; set; } to ApplicationUser.
// Run: dotnet ef migrations add AddSsoColumnsToUsers && dotnet ef database update`,
                    },
                    {
                        stepNumber: 3,
                        title: '[Client Application] [Required] SsoController (VerifyCredentials, Redirect & Callback)',
                        description: 'Purpose: Implement POST /api/sso/verify-credentials for Connected Systems binding, GET /sso/redirect with CSRF state + PKCE S256 + nonce, and GET /sso/callback exchanging the code at /api/sso/token and reading response.User directly.',
                        filename: '[Client Application] Controllers/SsoController.cs',
                        language: 'csharp',
                        code: `// [Client Application] Controllers/SsoController.cs
// 1. [HttpPost("/api/sso/verify-credentials")] [IgnoreAntiforgeryToken]
//    Validate Request.Form["client_secret"] using CryptographicOperations.FixedTimeEquals.
//    Verify Request.Form["username"] and Request.Form["password"] against local user store.
//    Return Ok(new { valid = true, user_id = user.Id.ToString(), name = user.Name, email = user.Email });
//
// 2. [HttpGet("/sso/redirect")]
//    Generate state, nonce, and PKCE S256 code_verifier + code_challenge (SHA256 Base64Url).
//    Save to HttpContext.Session and Redirect to {Url}/sso/authorize?...
//
// 3. [HttpGet("/sso/callback")]
//    Validate state against HttpContext.Session, POST FormUrlEncodedContent to {Url}/api/sso/token
//    with grant_type=authorization_code, client_id, client_secret, redirect_uri, code, code_verifier.
//    Verify nonce, resolve local user (bound_user_id -> LoginPortalUserId -> Email -> AutoProvision),
//    and call await HttpContext.SignInAsync(CookieAuthenticationDefaults.AuthenticationScheme, principal).`,
                    },
                ],
            };

        case 'spring_boot':
            return {
                title: `${clientName} — SSO Integration Guide for Spring Boot (Java)`,
                framework: meta,
                overview: 'Complete execution-order manual guide for integrating a Spring Boot 3 + Spring Security + JPA client application with the central Login Portal SSO Server using OAuth 2.0 Authorization Code + PKCE (S256), Connected Systems account binding (/api/sso/verify-credentials), and 4-tier user identity mapping.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, 'Spring Boot (Java)'),
                    {
                        stepNumber: 2,
                        title: '[Client Application] [Required] application.yml, SecurityConfig & User Entity',
                        description: 'Purpose: Configure loginportal properties in application.yml, permit /sso/** and /api/sso/verify-credentials in SecurityFilterChain (ignoring CSRF on /api/sso/verify-credentials), and add loginPortalUserId (Long, unique) and ssoProvider to your User JPA entity.',
                        filename: '[Client Application] src/main/resources/application.yml & SecurityConfig.java',
                        language: 'yaml',
                        code: `# [Client Application] src/main/resources/application.yml
loginportal:
  url: \${LOGIN_PORTAL_URL:${cfg.portalUrl}}
  client-id: \${LOGIN_PORTAL_CLIENT_ID:${cfg.clientId}}
  client-secret: \${LOGIN_PORTAL_CLIENT_SECRET:${cfg.clientSecret}}
  redirect-uri: \${LOGIN_PORTAL_REDIRECT_URI:${cfg.redirectUri}}
  auto-provision: \${LOGIN_PORTAL_AUTO_PROVISION:true}

server:
  servlet:
    session:
      cookie:
        same-site: lax
        http-only: true

# In SecurityConfig.java:
# .csrf(csrf -> csrf.ignoringRequestMatchers("/api/sso/verify-credentials"))
# .authorizeHttpRequests(auth -> auth.requestMatchers("/login", "/sso/**", "/api/sso/verify-credentials").permitAll().anyRequest().authenticated())`,
                    },
                    {
                        stepNumber: 3,
                        title: '[Client Application] [Required] SsoController (VerifyCredentials, Redirect & Callback)',
                        description: 'Purpose: Implement POST /api/sso/verify-credentials for Connected Systems binding, GET /sso/redirect (state + PKCE S256 + nonce), and GET /sso/callback (exchanging code at /api/sso/token, reading user from token payload, 4-tier mapping, and request.changeSessionId()).',
                        filename: '[Client Application] src/main/java/com/example/app/controller/SsoController.java',
                        language: 'java',
                        code: `// [Client Application] SsoController.java
// 1. @PostMapping("/api/sso/verify-credentials")
//    Verify client_secret with MessageDigest.isEqual, check username/password with PasswordEncoder,
//    and return Map.of("valid", true, "user_id", String.valueOf(user.getId()), "name", user.getName(), "email", user.getEmail()).
//
// 2. @GetMapping("/sso/redirect")
//    Generate state, nonce, and PKCE S256 code_verifier + code_challenge, store in HttpSession,
//    and redirect to {portalUrl}/sso/authorize.
//
// 3. @GetMapping("/sso/callback")
//    Verify state from HttpSession, exchange code at POST {portalUrl}/api/sso/token with code_verifier,
//    consume response.get("user") directly (do NOT call /api/user), resolve/provision local User,
//    set SecurityContextHolder authentication, and call request.changeSessionId().`,
                    },
                ],
            };

        case 'nuxt_node':
            return {
                title: `${clientName} — SSO Integration Guide for Nuxt.js / Node.js`,
                framework: meta,
                overview: 'Complete execution-order manual guide for integrating a Nuxt 3/4 + Nitro server client application with the central Login Portal SSO Server using OAuth 2.0 Authorization Code + PKCE (S256), Connected Systems account binding (/api/sso/verify-credentials), and 4-tier user identity mapping.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, 'Nuxt.js / Node.js'),
                    {
                        stepNumber: 2,
                        title: '[Client Application] [Required] Environment Variables & nuxt.config.ts RuntimeConfig',
                        description: 'Purpose: Store clientSecret in server-only runtimeConfig (never in public runtimeConfig) so it is never bundled into browser JavaScript.',
                        filename: '[Client Application] .env & nuxt.config.ts',
                        language: 'typescript',
                        code: `// [Client Application] nuxt.config.ts
export default defineNuxtConfig({
    runtimeConfig: {
        loginPortalClientSecret: process.env.LOGIN_PORTAL_CLIENT_SECRET || '${cfg.clientSecret}',
        public: {
            loginPortalUrl: process.env.LOGIN_PORTAL_URL || '${cfg.portalUrl}',
            loginPortalClientId: process.env.LOGIN_PORTAL_CLIENT_ID || '${cfg.clientId}',
            loginPortalRedirectUri: process.env.LOGIN_PORTAL_REDIRECT_URI || '${cfg.redirectUri}',
        },
    },
});`,
                    },
                    {
                        stepNumber: 3,
                        title: '[Client Application] [Required] Nitro Server Routes (Binding, Redirect & Callback)',
                        description: 'Purpose: Implement server/api/sso/verify-credentials.post.ts for Connected Systems binding, server/routes/sso/redirect.get.ts (setting httpOnly, sameSite: "lax" cookies for state, nonce, and PKCE code_verifier), and server/routes/sso/callback.get.ts (exchanging code at /api/sso/token and reading response.user directly).',
                        filename: '[Client Application] server/routes/sso/redirect.get.ts & callback.get.ts',
                        language: 'typescript',
                        code: `// 1. [Client Application] server/api/sso/verify-credentials.post.ts:
//    Read body (readBody(event)), verify body.client_secret against runtimeConfig.loginPortalClientSecret,
//    validate username/password against local DB, and return { valid: true, user_id, name, email }.
//
// 2. [Client Application] server/routes/sso/redirect.get.ts:
//    Generate state, nonce, and PKCE S256 verifier/challenge (node:crypto).
//    Store in httpOnly cookies (sameSite: 'lax', maxAge: 300) and sendRedirect() to /sso/authorize.
//
// 3. [Client Application] server/routes/sso/callback.get.ts:
//    Validate state cookie against query.state, delete temporary SSO cookies,
//    POST to {loginPortalUrl}/api/sso/token with grant_type=authorization_code & code_verifier,
//    read response.user directly (do NOT call /api/user), map/provision local user, and set session cookie.`,
                    },
                ],
            };

        case 'vue_spa':
            return {
                title: `${clientName} — SSO Integration Guide for Vue.js / React (SPA with Backend Proxy)`,
                framework: meta,
                overview: 'Complete execution-order manual guide for integrating a Vue.js / React Single Page Application (SPA) with a Backend API Proxy using OAuth 2.0 Authorization Code + PKCE (S256), Connected Systems account binding (/api/sso/verify-credentials), and 4-tier user identity mapping.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, 'Vue.js / React (SPA with Backend Proxy)'),
                    {
                        stepNumber: 2,
                        title: '[Client Application] [Required] Security Architecture: SPA Frontend + Backend Proxy',
                        description: 'Purpose: Never expose client_secret in Vite/Webpack frontend environment variables (VITE_* / REACT_APP_*). The SPA or backend initiates /sso/authorize with PKCE S256, and your Backend API Proxy performs the server-to-server POST /api/sso/token exchange and exposes POST /api/sso/verify-credentials.',
                        filename: '[Client Application] SPA + Backend API Proxy',
                        language: 'javascript',
                        code: `// 1. [Client Application] Backend API Proxy Endpoints:
//    - POST /api/sso/verify-credentials : Validates client_secret + username + password for Connected Systems binding.
//    - POST /api/auth/sso/exchange      : Receives { code, code_verifier, nonce } from the SPA callback page,
//      calls POST ${cfg.portalUrl}/api/sso/token with client_secret on the server side,
//      reads response.user directly (do NOT call /api/user), provisions/links the local user,
//      and issues a local HttpOnly session cookie or Sanctum/JWT token to the SPA.
//
// 2. [Client Application] SPA Callback Component (/sso/callback):
//    - Verify query 'state' against sessionStorage.getItem('sso_state') and remove it immediately.
//    - POST { code, code_verifier: sessionStorage.getItem('sso_code_verifier'), nonce: sessionStorage.getItem('sso_nonce') }
//      to your Backend API Proxy (/api/auth/sso/exchange).`,
                    },
                ],
            };

        case 'generic_rest':
        default:
            return {
                title: `${clientName} — SSO Integration Specification for ${meta.name}`,
                framework: meta,
                overview: 'Platform-agnostic OAuth 2.0 Authorization Code + PKCE (S256) specification synchronized with the central Login Portal SSO Server implementation, including Connected Systems account binding, token exchange, 4-tier identity mapping, and session security.',
                steps: [
                    buildServerRegistrationStep(clientName, cfg, meta.name),
                    {
                        stepNumber: 2,
                        title: '[Client Application] [Required] Environment Variables & Minimum Client Database Schema',
                        description: 'Purpose: Configure environment variables and add login_portal_user_id (nullable, unique) and sso_provider to your users table. The client database only needs users and sessions; do NOT create sso_clients, sso_authorization_codes, sso_user_bindings, or ssos on the client.',
                        filename: '[Client Application] .env & SQL Schema',
                        language: 'bash',
                        code: `LOGIN_PORTAL_URL=${cfg.portalUrl}
LOGIN_PORTAL_CLIENT_ID=${cfg.clientId}
LOGIN_PORTAL_CLIENT_SECRET=${cfg.clientSecret}
LOGIN_PORTAL_REDIRECT_URI=${cfg.redirectUri}
LOGIN_PORTAL_AUTO_PROVISION=true

# Minimum Client Database Columns on 'users' table:
# - login_portal_user_id BIGINT UNSIGNED NULL UNIQUE
# - sso_provider VARCHAR(64) NULL DEFAULT 'login_portal'`,
                    },
                    {
                        stepNumber: 3,
                        title: '[Client Application] [Required] Protocol Endpoints: Binding, Authorize & Token Exchange',
                        description: 'Purpose: Implement POST /api/sso/verify-credentials on your client app for Connected Systems account binding, redirect users to GET /sso/authorize with state + PKCE S256 + nonce, and exchange the 2-minute single-use code at POST /api/sso/token. Consume response.user directly from /api/sso/token; do NOT call /api/user.',
                        filename: '[Client Application] HTTP Protocol Specification',
                        language: 'http',
                        code: `### 1. Client Account Binding Endpoint (Called by SSO Server during /connected-systems bind)
POST /api/sso/verify-credentials HTTP/1.1
Content-Type: application/x-www-form-urlencoded

username=user@lgu.gov.ph&password=LocalPassword123&client_secret=${cfg.clientSecret}

# Expected Client 200 OK JSON Response:
# {"valid":true,"user_id":"15","name":"Juan Dela Cruz","email":"user@lgu.gov.ph"}

### 2. Authorization Redirect (Browser GET to SSO Server)
GET ${cfg.portalUrl}/sso/authorize?client_id=${cfg.clientId}&redirect_uri=${encodeURIComponent(cfg.redirectUri)}&response_type=code&scope=openid+profile+email&state={STATE}&code_challenge={S256_CHALLENGE}&code_challenge_method=S256&nonce={NONCE}

### 3. Backchannel Token & Identity Exchange (Client Server POST to SSO Server)
POST ${cfg.portalUrl}/api/sso/token HTTP/1.1
Content-Type: application/x-www-form-urlencoded
Accept: application/json

grant_type=authorization_code&client_id=${cfg.clientId}&client_secret=${cfg.clientSecret}&redirect_uri=${encodeURIComponent(cfg.redirectUri)}&code={CODE}&code_verifier={CODE_VERIFIER}

# Expected 200 OK JSON Response (Consume 'user' directly; do NOT call /api/user):
# {
#   "access_token": "80_char_ephemeral_string",
#   "token_type": "Bearer",
#   "expires_in": 3600,
#   "nonce": "{NONCE}",
#   "user": {
#     "id": 42,
#     "bound_user_id": "15",
#     "bound_username": "user@lgu.gov.ph",
#     "name": "Juan Dela Cruz",
#     "email": "user@lgu.gov.ph",
#     "email_verified_at": "2026-09-14T08:00:00.000000Z",
#     "profile_photo_url": "..."
#   }
# }`,
                    },
                ],
            };
    }
}

/**
 * Generate stack-specific and universal troubleshooting tips for SSO integrations.
 */
export function getTroubleshootingTips(frameworkKey, {
    clientId = 'your_client_id',
    redirectUri = 'https://your-app.example/sso/callback',
    portalUrl = 'https://your-login-portal.example',
} = {}) {
    const cfg = resolveConfigValues({ clientId, clientSecret: 'your_client_secret', redirectUri, portalUrl });

    const universalTips = [
        {
            id: 'do_not_call_api_user',
            title: '401 Unauthorized When Calling /api/user or /api/sso/userinfo',
            category: 'Identity Payload',
            badge: 'HTTP 401',
            badgeColor: 'bg-red-100 text-red-800 border-red-200',
            problem: 'Token exchange at POST /api/sso/token succeeds (200 OK), but a follow-up GET /api/user or GET /api/sso/userinfo with Bearer {access_token} returns 401 Unauthorized.',
            cause: 'SsoService::exchangeToken() returns an ephemeral 80-character access_token alongside the complete user object in the JSON response without persisting the token in Sanctum personal_access_tokens.',
            solution: 'Do NOT make a second HTTP request to /api/user or /api/sso/userinfo. Read the authenticated user profile directly from response["user"] returned by POST /api/sso/token.',
            codeSnippet: `// Read user directly from the POST /api/sso/token response:
$portalUser = $tokenResponse->json('user');
// Keys: id, bound_user_id, bound_username, name, email, email_verified_at, profile_photo_url`,
        },
        {
            id: 'account_binding_required',
            title: 'User Redirected to /connected-systems Instead of Client Callback',
            category: 'Account Binding',
            badge: 'HTTP 302',
            badgeColor: 'bg-amber-100 text-amber-800 border-amber-200',
            problem: 'During GET /sso/authorize or GET /sso/launch/{clientName}, the browser is redirected to /connected-systems on the Login Portal with "Please bind your {ClientName} account first to use Single Sign-On."',
            cause: 'SsoProviderController::authorize requires an active SsoUserBinding record linking the Portal user to your client application before issuing an authorization code. If POST /api/sso/verify-credentials is missing or unreachable on your client app, binding fails.',
            solution: 'Ensure your client application exposes POST /api/sso/verify-credentials (accepting username, password, client_secret as application/x-www-form-urlencoded) and that API Base URL is configured on the SSO Server.',
            codeSnippet: `// Expected 200 OK JSON from client POST /api/sso/verify-credentials:
{
    "valid": true,
    "user_id": "15",
    "name": "Juan Dela Cruz",
    "email": "juan@lgu.gov.ph"
}`,
        },
        {
            id: 'redirect_uri_mismatch',
            title: 'Invalid Redirect URI Error (invalid_redirect_uri)',
            category: 'Configuration',
            badge: 'HTTP 400',
            badgeColor: 'bg-red-100 text-red-800 border-red-200',
            problem: 'GET /sso/authorize or POST /api/sso/token returns {"error":"invalid_redirect_uri","error_description":"The redirect_uri does not match the registered URI."}',
            cause: 'SsoClient::validateRedirectUri() enforces an exact character-for-character match (after rtrim("/")) against the comma-separated URIs registered in sso_clients.redirect_uri.',
            solution: 'Ensure LOGIN_PORTAL_REDIRECT_URI in your client matches the registered Redirect URI in /admin/sso (including http vs https, localhost vs 127.0.0.1, port number, and /sso/callback path).',
            codeSnippet: `LOGIN_PORTAL_REDIRECT_URI=${cfg.redirectUri}
# Multiple allowed URIs can be registered on the SSO Server separated by commas:
# https://app.example/sso/callback, http://localhost:8001/sso/callback`,
        },
        {
            id: 'invalid_grant_expired_code',
            title: 'Authorization Code Expired, Replayed, or PKCE Failed (invalid_grant)',
            category: 'Token Exchange',
            badge: 'HTTP 400',
            badgeColor: 'bg-amber-100 text-amber-800 border-amber-200',
            problem: 'POST /api/sso/token returns 400 Bad Request with {"error":"invalid_grant"}.',
            cause: 'Authorization codes expire after 2 minutes (SsoAuthorizationCode::EXPIRE_MINUTES = 2), can only be exchanged once (used = true), must match the same redirect_uri, and if code_challenge was sent, must pass PKCE code_verifier verification.',
            solution: 'Never exchange the same code twice, ensure server clocks are NTP-synchronized, and pass the exact code_verifier stored in the user session during /sso/redirect.',
            codeSnippet: `$payload = [
    'grant_type' => 'authorization_code',
    'client_id' => config('services.login_portal.client_id'),
    'client_secret' => config('services.login_portal.client_secret'),
    'redirect_uri' => config('services.login_portal.redirect_uri'),
    'code' => $request->query('code'),
    'code_verifier' => $codeVerifier,
];`,
        },
        {
            id: 'state_mismatch_cookie',
            title: 'Invalid or Expired SSO Session State on Callback',
            category: 'Session & CSRF',
            badge: 'State Error',
            badgeColor: 'bg-amber-100 text-amber-800 border-amber-200',
            problem: 'Returning from the Login Portal to /sso/callback fails with "Invalid or expired SSO session state."',
            cause: 'Either SESSION_SAME_SITE is set to "strict", the user started at localhost and returned to 127.0.0.1, SESSION_SECURE_COOKIE=true is set on plain HTTP, or the user clicked an IdP-initiated launch link (/sso/launch/{clientName}) without an existing client session state.',
            solution: 'Set SESSION_SAME_SITE=lax, use the exact same hostname as LOGIN_PORTAL_REDIRECT_URI, and set SESSION_SECURE_COOKIE=false on local HTTP.',
            codeSnippet: `SESSION_DRIVER=database
SESSION_SAME_SITE=lax
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true`,
        },
        {
            id: 'invalid_client_credentials',
            title: 'Client Authentication Failed (invalid_client)',
            category: 'Credentials',
            badge: 'HTTP 401',
            badgeColor: 'bg-red-100 text-red-800 border-red-200',
            problem: 'POST /api/sso/token returns 401 Unauthorized with {"error":"invalid_client"}.',
            cause: 'client_id or client_secret is wrong, the client was deactivated in /admin/sso, the secret was rotated via Regenerate Secret, or the client app has a stale configuration cache.',
            solution: 'Verify LOGIN_PORTAL_CLIENT_ID and LOGIN_PORTAL_CLIENT_SECRET in .env and clear cached config.',
            codeSnippet: `php artisan config:clear`,
        },
    ];

    let stackTips = [];
    if (frameworkKey === 'laravel_inertia') {
        stackTips = [
            {
                id: 'inertia_cors_redirect',
                title: 'CORS Error When Clicking SSO Button in Inertia.js',
                category: 'Inertia / Vue',
                badge: 'CORS Block',
                badgeColor: 'bg-red-100 text-red-800 border-red-200',
                problem: 'Clicking the SSO login button triggers an XHR CORS error to /sso/authorize in the browser console.',
                cause: 'Using Inertia <Link> or router.visit() performs an XHR request instead of a full top-level browser redirect.',
                solution: 'Use a standard HTML <a :href="route(\'sso.redirect\')"> anchor tag so the browser performs a full-page navigation.',
                codeSnippet: `<a :href="route('sso.redirect')" class="btn-sso">Sign in with Central Login Portal (SSO)</a>`,
            },
        ];
    } else if (frameworkKey === 'laravel_livewire') {
        stackTips = [
            {
                id: 'livewire_navigate_cors',
                title: 'wire:navigate Blocks External OAuth Redirect',
                category: 'Livewire',
                badge: 'SPA Nav Error',
                badgeColor: 'bg-amber-100 text-amber-800 border-amber-200',
                problem: 'Clicking the SSO button fails to navigate to the external Login Portal when using Livewire.',
                cause: 'Adding wire:navigate intercepts the link via AJAX.',
                solution: 'Use a standard <a href="{{ route(\'sso.redirect\') }}"> tag without wire:navigate.',
                codeSnippet: `<a href="{{ route('sso.redirect') }}">Sign in with Central Login Portal (SSO)</a>`,
            },
        ];
    }

    return [...universalTips, ...stackTips];
}

/**
 * Generate a comprehensive, self-contained AI Agent Prompt designed for autonomous coding agents
 * (Google Antigravity, Claude Code, Cursor, GitHub Copilot, Codex) to inspect a target client repository
 * and implement the verified SSO contract end-to-end without needing access to the SSO server's source code.
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

    const stackSpecificNotes = {
        laravel_inertia: `- Framework Target: Laravel (10.x/11.x/12.x/13.x) + Jetstream + Inertia.js (Vue 3).
- In resources/js/Pages/Auth/Login.vue, use a standard HTML <a :href="route('sso.redirect')"> tag (NEVER Inertia <Link> or router.visit) so the browser performs a full-page navigation to the external Login Portal.
- Expose config in config/services.php under 'login_portal', implement App\\Http\\Controllers\\Auth\\SsoClientController and App\\Http\\Controllers\\Auth\\SsoVerifyCredentialsController, and write PHPUnit/Pest tests using Http::fake().`,
        laravel_livewire: `- Framework Target: Laravel (10.x/11.x/12.x/13.x) + Jetstream (Livewire / Blade).
- In resources/views/auth/login.blade.php, use a standard <a href="{{ route('sso.redirect') }}"> tag WITHOUT wire:navigate.
- Expose config in config/services.php under 'login_portal', implement App\\Http\\Controllers\\Auth\\SsoClientController and App\\Http\\Controllers\\Auth\\SsoVerifyCredentialsController, and write PHPUnit/Pest tests using Http::fake().`,
        laravel_blade: `- Framework Target: Laravel (10.x/11.x/12.x/13.x) Standard MVC / Blade.
- Expose config in config/services.php under 'login_portal', implement App\\Http\\Controllers\\Auth\\SsoClientController and App\\Http\\Controllers\\Auth\\SsoVerifyCredentialsController, add the SSO button to resources/views/auth/login.blade.php, and write PHPUnit/Pest tests using Http::fake().`,
        php_vanilla: `- Framework Target: Native PHP (^8.2) + PDO + cURL + PHP Sessions.
- Configure session_set_cookie_params with 'samesite' => 'Lax' and 'httponly' => true before session_start().
- Implement sso_redirect.php, sso_callback.php (calling session_regenerate_id(true) after authentication), and api/sso/verify-credentials.php using PDO prepared statements and hash_equals().`,
        node_express: `- Framework Target: Node.js + Express + express-session + Axios.
- Configure express-session with cookie: { sameSite: 'lax', httpOnly: true }.
- Implement GET /sso/redirect, GET /sso/callback (calling req.session.regenerate() and req.session.save() before res.redirect()), and POST /api/sso/verify-credentials (using crypto.timingSafeEqual). Write Jest + Supertest tests mocking Axios.`,
        python_django: `- Framework Target: Python + Django + requests.
- Set SESSION_COOKIE_SAMESITE = 'Lax' and SESSION_COOKIE_HTTPONLY = True in settings.py.
- Implement sso_redirect, sso_callback (setting user.backend = 'django.contrib.auth.backends.ModelBackend', login(request, user), and request.session.cycle_key()), and @csrf_exempt POST /api/sso/verify-credentials. Write django.test.TestCase tests with unittest.mock.patch.`,
        dotnet_core: `- Framework Target: ASP.NET Core (C#) + Cookie Authentication + EF Core.
- Register AddSession(o => { o.Cookie.SameSite = SameSiteMode.Lax; o.Cookie.HttpOnly = true; }) and place app.UseSession() BEFORE app.UseAuthentication().
- Implement GET /sso/redirect, GET /sso/callback (using HttpClient and HttpContext.SignInAsync), and [IgnoreAntiforgeryToken] POST /api/sso/verify-credentials (using CryptographicOperations.FixedTimeEquals).`,
        spring_boot: `- Framework Target: Java + Spring Boot 3 + Spring Security + Spring Data JPA.
- Permit /login, /sso/**, and /api/sso/verify-credentials in SecurityFilterChain, and ignore CSRF on /api/sso/verify-credentials.
- Implement GET /sso/redirect, GET /sso/callback (calling request.changeSessionId()), and POST /api/sso/verify-credentials. Write MockMvc / MockRestServiceServer tests.`,
        nuxt_node: `- Framework Target: Nuxt 3/4 + Nitro Server (Node.js).
- Keep LOGIN_PORTAL_CLIENT_SECRET in server-only runtimeConfig (never in public runtimeConfig).
- Implement server/routes/sso/redirect.get.ts (httpOnly, sameSite: 'lax' cookies), server/routes/sso/callback.get.ts, and server/api/sso/verify-credentials.post.ts.`,
        vue_spa: `- Framework Target: Vue.js / React SPA with Backend API Proxy.
- Never place LOGIN_PORTAL_CLIENT_SECRET in frontend VITE_* or REACT_APP_* variables.
- Have the Backend API Proxy perform the POST /api/sso/token exchange and expose POST /api/sso/verify-credentials.`,
    }[frameworkKey] || `- Framework Target: ${meta.name}. Follow the repository's native routing, session, ORM, and testing conventions.`;

    return `You are an expert full-stack software engineer. Your task is to integrate OAuth 2.0 Single Sign-On (SSO) with the central **Login Portal SSO Server** into this client repository (**${clientName}**, stack: **${meta.name}**).

This prompt is completely self-contained. Follow the 4 phases below in strict order. Treat the **Verified SSO Server Integration Contract** in Phase 2 as the authoritative source of truth.

---

### PHASE 1: Inspect the Client Repository First (Mandatory Before Writing Code)

Before creating or modifying any files, inspect this repository and determine:
1. **Framework & Package Versions**:
   - Inspect \`composer.json\`, \`package.json\`, \`requirements.txt\`, \`*.csproj\`, \`pom.xml\`, or equivalent manifest files.
   - Identify the exact framework major version, authentication packages (e.g., Laravel Fortify, Jetstream, Sanctum, Breeze, Inertia, Livewire, or custom auth), and test runner (PHPUnit, Pest, Jest, Pytest, xUnit, JUnit).
2. **Existing Authentication & Session Setup**:
   - Inspect existing login/logout routes, controllers, middleware, guards, session configuration, and login UI templates/components.
   - Identify where authenticated users are redirected after login (e.g., \`/dashboard\` or \`RouteServiceProvider::HOME\`).
3. **Existing Database Schema & User Model**:
   - Inspect existing migrations and the \`User\` model/entity.
   - Check the primary key type of \`users\`, existing columns (\`email\`, \`username\`, \`password\`, \`status\`, \`is_active\`, \`role\`, etc.), and whether SSO columns (\`login_portal_user_id\`, \`sso_provider\`) or a \`sessions\` table already exist.
4. **Stack-Specific Notes for ${meta.name}**:
${stackSpecificNotes}

**Strict Preservation Rules**:
- Reuse existing models, authentication guards, middleware, and layout components.
- Preserve existing users and local username/password authentication alongside SSO.
- Create **only** missing migrations. Use non-destructive \`ALTER TABLE\` / \`Schema::table\` checks so existing data in \`users\` is never dropped or truncated.
- **NEVER** run destructive database commands (\`migrate:fresh\`, \`migrate:reset\`, \`db:wipe\`, \`DROP TABLE\`).
- **DO NOT** create SSO server tables (\`sso_clients\`, \`sso_authorization_codes\`, \`sso_user_bindings\`, \`ssos\`) in this client application. Those tables belong exclusively to the SSO server.

---

### PHASE 2: Verified SSO Server Integration Contract (Source of Truth)

The central Login Portal SSO Server implements an OAuth 2.0 Authorization Code flow with PKCE (\`S256\`), OIDC \`nonce\`, and mandatory **Connected Systems Account Binding**.

#### 1. Target Values & Environment Variables
Configure the client application to read these environment variables (never hardcode secrets in committed source files):
\`\`\`dotenv
LOGIN_PORTAL_URL=${cfg.portalUrl}
LOGIN_PORTAL_CLIENT_ID=${cfg.clientId}
LOGIN_PORTAL_CLIENT_SECRET=${cfg.clientSecret}
LOGIN_PORTAL_REDIRECT_URI=${cfg.redirectUri}
LOGIN_PORTAL_AUTO_PROVISION=true
\`\`\`
- If any real credential values are needed for local verification, ask the developer to place them directly into their local untracked \`.env\` file. Never print, log, or commit real secrets.

#### 2. SSO Server Endpoints
- **Authorization Endpoint (\`GET\`)**: \`{LOGIN_PORTAL_URL}/sso/authorize\`
  - Required query parameters: \`client_id\`, \`redirect_uri\`, \`response_type=code\`, \`state\` (40+ char random CSRF token).
  - Recommended query parameters: \`scope=openid profile email\`, \`code_challenge\` (\`BASE64URL-ENCODE(SHA256(code_verifier))\`), \`code_challenge_method=S256\`, \`nonce\` (40+ char random token).
  - Behavior: Authenticates the user on the Portal, verifies that an active \`sso_user_bindings\` record exists for \`(user_id, client_id)\` (redirecting unbound users to \`{LOGIN_PORTAL_URL}/connected-systems\`), generates a single-use 80-character authorization \`code\` valid for **2 minutes** (\`120\` seconds), and redirects the browser to \`{redirect_uri}?code={code}&state={state}\`.
  - Note on Errors: Request validation errors on \`/sso/authorize\` (\`invalid_request\`, \`unauthorized_client\`, \`invalid_redirect_uri\`, \`unsupported_response_type\`) return HTTP 400 JSON on the Portal, and banned Portal users receive HTTP 403 JSON (\`access_denied\`). Your callback handler must also gracefully handle \`?error=...&error_description=...\` query parameters if present.
- **Token Exchange Endpoint (\`POST\`)**: \`{LOGIN_PORTAL_URL}/api/sso/token\` (also aliased at \`POST {LOGIN_PORTAL_URL}/sso/token\`)
  - \`Content-Type\`: \`application/x-www-form-urlencoded\` or \`application/json\`.
  - Request body:
    - \`grant_type\`: \`"authorization_code"\`
    - \`client_id\`: \`LOGIN_PORTAL_CLIENT_ID\`
    - \`client_secret\`: \`LOGIN_PORTAL_CLIENT_SECRET\`
    - \`redirect_uri\`: \`LOGIN_PORTAL_REDIRECT_URI\` (must match the \`/sso/authorize\` request)
    - \`code\`: The single-use authorization code from callback query
    - \`code_verifier\`: The original PKCE verifier generated before redirecting
  - Success Response (\`HTTP 200 OK\`):
    \`\`\`json
    {
      "access_token": "80_char_random_string",
      "token_type": "Bearer",
      "expires_in": 3600,
      "nonce": "echoed_nonce_if_provided",
      "user": {
        "id": 42,
        "bound_user_id": "15",
        "bound_username": "user@lgu.gov.ph",
        "name": "Juan Dela Cruz",
        "email": "user@lgu.gov.ph",
        "email_verified_at": "2026-09-14T08:00:00.000000Z",
        "profile_photo_url": "https://..."
      }
    }
    \`\`\`
  - **CRITICAL IMPLEMENTATION RULE**: Consume \`response.user\` **directly** from the \`POST /api/sso/token\` response! **DO NOT** make a follow-up request to \`GET /api/user\` or \`GET /api/sso/userinfo\` using \`access_token\`. The SSO server generates an ephemeral \`access_token\` and does not persist it in \`personal_access_tokens\`, so calling \`/api/user\` with \`Bearer {access_token}\` will always fail with \`401 Unauthorized\`.

#### 3. Client Endpoint Required for Connected Systems Account Binding
When a user binds their account on the SSO Server (\`POST /connected-systems/{clientId}/bind\`), the SSO Server sends a server-to-server \`POST\` request (\`application/x-www-form-urlencoded\`, 5-second timeout) to your client application at:
- \`POST /api/sso/verify-credentials\` (and fallback \`POST /sso/verify-credentials\`)
- Incoming fields: \`username\` (email or username), \`password\` (plaintext local password), \`client_secret\` (must match \`LOGIN_PORTAL_CLIENT_SECRET\` using constant-time \`hash_equals\`).
- Expected \`200 OK\` JSON response on valid credentials:
  \`\`\`json
  {
    "valid": true,
    "user_id": "<local_user_primary_key_as_string>",
    "name": "<local_user_name>",
    "email": "<local_user_email>"
  }
  \`\`\`
- Expected \`401 Unauthorized\` / \`403 Forbidden\` JSON response on invalid credentials or secret:
  \`\`\`json
  {
    "valid": false,
    "message": "Invalid username or password."
  }
  \`\`\`

---

### PHASE 3: Implementation Requirements

Implement the following in this client repository, adapting file paths and idioms to match the detected framework:

1. **Configuration & Environment**:
   - Add \`LOGIN_PORTAL_URL\`, \`LOGIN_PORTAL_CLIENT_ID\`, \`LOGIN_PORTAL_CLIENT_SECRET\`, \`LOGIN_PORTAL_REDIRECT_URI\`, and \`LOGIN_PORTAL_AUTO_PROVISION\` placeholders to \`.env.example\` (and \`.env\` if present, without overwriting existing secrets).
   - Register the configuration in \`config/services.php\` under \`'login_portal'\` (or the framework's equivalent config module).
   - Ensure session cookies use \`SameSite=Lax\` and \`HttpOnly=true\` so \`sso_state\` survives the cross-domain redirect from the Login Portal.

2. **Minimum Client Database Migration & Model Update**:
   - Ensure the \`users\` table has:
     - \`login_portal_user_id\` (\` BIGINT UNSIGNED NULL UNIQUE\`)
     - \`sso_provider\` (\`VARCHAR(64) NULL DEFAULT 'login_portal'\`, indexed)
   - Guard column additions with \`Schema::hasColumn\` (or equivalent) so the migration is safe on both new and existing databases.
   - Update the \`User\` model's fillable/cast properties accordingly.

3. **Connected Systems Credential Verification Endpoint (\`POST /api/sso/verify-credentials\`)**:
   - Implement the endpoint accepting \`username\`, \`password\`, and \`client_secret\`.
   - Verify \`client_secret\` using constant-time comparison (\`hash_equals\`).
   - Look up the local user by \`email\` (and \`username\` if the column exists in \`users\`), verify the password hash, check that the user is not banned/suspended/inactive, and return \`{ valid: true, user_id: (string) $user->id, name: $user->name, email: $user->email }\`.
   - Ensure this endpoint is exempt from browser CSRF token verification since it is called server-to-server by the SSO Portal.

4. **SSO Login Flow (\`GET /sso/redirect\` & \`GET /sso/callback\`)**:
   - **Redirect (\`GET /sso/redirect\`)**:
     - Validate that \`LOGIN_PORTAL_URL\`, \`LOGIN_PORTAL_CLIENT_ID\`, and \`LOGIN_PORTAL_REDIRECT_URI\` are configured.
     - Generate cryptographically random \`state\`, \`nonce\`, and PKCE \`code_verifier\` + \`S256\` \`code_challenge\`.
     - Store \`sso_state\`, \`sso_nonce\`, and \`sso_code_verifier\` in the session and redirect to \`{LOGIN_PORTAL_URL}/sso/authorize\`.
   - **Callback (\`GET /sso/callback\`)**:
     - Handle \`error\` query parameter gracefully.
     - Pull and consume \`sso_state\`, \`sso_nonce\`, and \`sso_code_verifier\` from the session immediately (single-use).
     - Validate \`state\` using constant-time \`hash_equals\`.
     - Exchange \`code\` + \`code_verifier\` via backchannel \`POST\` to \`{LOGIN_PORTAL_URL}/api/sso/token\` with a 15-second timeout. Catch network/connection exceptions gracefully and redact secrets/tokens from logs.
     - Verify \`nonce\` if returned.
     - Resolve the local user from \`response.user\` using the **4-Tier Identity Resolution Order**:
       1. Match local user primary key by \`response.user.bound_user_id\` (if non-empty).
       2. Match local user by \`login_portal_user_id == response.user.id\`.
       3. Match local user by \`email == response.user.email\`.
       4. If matched in Tiers 1–3, link/update \`login_portal_user_id = response.user.id\` and \`sso_provider = 'login_portal'\`.
       5. If no local user exists: check \`auto_provision\` config (\`LOGIN_PORTAL_AUTO_PROVISION\`). If \`true\`, create a new local user with \`name\`, \`email\`, \`login_portal_user_id\`, \`sso_provider = 'login_portal'\`, and a random hashed password. If \`false\`, reject login with a clear authorization message.
     - **Authorization Boundary Check**: If the local \`User\` model has a status/active/banned attribute (e.g. \`status === 'banned'\` or \`is_active === false\`), deny login before creating a session.
     - Authenticate the user, **regenerate the session ID** to prevent session fixation, and redirect to the intended destination (\`redirect()->intended('/dashboard')\`).

5. **Login UI & Logout Behavior**:
   - Add a **"Sign in with Central Login Portal (SSO)"** button to the login view pointing to \`/sso/redirect\` (using a full-page browser navigation link \`<a>\`, not an SPA XHR link) while preserving the existing local login form and displaying error messages clearly.
   - Keep local logout behavior clean (invalidating local session and CSRF token). Document that local logout terminates the client session only (no OIDC Single Logout on the SSO server).

---

### PHASE 4: Automated Testing & Final Report

1. **Write Automated Feature/Integration Tests**:
   - Mock all outbound HTTP calls to the Login Portal (e.g., \`Http::fake()\` in Laravel) — never call a live SSO server in tests.
   - Cover at minimum:
     - \`GET /sso/redirect\` stores \`sso_state\`, \`sso_nonce\`, and \`sso_code_verifier\` in session and redirects to \`/sso/authorize\` with \`code_challenge_method=S256\`.
     - \`GET /sso/callback\` rejects missing or mismatched \`state\` and keeps the user unauthenticated.
     - \`GET /sso/callback\` exchanges a valid \`code\`, provisions a new user when \`auto_provision=true\`, regenerates the session, and redirects to \`/dashboard\`.
     - \`GET /sso/callback\` matches an existing user by \`bound_user_id\` or \`email\` and links \`login_portal_user_id\` without creating a duplicate row.
     - \`GET /sso/callback\` denies login for unprovisioned users when \`auto_provision=false\`.
     - \`GET /sso/callback\` handles token endpoint \`400\`/\`401\`/\`500\` errors gracefully.
     - \`POST /api/sso/verify-credentials\` returns \`200 OK\` with \`valid: true\` and \`user_id\` for valid credentials + \`client_secret\`, and \`401\` for invalid credentials or secret.
2. **Run the Test Suite & Formatter**:
   - Execute the new and existing auth tests and ensure all pass.
3. **Provide a Structured Final Report**:
   - Detected framework, package versions, and auth stack.
   - Complete list of created and modified files.
   - Database migrations created and their status.
   - Required \`.env\` variables and values the developer still needs to supply.
   - Exact commands to run locally and in production.`;
}

/**
 * Main entry point: build complete guide data with steps, metadata, AI Agent prompt, and troubleshooting tips.
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

    const troubleshooting = getTroubleshootingTips(frameworkKey, {
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
        troubleshooting,
    };
}

/**
 * Print / Save as PDF via executive styled browser printable window.
 */
export function printIntegrationGuide(docData) {
    const printWindow = window.open('', '_blank', 'width=1050,height=950');
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

    const troubleshooting = docData.troubleshooting || getTroubleshootingTips(docData.framework?.key || 'laravel_inertia', {
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
            max-width: 880px; margin: 0 auto; background: #ffffff; border-radius: 12px;
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
        .badge-red { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
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
        .troubleshooting-card {
            background: #ffffff; border: 1px solid #fecaca; border-left: 4px solid #b91c1c;
            border-radius: 8px; padding: 16px 20px; margin-bottom: 16px; page-break-inside: avoid;
        }
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
            .troubleshooting-section { page-break-before: always; }
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
                        Official Technical Integration &amp; Implementation Specification for Single Sign-On (SSO)
                    </div>
                </div>
            </div>
            <div class="meta-badges">
                <span class="badge">${escapeHtml(docData.framework?.name || docData.frameworkName)}</span>
                <span class="badge badge-navy">OAuth 2.0 + PKCE (S256)</span>
                <span class="badge badge-gold">Part 1: Manual Guide</span>
                <span class="badge badge-red">Part 2: Troubleshooting</span>
                <span class="badge badge-navy">Part 3: AI Agent</span>
            </div>
        </div>

        <!-- Overview -->
        <div class="overview-box">
            <strong>Executive Overview:</strong> ${escapeHtml(docData.overview)}
        </div>

        <!-- Specifications & Credentials Table -->
        <div class="specs-card">
            <div class="specs-header">
                <span>Registered Application Configuration &amp; Verified Protocol Contract</span>
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
                        <td class="specs-key">Token &amp; Identity URL:</td>
                        <td class="specs-val">${escapeHtml(portalUrl)}/api/sso/token</td>
                    </tr>
                    <tr>
                        <td class="specs-key">Redirect URI:</td>
                        <td class="specs-val">${escapeHtml(docData.redirectUri || 'https://your-app.example/sso/callback')}</td>
                        <td class="specs-key">Client Bind Endpoint:</td>
                        <td class="specs-val">/api/sso/verify-credentials</td>
                    </tr>
                    <tr>
                        <td class="specs-key">Grant &amp; PKCE:</td>
                        <td class="specs-val">authorization_code (S256)</td>
                        <td class="specs-key">Scopes / Code TTL:</td>
                        <td class="specs-val">openid profile email (120s single-use)</td>
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
            <div class="checklist-title">🛡️ Security &amp; Production Readiness Checklist</div>
            <div class="checklist-item">• <strong>HTTPS / TLS Mandatory:</strong> Production environments MUST serve all redirect URIs and API calls over encrypted HTTPS.</div>
            <div class="checklist-item">• <strong>CSRF State + PKCE (S256) + Nonce:</strong> Generate state, PKCE code_verifier/code_challenge, and nonce on redirect; verify and consume immediately on callback.</div>
            <div class="checklist-item">• <strong>Direct User Payload Consumption:</strong> Read response.user directly from POST /api/sso/token. Do NOT call GET /api/user with access_token.</div>
            <div class="checklist-item">• <strong>Connected Systems Binding Endpoint:</strong> Expose POST /api/sso/verify-credentials and validate client_secret with hash_equals().</div>
            <div class="checklist-item">• <strong>4-Tier Identity Mapping:</strong> Match returning users by bound_user_id first, then login_portal_user_id, then email, then auto-provision (if enabled).</div>
            <div class="checklist-item">• <strong>Session Fixation Protection:</strong> Regenerate the application session ID immediately upon authenticating the user locally.</div>
            <div class="checklist-item">• <strong>Automated Test Isolation:</strong> Mock all Login Portal HTTP calls in automated tests (e.g. Http::fake). Never depend on live portal servers in CI.</div>
        </div>

        <!-- Part 2 Divider: Troubleshooting Tips -->
        <div class="troubleshooting-section" style="margin-top: 36px; page-break-before: always;">
            <div class="section-divider" style="border-color: #b91c1c;">
                <span class="section-badge" style="background: #b91c1c;">PART 2</span>
                <h2 class="section-title">Troubleshooting &amp; Diagnostic Guide</h2>
            </div>
            <p style="font-size: 13px; color: #475569; margin-bottom: 16px; line-height: 1.5;">
                Common error codes, configuration pitfalls, and step-by-step diagnostic solutions for <strong>${escapeHtml(docData.frameworkName)}</strong>:
            </p>

            ${troubleshooting.map((tip, idx) => `
                <div class="troubleshooting-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 13.5px; font-weight: 800; color: #991b1b;">
                            ${idx + 1}. [${escapeHtml(tip.badge)}] ${escapeHtml(tip.title)}
                        </span>
                        <span style="font-size: 11px; font-weight: 700; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 4px;">
                            ${escapeHtml(tip.category)}
                        </span>
                    </div>
                    <div style="font-size: 12.5px; line-height: 1.5; color: #334155; margin-bottom: 6px;">
                        <strong style="color: #b91c1c;">Symptom:</strong> ${escapeHtml(tip.problem)}
                    </div>
                    <div style="font-size: 12.5px; line-height: 1.5; color: #334155; margin-bottom: 6px;">
                        <strong style="color: #92400e;">Root Cause:</strong> ${escapeHtml(tip.cause)}
                    </div>
                    <div style="font-size: 12.5px; line-height: 1.5; color: #065f46; background: #ecfdf5; padding: 8px 12px; border-radius: 6px; border: 1px solid #a7f3d0;">
                        <strong>Resolution:</strong> ${escapeHtml(tip.solution)}
                    </div>
                    ${tip.codeSnippet ? `<pre style="margin-top: 8px; background: #0f172a; color: #e2e8f0; padding: 10px 12px; border-radius: 6px; font-size: 11px; font-family: monospace; white-space: pre-wrap;"><code>${escapeHtml(tip.codeSnippet)}</code></pre>` : ''}
                </div>
            `).join('')}
        </div>

        <!-- Part 3 Divider: AI Agent Section -->
        <div class="ai-section">
            <div class="section-divider" style="margin-top: 0; border-color: #1e293b;">
                <span class="section-badge" style="background: #1e293b;">PART 3</span>
                <h2 class="section-title">Integration using AI Coding Agent</h2>
            </div>
            <p style="font-size: 13px; color: #475569; margin-bottom: 14px; line-height: 1.5;">
                Copy and paste the following self-contained autonomous prompt into your AI coding assistant (Google Antigravity, Claude Code, Cursor, GitHub Copilot) to inspect your client repository and implement and test the SSO integration:
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
        const words = (text || '').split(/\s+/);
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
        this.addText(`Portal Base URL: ${portalUrl}  |  Protocol: OAuth 2.0 + PKCE (S256)  |  Confidential`, this.margin + 16, topY - 50, '/F1', 8, [0.82, 0.9, 0.82]);
        this.addText(`Date: ${new Date().toLocaleDateString()}`, this.margin + this.contentWidth - 16, topY - 50, '/F1', 8, [0.82, 0.9, 0.82], 'right');

        this.addRect(this.margin + 16, botY + 7, 440, 14, [0.06, 0.18, 0.13], null);
        this.addText('COMPLETE PACKAGE: PART 1 (MANUAL) * PART 2 (TROUBLESHOOTING) * PART 3 (AI AGENT)', this.margin + 22, botY + 11, '/F2', 6.8, [0.9, 0.96, 0.9]);

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
            ['Client Secret', credentials.clientSecret || 'your_client_secret', 'Token & User URL', `${credentials.portalUrl}/api/sso/token`],
            ['Redirect URI', credentials.redirectUri || 'https://your-app.example/sso/callback', 'Client Bind Endpoint', '/api/sso/verify-credentials'],
            ['Grant & PKCE', 'authorization_code (S256)', 'Scopes / Code TTL', 'openid profile email (120s)'],
        ];

        const rowH = 16;
        const h = 22 + (rows.length * rowH);
        this.ensureSpace(h);
        const botY = this.y - h;

        this.addRect(this.margin, botY, this.contentWidth, h, [0.98, 0.98, 0.99], [0.82, 0.85, 0.88], 1);
        this.addRect(this.margin, this.y - 16, this.contentWidth, 16, [0.92, 0.94, 0.97], null);
        this.addText('REGISTERED CLIENT CONFIGURATION & VERIFIED PROTOCOL CONTRACT', this.margin + 10, this.y - 11, '/F2', 7.8, [0.15, 0.2, 0.3]);

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

        this.addRect(this.margin, this.y - 17, 56, 16, [0.106, 0.263, 0.196], null);
        this.addText(`STEP ${step.stepNumber}`, this.margin + 28, this.y - 12, '/F2', 8, [1, 1, 1], 'center');
        this.addText(step.title, this.margin + 64, this.y - 12, '/F2', 9.5, [0.08, 0.12, 0.1]);
        this.y -= 25;

        descLines.forEach(dl => {
            this.addText(dl, this.margin + 8, this.y, '/F1', 8, [0.25, 0.25, 0.25]);
            this.y -= 10;
        });

        if (step.filename) {
            this.addText(`TARGET FILE: ${step.filename}`, this.margin + 8, this.y, '/F2', 7.5, [0.3, 0.4, 0.35]);
            this.y -= 12;
        }

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

    drawTroubleshootingSection(tips) {
        if (!tips || tips.length === 0) return;
        this.startNewPage();
        this.drawSectionHeading('PART 2', 'SSO TROUBLESHOOTING & DIAGNOSTIC GUIDE', [0.72, 0.15, 0.15]);

        const introText = 'Common error codes, configuration pitfalls, and step-by-step diagnostic solutions for ' + this.title + ':';
        const wrappedIntro = this.wrapText(introText, this.contentWidth - 20, 8 * 0.52);

        wrappedIntro.forEach(l => {
            this.addText(l, this.margin + 6, this.y, '/F1', 8, [0.2, 0.2, 0.25]);
            this.y -= 10.5;
        });
        this.y -= 6;

        tips.forEach((tip, idx) => {
            const titleText = `${idx + 1}. [${tip.badge}] ${tip.title}`;
            const problemLines = this.wrapText('Symptom: ' + tip.problem, this.contentWidth - 28, 7.5 * 0.52);
            const causeLines = this.wrapText('Root Cause: ' + tip.cause, this.contentWidth - 28, 7.5 * 0.52);
            const solutionLines = this.wrapText('Resolution: ' + tip.solution, this.contentWidth - 28, 7.5 * 0.52);

            const cardH = 26 + ((problemLines.length + causeLines.length + solutionLines.length) * 9.5);
            this.ensureSpace(cardH + 10);

            const botY = this.y - cardH;
            this.addRect(this.margin, botY, this.contentWidth, cardH, [0.99, 0.98, 0.98], [0.9, 0.8, 0.8], 0.75);
            this.addRect(this.margin, this.y - 16, this.contentWidth, 16, [0.96, 0.9, 0.9], null);
            this.addRect(this.margin, botY, 3.5, cardH, [0.72, 0.15, 0.15], null);

            this.addText(titleText, this.margin + 8, this.y - 11, '/F2', 7.5, [0.65, 0.1, 0.1]);

            let curY = this.y - 25;
            problemLines.forEach(pl => {
                this.addText(pl, this.margin + 8, curY, '/F1', 7.2, [0.35, 0.15, 0.15]);
                curY -= 9.5;
            });
            causeLines.forEach(cl => {
                this.addText(cl, this.margin + 8, curY, '/F1', 7.2, [0.3, 0.3, 0.3]);
                curY -= 9.5;
            });
            solutionLines.forEach(sl => {
                this.addText(sl, this.margin + 8, curY, '/F2', 7.2, [0.08, 0.38, 0.15]);
                curY -= 9.5;
            });

            this.y = botY - 10;
        });
    }

    drawAiPromptSection(aiPrompt) {
        this.startNewPage();
        this.drawSectionHeading('PART 3', 'INTEGRATION USING AI CODING AGENT', [0.15, 0.23, 0.38]);

        const introText = 'You can pass this structured prompt directly to an autonomous AI coding assistant (Google Antigravity, Claude Code, Cursor, GitHub Copilot) to automatically inspect your codebase, create necessary controllers, register routes, and wire the OAuth 2.0 flow.';
        const wrappedIntro = this.wrapText(introText, this.contentWidth - 20, 8 * 0.52);

        wrappedIntro.forEach(l => {
            this.addText(l, this.margin + 6, this.y, '/F1', 8, [0.2, 0.2, 0.25]);
            this.y -= 10.5;
        });
        this.y -= 4;

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
                this.addText('PART 3: AI AGENT PROMPT (CONTINUED)', this.margin + 8, this.y, '/F2', 8.5, [0.15, 0.23, 0.38]);
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
            '2. CSRF State + PKCE (S256) + Nonce: Generate state, PKCE challenge, and nonce on redirect; verify and consume on callback.',
            '3. Direct User Payload: Consume response.user directly from POST /api/sso/token; do NOT call GET /api/user with access_token.',
            '4. Account Binding Endpoint: Expose POST /api/sso/verify-credentials validating client_secret with hash_equals().',
            '5. 4-Tier Identity Mapping: Match returning users by bound_user_id -> login_portal_user_id -> email -> auto-provision.',
            '6. Session Fixation Defense: Always regenerate application session ID immediately after authenticating local user session.',
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

        const troubleshooting = docData.troubleshooting || getTroubleshootingTips(docData.framework?.key || 'laravel_inertia', {
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
        builder.drawTroubleshootingSection(troubleshooting);
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
