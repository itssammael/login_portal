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
  "client_id": "client_7f2b1c4e9a0d3f81",
  "client_secret": "secure_client_secret_string_here",
  "code": "40_character_authorization_code_here",
  "redirect_uri": "http://gisportal.local/sso/callback",
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

To integrate a new Laravel application (e.g., `TeamTracker` or `BudgetSys`) as an SSO Client:

### Step 1: Environment Variables (`.env`)
```env
LOGIN_PORTAL_URL=http://localhost:8000
LOGIN_PORTAL_CLIENT_ID=teamtracker-client-id
LOGIN_PORTAL_CLIENT_SECRET=teamtracker-secret-64-bytes-random-string-secure-key
LOGIN_PORTAL_REDIRECT_URI="${APP_URL}/sso/callback"
```

### Step 2: Configuration (`config/services.php`)
```php
'login_portal' => [
    'url' => env('LOGIN_PORTAL_URL', 'http://127.0.0.1:8000'),
    'client_id' => env('LOGIN_PORTAL_CLIENT_ID'),
    'client_secret' => env('LOGIN_PORTAL_CLIENT_SECRET'),
    'redirect_uri' => env('LOGIN_PORTAL_REDIRECT_URI'),
],
```

### Step 3: Routes (`routes/web.php` & `routes/api.php`)
```php
// routes/web.php
use App\Http\Controllers\Auth\SsoClientController;

Route::get('/sso/redirect', [SsoClientController::class, 'redirect'])->name('sso.redirect');
Route::get('/sso/callback', [SsoClientController::class, 'callback'])->name('sso.callback');

// routes/api.php (For Account Binding Verification)
Route::post('/sso/verify-credentials', function (Request $request) {
    if ($request->input('client_secret') !== config('services.login_portal.client_secret')) {
        return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
    }

    $username = $request->input('username');
    $password = $request->input('password');

    $userQuery = \App\Models\User::query();
    if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'username')) {
        $userQuery->where(function ($q) use ($username) {
            $q->where('email', $username)->orWhere('username', $username);
        });
    } else {
        $userQuery->where('email', $username);
    }
    $user = $userQuery->first();

    if (! $user || ! \Illuminate\Support\Facades\Hash::check($password, $user->password)) {
        return response()->json(['success' => false, 'message' => 'Invalid credentials'], 400);
    }

    return response()->json([
        'success' => true,
        'user' => [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => \Illuminate\Support\Facades\Schema::hasColumn('users', 'username') ? ($user->username ?? $user->email) : $user->email,
        ],
    ]);
});
```

### Step 4: Controller (`app/Http/Controllers/Auth/SsoClientController.php`)
```php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SsoClientController extends Controller
{
    public function redirect()
    {
        $state = Str::random(40);
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        session([
            'sso_state' => $state,
            'sso_code_verifier' => $codeVerifier,
        ]);

        $params = http_build_query([
            'client_id' => config('services.login_portal.client_id'),
            'redirect_uri' => config('services.login_portal.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect()->away(config('services.login_portal.url').'/sso/authorize?'.$params);
    }

    public function callback(Request $request)
    {
        $sessionState = session()->pull('sso_state');
        $codeVerifier = session()->pull('sso_code_verifier');

        if (! $request->state || ! hash_equals($sessionState ?? '', $request->state)) {
            return redirect()->route('login')->withErrors(['email' => 'Invalid state parameter.']);
        }

        $response = Http::asForm()->post(config('services.login_portal.url').'/sso/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.login_portal.client_id'),
            'client_secret' => config('services.login_portal.client_secret'),
            'code' => $request->code,
            'redirect_uri' => config('services.login_portal.redirect_uri'),
            'code_verifier' => $codeVerifier,
        ]);

        if (! $response->successful()) {
            return redirect()->route('login')->withErrors(['email' => 'SSO Authentication failed.']);
        }

        $ssoData = $response->json('user');
        
        // Find local user by bound user id or email
        $user = User::where('id', $ssoData['bound_user_id'] ?? null)
            ->orWhere('email', $ssoData['email'])
            ->first();

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'No local account found linked to this identity.']);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}
```

### Step 5: "Login with LGUNET Portal" Button
In client's login view:
```html
<a href="{{ route('sso.redirect') }}" class="btn btn-primary">
    <img src="/assets/imgs/lgunet-logo.png" class="size-5" />
    <span>Login with LGUNET Portal</span>
</a>
```

---

## 8. Audit Logging & Security Checklist

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
