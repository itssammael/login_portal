# LGUNET Portal — Single Sign-On (SSO) Integration Guide

This document provides a comprehensive technical guide for developers, system administrators, and integration teams connecting relying party applications (SSO Clients) to **LGUNET Portal** (`login_portal`).

---

## 1. Architecture Overview

LGUNET Portal operates as a centralized **Identity Provider (IdP)** implementing an **OAuth 2.0 Authorization Code Grant** with **PKCE (RFC 7636)** support.

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              LGUNET Portal                              │
│                      (Central Identity Provider)                        │
│                                                                         │
│  ┌──────────────────────┐               ┌───────────────────────────┐   │
│  │ Laravel Jetstream    │──────Auth────►│ LGUNET SSO Layer          │   │
│  │ User Authentication  │               │ Code & Token Authority    │   │
│  └──────────────────────┘               └─────────────┬─────────────┘   │
└───────────────────────────────────────────────────────┼─────────────────┘
                                                        │
                      ┌─────────────────────────────────┼─────────────────────────────────┐
                      │                                 │                                 │
                      ▼                                 ▼                                 ▼
         ┌─────────────────────────┐       ┌─────────────────────────┐       ┌─────────────────────────┐
         │       SSO Client        │       │       SSO Client        │       │       SSO Client        │
         │       TeamTracker       │       │       GIS Portal        │       │      Budget System      │
         │   http://teamtracker    │       │    http://gisportal     │       │    http://budgetsys     │
         └─────────────────────────┘       └─────────────────────────┘       └─────────────────────────┘
```

### Key Architectural Principles
- **No Shared Passwords**: User passwords never leave LGUNET Portal and are never transmitted to relying client applications.
- **Explicit Account Binding**: Users must explicitly bind their LGUNET Portal account to an external client application account before SSO authorization is granted.
- **Short-Lived Authorization Codes**: Codes expire in 2 minutes and are strictly single-use (replay protected).
- **Backchannel Token Exchange**: Client secrets are exchanged exclusively via direct server-to-server requests over HTTPS/HTTP, never via browser query parameters.
- **Preservation of Jetstream**: The core Jetstream/Fortify authentication architecture (session cookies, 2FA, password management) remains untouched and acts as the root authentication authority.

---

## 2. Database Schema

### `sso` (Registered SSO Clients)
| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED (PK)` | Primary identifier |
| `name` | `VARCHAR(255)` | Human-readable application name (e.g., "TeamTracker") |
| `description` | `TEXT (NULL)` | Purpose and notes regarding the application |
| `client_id` | `VARCHAR(255) (UNIQUE)` | Public client identifier (e.g., `client_a1b2c3d4e5f6g7h8`) |
| `client_secret` | `VARCHAR(255)` | Secret key for backchannel authentication (hidden from JSON) |
| `redirect_uri` | `TEXT` | Authorized callback URL(s), comma-separated |
| `api_url` | `VARCHAR(500) (NULL)` | Explicit base API URL of client for account verification |
| `icon` | `VARCHAR(255) (NULL)` | Relative path to uploaded icon file |
| `framework` | `VARCHAR(100)` | Client technology stack preset (e.g., `laravel_inertia`) |
| `created_by` | `BIGINT UNSIGNED (FK)` | Administrator who registered the client |
| `is_active` | `BOOLEAN (DEFAULT TRUE)` | Whether the client is allowed to authenticate |
| `last_used_at` | `TIMESTAMP (NULL)` | Timestamp of most recent token exchange |
| `created_at` | `TIMESTAMP` | Record creation timestamp |
| `updated_at` | `TIMESTAMP` | Record update timestamp |

### `sso_authorization_codes` (Temporary One-Time Codes)
| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED (PK)` | Primary identifier |
| `code` | `VARCHAR(255) (UNIQUE)` | SHA-256 hash of the issued authorization code |
| `client_id` | `VARCHAR(255)` | SSO client identifier |
| `user_id` | `BIGINT UNSIGNED (FK)` | Authenticated LGUNET Portal user ID |
| `redirect_uri` | `TEXT` | Redirect URI specified during the authorization request |
| `nonce` | `VARCHAR(255) (NULL)` | Optional client nonce for replay prevention |
| `code_challenge` | `VARCHAR(255) (NULL)` | PKCE code challenge |
| `code_challenge_method` | `VARCHAR(255)` | PKCE method (`S256` or `plain`) |
| `expires_at` | `TIMESTAMP` | Expiration timestamp (2 minutes from issue) |
| `used_at` | `TIMESTAMP (NULL)` | Invalidation timestamp set immediately upon exchange |
| `created_at` | `TIMESTAMP` | Record creation timestamp |

### `sso_user_bindings` (User Identity Associations)
| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED (PK)` | Primary identifier |
| `user_id` | `BIGINT UNSIGNED (FK)` | LGUNET Portal user ID |
| `client_id` | `VARCHAR(255)` | Registered SSO Client ID |
| `external_user_id` | `VARCHAR(255)` | User ID in the external client application |
| `external_username` | `VARCHAR(255)` | Username / Email in the external client application |
| `is_verified` | `BOOLEAN (DEFAULT TRUE)` | Verified ownership flag |
| `last_login_at` | `TIMESTAMP (NULL)` | Last time user launched SSO into this client |
| `created_at` | `TIMESTAMP` | Binding timestamp |
| `updated_at` | `TIMESTAMP` | Binding update timestamp |

---

## 3. SSO Client Registration (Administrators)

Administrators manage SSO Clients via the Admin Panel:
`GET /admin/sso` or `GET /admin/sso-clients`

### Registration Fields:
1. **Application Name**: Display name for the client (e.g., `GIS Portal`).
2. **Client ID**: Unique alphanumeric identifier (e.g., `client_7f2b1c4e9a0d3f81`).
3. **Redirect URI**: Strictly checked callback URL (e.g., `http://gisportal.local/sso/callback`). Multiple URIs can be comma-separated.
4. **System Base URL (Optional)**: Root URL of the client system used for account verification (`/api/sso/verify-credentials`).
5. **Framework Stack**: Preset for custom documentation export.
6. **Icon**: Custom PNG/JPEG application logo.

### Security Protocol for Client Secrets:
- Client secrets are **cryptographically random (64 characters)** generated via `Str::random(64)`.
- Client secrets are **never sent in listing responses** (`$hidden = ['client_secret']`).
- When created or rotated, the plaintext secret is displayed **once** in a secure modal.
- Administrators must copy the secret and store it in the client application's `.env` or vault.

---

## 4. SSO Authorization Flow (Browser Redirect)

### Endpoint: `GET /sso/authorize`

```
User Browser                  SSO Client                LGUNET Portal
     │                            │                           │
     │── 1. Click SSO Button ────►│                           │
     │                            │── 2. Build Auth URL ─────►│
     │◄── 3. 302 Redirect ────────│                           │
     │                                                        │
     │── 4. GET /sso/authorize ──────────────────────────────►│
     │                                                        │
     │   [If unauthenticated: prompt login & preserve state]  │
     │   [If unbound: prompt account binding & preserve state]│
     │                                                        │
     │◄── 5. 302 Redirect back to callback URI with code ─────│
     │                                                        │
     │── 6. GET /sso/callback?code=CODE&state=STATE ─────────►│
```

### Request Parameters:
| Parameter | Type | Required | Description |
|---|---|---|---|
| `client_id` | string | Yes | Registered Client ID |
| `redirect_uri` | string | Yes | Must exactly match registered redirect URI |
| `response_type` | string | Yes | Must be `code` |
| `state` | string | Yes | Random CSRF token generated by the SSO client |
| `code_challenge` | string | Recommended | PKCE `BASE64URL(SHA256(verifier))` |
| `code_challenge_method` | string | Recommended | Must be `S256` |
| `nonce` | string | Optional | Replay prevention nonce |

### Validation & Behavior:
1. If client is invalid or `is_active === false`, returns HTTP 400 (`unauthorized_client`).
2. If `redirect_uri` does not match the registered domain/path, returns HTTP 400 (`invalid_redirect_uri`).
3. If user is unauthenticated, LGUNET Portal saves params in session and redirects to Jetstream login (`/login`). Once authenticated, `CheckPendingSsoRequest` resumes the authorization flow.
4. If user has not bound their account, redirects to `/connected-systems` with notice. Once bound, flow automatically resumes.
5. If valid, generates a 40-character single-use code, stores its SHA-256 hash with 2-minute expiration, and redirects to:
   `https://client.example/sso/callback?code={AUTH_CODE}&state={STATE}`

---

## 5. Token Exchange (Backchannel Server-to-Server)

### Endpoint: `POST /sso/token` (or `POST /api/sso/token`)
- Protected by rate limiting (`60 requests/min`).
- CSRF exempt for server-to-server requests.

### Request Body (`application/x-www-form-urlencoded` or `application/json`):
```json
{
  "grant_type": "authorization_code",
  "client_id": "your_client_id",
  "client_secret": "your_client_secret",
  "code": "40_character_authorization_code_here",
  "redirect_uri": "https://your-app.example/sso/callback",
  "code_verifier": "plain_code_verifier_if_pkce_was_used"
}
```

### Response (`200 OK`):
```json
{
  "token_type": "Bearer",
  "access_token": "random_secure_access_token_string",
  "expires_in": 3600,
  "nonce": "optional_nonce_value",
  "user": {
    "id": "42",
    "bound_user_id": "105",
    "bound_username": "jdoe_external",
    "name": "John Doe",
    "email": "jdoe@bayawancity.gov.ph",
    "email_verified_at": "2026-09-15T08:30:00+08:00",
    "profile_photo_url": "http://login_portal.local/storage/profile-photos/jdoe.jpg"
  }
}
```

### Security Actions:
- Authorization code is **invalidated immediately** (`used_at = now()`).
- Replay attempts return HTTP 400 with `'invalid_grant'`.
- Client `last_used_at` is updated.
- Audit event `sso_token_exchanged` is recorded.

---

## 6. User Account Binding Flow

Account binding links the LGUNET Portal identity (`user_id`) to the target application's local user account (`external_user_id`).

### Accessible via:
1. **Account Settings**: Profile > SSO Applications (`/user/profile`)
2. **Connected Systems**: `/connected-systems` or `/settings/sso`

### Secure Binding Process:
1. User clicks **[Bind Account]**.
2. A secure modal prompts the user for:
   - **LGUNET Portal Password**: Authenticates that the current session owner is performing the binding.
   - **Client Username / Email**: User identifier in the target application.
   - **Client Password**: Authenticates ownership of the external account.
3. LGUNET Portal calls the client's verification endpoint:
   `POST {api_url}/api/sso/verify-credentials`
   sending `username`, `password`, and `client_secret`.
4. Client application authenticates the credentials against its local database and returns:
   ```json
   {
     "success": true,
     "user": {
       "id": "105",
       "username": "jdoe_external",
       "email": "jdoe@bayawancity.gov.ph"
     }
   }
   ```
5. LGUNET Portal records the binding in `sso_user_bindings` with `is_verified = true`.
6. **Passwords are discarded immediately** and never stored.

---

## 7. Example SSO Client Integration (Laravel)

To integrate a new Laravel application as an SSO Client while preserving existing local users and password authentication:

### Step 1: Environment Variables (`.env`)
Store your client credentials securely using environment variables. Never hardcode credentials into source code:
```env
LOGIN_PORTAL_URL=https://your-login-portal.example
LOGIN_PORTAL_CLIENT_ID=your_client_id
LOGIN_PORTAL_CLIENT_SECRET=your_client_secret
LOGIN_PORTAL_REDIRECT_URI=https://your-app.example/sso/callback
```

### Step 2: Configuration (`config/services.php`)
Expose the environment variables in `config/services.php`:
```php
'login_portal' => [
    'url' => env('LOGIN_PORTAL_URL', 'https://your-login-portal.example'),
    'client_id' => env('LOGIN_PORTAL_CLIENT_ID'),
    'client_secret' => env('LOGIN_PORTAL_CLIENT_SECRET'),
    'redirect_uri' => env('LOGIN_PORTAL_REDIRECT_URI'),
],
```

### Step 3: Identity Columns Migration
Generate a migration to add `login_portal_user_id` and `sso_provider` to the local `users` table:
```bash
php artisan make:migration add_sso_columns_to_users_table --table=users
```
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->unsignedBigInteger('login_portal_user_id')->nullable()->unique()->after('id');
        $table->string('sso_provider')->nullable()->default('login_portal')->after('login_portal_user_id');
    });
}
```

### Step 4: Routes (`routes/web.php`)
Register the redirection and callback routes:
```php
use App\Http\Controllers\Auth\SsoClientController;

Route::middleware('guest')->group(function () {
    Route::get('/sso/redirect', [SsoClientController::class, 'redirect'])->name('sso.redirect');
    Route::get('/sso/callback', [SsoClientController::class, 'callback'])->name('sso.callback');
});
```

### Step 5: Controller (`app/Http/Controllers/Auth/SsoClientController.php`)
```php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        // 3. Backchannel Server-to-Server Token Exchange
        try {
            $tokenUrl = rtrim(config('services.login_portal.url'), '/') . '/api/sso/token';
            $response = Http::asForm()->timeout(15)->post($tokenUrl, [
                'grant_type' => 'authorization_code',
                'client_id' => config('services.login_portal.client_id'),
                'client_secret' => config('services.login_portal.client_secret'),
                'redirect_uri' => config('services.login_portal.redirect_uri'),
                'code' => $code,
            ]);
        } catch (\Exception $e) {
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
                // Safely link existing local account to Login Portal identity
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

        // 5. Establish Local Session & Regenerate Session ID (Fixation Protection)
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}
```

### Step 6: UI Integration ("Login with LGUNET Portal" Button)
Add the SSO login option to your application's login template while strictly preserving the existing username/password form:
```html
<!-- LGUNET Portal Single Sign-On Button -->
<div class="mb-4">
    <a href="{{ route('sso.redirect') }}" class="w-full inline-flex justify-center items-center px-4 py-2.5 bg-[#1b4332] hover:bg-[#081c15] text-white font-bold text-sm rounded-xl shadow-xs transition">
        <span>Login with LGUNET Portal</span>
    </a>
    <div class="relative flex py-4 items-center">
        <div class="flex-grow border-t border-gray-300"></div>
        <span class="flex-shrink mx-4 text-xs font-semibold text-gray-500 uppercase tracking-wider">or sign in with credentials</span>
        <div class="flex-grow border-t border-gray-300"></div>
    </div>
</div>
```

### Step 7: Automated Feature Testing (Mocked HTTP)
Test your SSO implementation using `Http::fake()` without depending on a live Login Portal server:
```php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

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
}
```

---

## 8. Audit Logging & Security Checklist

### Audit Events Logged by LGUNET Portal:
| Event | Log Key | Details Logged |
|---|---|---|
| Client Created | `created_sso_client` | client_id, name, framework |
| Client Updated | `updated_sso_client` | client_id, name, framework |
| Secret Rotated | `regenerated_sso_secret` | client_id, name |
| Client Enabled | `enabled_sso_client` | client_id |
| Client Disabled | `disabled_sso_client` | client_id |
| Client Deleted | `deleted_sso_client` | client_id, name |
| Code Issued | `sso_authorization_code_issued` | client_id, redirect_uri, has_pkce |
| Token Exchanged | `sso_token_exchanged` | client_id, user_id, bound_user_id |
| Replay Attempt | `replayed_authorization_code` | client_id, code_id |
| Expired Code Attempt | `expired_authorization_code` | client_id, code_id |
| URI Mismatch | `sso_redirect_uri_mismatch` | client_id, attempted_redirect_uri |
| Binding Created | `sso_user_binding_created` | client_id, external_username |
| Binding Removed | `sso_user_binding_removed` | client_id |

### Production Readiness & Security Standards:
1. **HTTPS / TLS Mandatory**: All production redirects and backchannel API endpoints MUST use HTTPS encryption.
2. **CSRF State Validation**: Generate a cryptographically secure random `state` (e.g. `Str::random(40)` or `random_bytes(32)`), store it in protected server session, compare using constant-time `hash_equals()`, and invalidate it immediately upon return.
3. **Server-Side Secret Protection**: Client secrets must NEVER be committed to Git, exposed in frontend JavaScript bundles, or passed via browser query parameters. Token exchange must occur server-to-server.
4. **Safe User Mapping**: Match returning users by `login_portal_user_id` first, fallback to verified email. Never automatically merge unverified accounts.
5. **Session Fixation Defense**: Always regenerate the local session identifier (`$request->session()->regenerate()`) immediately after authenticating the local user.
6. **Credential Redaction in Logs**: Ensure access tokens, client secrets, authorization codes, and passwords are fully redacted from server and application logs.
7. **Mocked Automated Tests**: Automated CI/CD test suites must mock Login Portal responses (e.g. `Http::fake()`, `nock`, `WireMock`) and never depend on a live external server.
8. **Preserve Local Authentication**: SSO authentication must complement, rather than arbitrarily disable, existing local username and password authentication mechanisms.

