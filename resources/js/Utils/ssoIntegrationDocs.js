/**
 * SSO Step-by-Step Integration Documentation Generator & PDF Exporter
 * Provides customized integration guides for each framework/language.
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
        description: 'Standalone PHP application using cURL, native sessions, and JavaScript / jQuery.',
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
        name: 'Vue.js / React (SPA)',
        shortName: 'Vue / React SPA',
        icon: '⚛️',
        badgeColor: 'bg-sky-100 text-sky-800 border-sky-200',
        description: 'Client-side Single Page Application with REST API backend token exchange.',
    },
    {
        key: 'generic_rest',
        name: 'Generic REST API / Python / Other',
        shortName: 'REST API / Python',
        icon: '🛠️',
        badgeColor: 'bg-gray-100 text-gray-800 border-gray-300',
        description: 'Language-agnostic OAuth 2.0 Authorization Code flow for any web service or API.',
    },
];

export function getFrameworkMeta(key) {
    return FRAMEWORK_OPTIONS.find(f => f.key === key) || FRAMEWORK_OPTIONS[0];
}

/**
 * Build customized integration steps for a client and selected framework.
 */
function buildGuideContent({
    clientName = 'My Application',
    clientId = 'client_sample_id',
    clientSecret = 'sample_client_secret',
    redirectUri = 'http://localhost:8001/sso/callback',
    frameworkKey = 'laravel_inertia',
    portalUrl = 'http://localhost:8000',
    meta,
}) {
    switch (frameworkKey) {
        case 'laravel_inertia':
            return {
                title: `${clientName} — SSO Integration Guide for Laravel + Jetstream + Inertia (Vue.js)`,
                framework: meta,
                overview: 'This guide walks through configuring your Laravel + Inertia application to authenticate users centrally via Login Portal Single Sign-On (SSO) while preserving your existing local database and session.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Configure Environment Variables (.env)',
                        description: 'Add your registered SSO client credentials to your client application’s .env file:',
                        filename: '.env',
                        language: 'bash',
                        code: `LOGIN_PORTAL_URL=${portalUrl}
LOGIN_PORTAL_CLIENT_ID=${clientId}
LOGIN_PORTAL_CLIENT_SECRET=${clientSecret}
LOGIN_PORTAL_REDIRECT_URI=${redirectUri}`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Create Identity Mapping Migration',
                        description: 'Generate a migration in your application to link local users with Login Portal identities:',
                        filename: 'terminal',
                        language: 'bash',
                        code: `php artisan make:migration add_sso_columns_to_users_table --table=users`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Update Identity Columns in Migration',
                        description: 'Add login_portal_user_id to the users table:',
                        filename: 'database/migrations/xxxx_xx_xx_add_sso_columns_to_users_table.php',
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
                        stepNumber: 4,
                        title: 'Register SSO Routes',
                        description: 'Add redirection and callback routes in your client web.php file:',
                        filename: 'routes/web.php',
                        language: 'php',
                        code: `use App\\Http\\Controllers\\SsoClientController;

Route::get('/sso/redirect', [SsoClientController::class, 'redirect'])->name('sso.redirect');
Route::get('/sso/callback', [SsoClientController::class, 'callback'])->name('sso.callback');`,
                    },
                    {
                        stepNumber: 5,
                        title: 'Create SsoClientController',
                        description: 'Implement the authorization redirection and token validation logic:',
                        filename: 'app/Http/Controllers/SsoClientController.php',
                        language: 'php',
                        code: `<?php

namespace App\\Http\\Controllers;

use App\\Models\\User;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Http;
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

        return redirect(config('services.login_portal.url') . '/sso/authorize?' . $query);
    }

    public function callback(Request $request)
    {
        $state = $request->query('state');
        if (! $state || $state !== $request->session()->pull('sso_state')) {
            return redirect()->route('login')->withErrors(['email' => 'Invalid SSO state token.']);
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect()->route('login')->withErrors(['email' => 'Authorization code missing.']);
        }

        // Exchange code for user identity with Login Portal
        $response = Http::asForm()->post(config('services.login_portal.url') . '/api/sso/token', [
            'client_id' => config('services.login_portal.client_id'),
            'client_secret' => config('services.login_portal.client_secret'),
            'redirect_uri' => config('services.login_portal.redirect_uri'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            return redirect()->route('login')->withErrors(['email' => 'SSO authentication failed.']);
        }

        $userData = $response->json('user');
        
        // Match user by login_portal_user_id or fallback to existing email
        $user = User::where('login_portal_user_id', $userData['id'])
            ->orWhere('email', $userData['email'])
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'login_portal_user_id' => $userData['id'],
                'password' => bcrypt(Str::random(32)),
            ]);
        } else {
            $user->update(['login_portal_user_id' => $userData['id']]);
        }

        Auth::login($user, true);

        return redirect()->intended('/dashboard');
    }
}`,
                    },
                    {
                        stepNumber: 6,
                        title: 'Insert Button [Login with LGUNET Portal] into Login Form/Page',
                        description: 'Add the native SSO login button on your resources/js/Pages/Auth/Login.vue template:',
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
                ],
            };

        case 'laravel_livewire':
        case 'laravel_blade':
            return {
                title: `${clientName} — SSO Integration Guide for ${meta.name}`,
                framework: meta,
                overview: 'Integrate Login Portal Single Sign-On into your server-rendered Laravel application with Blade views.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Add Environment Keys (.env)',
                        description: 'Configure your credentials in .env and config/services.php:',
                        filename: '.env',
                        language: 'bash',
                        code: `LOGIN_PORTAL_URL=${portalUrl}
LOGIN_PORTAL_CLIENT_ID=${clientId}
LOGIN_PORTAL_CLIENT_SECRET=${clientSecret}
LOGIN_PORTAL_REDIRECT_URI=${redirectUri}`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Register Service Configuration',
                        description: 'Add the login_portal driver to config/services.php:',
                        filename: 'config/services.php',
                        language: 'php',
                        code: `'login_portal' => [
    'url' => env('LOGIN_PORTAL_URL', '${portalUrl}'),
    'client_id' => env('LOGIN_PORTAL_CLIENT_ID', '${clientId}'),
    'client_secret' => env('LOGIN_PORTAL_CLIENT_SECRET', '${clientSecret}'),
    'redirect_uri' => env('LOGIN_PORTAL_REDIRECT_URI', '${redirectUri}'),
],`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Add SSO Routes & Controller',
                        description: 'Register the /sso/redirect and /sso/callback routes in routes/web.php:',
                        filename: 'routes/web.php',
                        language: 'php',
                        code: `Route::get('/sso/redirect', [App\\Http\\Controllers\\SsoController::class, 'redirect'])->name('sso.redirect');
Route::get('/sso/callback', [App\\Http\\Controllers\\SsoController::class, 'callback'])->name('sso.callback');`,
                    },
                    {
                        stepNumber: 4,
                        title: 'Insert Button [Login with LGUNET Portal] into Login Form/Page',
                        description: 'Add the SSO login button into resources/views/auth/login.blade.php:',
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
                ],
            };

        case 'php_vanilla':
            return {
                title: `${clientName} — SSO Integration Guide for PHP + Vanilla JS / jQuery`,
                framework: meta,
                overview: 'Direct PHP implementation using cURL to exchange short-lived SSO codes for authenticated user sessions.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Create SSO Configuration File',
                        description: 'Store client credentials in sso_config.php:',
                        filename: 'sso_config.php',
                        language: 'php',
                        code: `<?php
define('SSO_PORTAL_URL', '${portalUrl}');
define('SSO_CLIENT_ID', '${clientId}');
define('SSO_CLIENT_SECRET', '${clientSecret}');
define('SSO_REDIRECT_URI', '${redirectUri}');
?>`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Create sso_redirect.php',
                        description: 'Initiates SSO flow with secure state token:',
                        filename: 'sso_redirect.php',
                        language: 'php',
                        code: `<?php
require_once 'sso_config.php';
session_start();

$state = bin2hex(random_bytes(20));
$_SESSION['sso_state'] = $state;

$params = [
    'client_id' => SSO_CLIENT_ID,
    'redirect_uri' => SSO_REDIRECT_URI,
    'response_type' => 'code',
    'state' => $state
];

header('Location: ' . SSO_PORTAL_URL . '/sso/authorize?' . http_build_query($params));
exit;
?>`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Create Callback Handler (sso/callback.php)',
                        description: 'Exchanges authorization code for verified user profile via cURL:',
                        filename: 'sso_callback.php',
                        language: 'php',
                        code: `<?php
require_once 'sso_config.php';
session_start();

if (!isset($_GET['code']) || !isset($_GET['state']) || $_GET['state'] !== $_SESSION['sso_state']) {
    die('Invalid SSO state or authorization code missing.');
}

$ch = curl_init(SSO_PORTAL_URL . '/api/sso/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id' => SSO_CLIENT_ID,
    'client_secret' => SSO_CLIENT_SECRET,
    'redirect_uri' => SSO_REDIRECT_URI,
    'code' => $_GET['code']
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!isset($response['user'])) {
    die('SSO Verification Failed.');
}

// Set local authenticated session
$_SESSION['auth_user'] = $response['user'];
header('Location: /dashboard.php');
exit;
?>`,
                    },
                    {
                        stepNumber: 4,
                        title: 'Insert Button [Login with LGUNET Portal] into Login Form/Page',
                        description: 'Add the SSO login button into your HTML/PHP login view template (e.g. login.php):',
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

        case 'nuxt_node':
            return {
                title: `${clientName} — SSO Integration Guide for Nuxt.js / Node.js`,
                framework: meta,
                overview: 'Server-side route handler in Nuxt 3 Nitro server for Login Portal OAuth 2.0 authorization code flow.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Nuxt Runtime Config (nuxt.config.ts)',
                        description: 'Define SSO credentials in your Nuxt runtime configuration:',
                        filename: 'nuxt.config.ts',
                        language: 'typescript',
                        code: `export default defineNuxtConfig({
  runtimeConfig: {
    ssoPortalUrl: process.env.LOGIN_PORTAL_URL || '${portalUrl}',
    ssoClientId: process.env.LOGIN_PORTAL_CLIENT_ID || '${clientId}',
    ssoClientSecret: process.env.LOGIN_PORTAL_CLIENT_SECRET || '${clientSecret}',
    ssoRedirectUri: process.env.LOGIN_PORTAL_REDIRECT_URI || '${redirectUri}',
  }
})`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Server Callback Route (server/routes/sso/callback.ts)',
                        description: 'Create the server-side callback to exchange code for session:',
                        filename: 'server/routes/sso/callback.ts',
                        language: 'typescript',
                        code: `export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const config = useRuntimeConfig()

  if (!query.code) {
    return sendRedirect(event, '/login?error=sso_failed')
  }

  const tokenData = await $fetch(\`\${config.ssoPortalUrl}/api/sso/token\`, {
    method: 'POST',
    body: {
      client_id: config.ssoClientId,
      client_secret: config.ssoClientSecret,
      redirect_uri: config.ssoRedirectUri,
      code: query.code
    }
  })

  // Set user cookie / session
  setCookie(event, 'auth_user', JSON.stringify(tokenData.user), { httpOnly: true, secure: true })
  return sendRedirect(event, '/dashboard')
})`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Insert Button [Login with LGUNET Portal] into Login Form/Page',
                        description: 'Add the SSO login button into your Nuxt login page component (e.g. pages/login.vue):',
                        filename: 'pages/login.vue',
                        language: 'html',
                        code: `<!-- LGUNET Portal SSO Login Button -->
<template>
  <div class="sso-auth-container mb-4">
    <a
      :href="\`\${ssoPortalUrl}/sso/authorize?client_id=\${ssoClientId}&redirect_uri=\${encodeURIComponent(ssoRedirectUri)}&response_type=code&state=\${ssoState}\`"
      class="w-full flex items-center justify-center space-x-2 py-3 px-4 bg-[#1b4332] hover:bg-[#081c15] text-white font-bold text-sm rounded-xl shadow-xs transition duration-150"
    >
      <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
      </svg>
      <span>Login with LGUNET Portal</span>
    </a>
  </div>
</template>`,
                    },
                ],
            };

        case 'vue_spa':
            return {
                title: `${clientName} — SSO Integration Guide for Vue.js / React (SPA)`,
                framework: meta,
                overview: 'Client-side Single Page Application OAuth 2.0 authorization code redirect and token reception.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Configure Environment & Redirect Helper',
                        description: 'Create an SSO helper function in src/services/sso.js:',
                        filename: 'src/services/sso.js',
                        language: 'javascript',
                        code: `export function redirectToLoginPortal() {
  const state = Math.random().toString(36).substring(2, 15);
  sessionStorage.setItem('sso_state', state);

  const params = new URLSearchParams({
    client_id: '${clientId}',
    redirect_uri: '${redirectUri}',
    response_type: 'code',
    state: state,
  });

  window.location.href = '${portalUrl}/sso/authorize?' + params.toString();
}`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Handle Callback in Route Component',
                        description: 'Exchange the returned authorization code via your backend API proxy in src/views/SsoCallback.vue:',
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

  if (!code || state !== savedState) {
    router.push('/login?error=invalid_sso_state');
    return;
  }

  // Forward authorization code to your backend service to securely exchange for token
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
                        stepNumber: 3,
                        title: 'Insert Button [Login with LGUNET Portal] into Login Form/Page',
                        description: 'Add the SSO login button into your SPA login component (e.g. src/views/Login.vue):',
                        filename: 'src/views/Login.vue',
                        language: 'html',
                        code: `<!-- LGUNET Portal SSO Login Button -->
<div class="mb-4">
    <button
        type="button"
        @click="redirectToLoginPortal"
        class="w-full flex items-center justify-center space-x-2 py-3 px-4 bg-[#1b4332] hover:bg-[#081c15] text-white font-bold text-sm rounded-xl shadow-xs transition"
    >
        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
        </svg>
        <span>Login with LGUNET Portal</span>
    </button>
</div>`,
                    },
                ],
            };

        case 'generic_rest':
        default:
            return {
                title: `${clientName} — SSO Integration Guide (${meta.name})`,
                framework: meta,
                overview: 'Standard OAuth 2.0 / OpenID Connect authorization code flow endpoints and payloads.',
                steps: [
                    {
                        stepNumber: 1,
                        title: 'Authorize Endpoint URL',
                        description: 'Direct users to this URL to initiate authentication:',
                        filename: 'HTTP GET Request',
                        language: 'bash',
                        code: `${portalUrl}/sso/authorize?client_id=${clientId}&redirect_uri=${encodeURIComponent(redirectUri)}&response_type=code&state=RANDOM_SECURE_TOKEN`,
                    },
                    {
                        stepNumber: 2,
                        title: 'Token Exchange Endpoint',
                        description: 'Exchange the returned authorization code for user identity on your backend:',
                        filename: 'HTTP POST Request',
                        language: 'bash',
                        code: `POST ${portalUrl}/api/sso/token
Content-Type: application/x-www-form-urlencoded

client_id=${clientId}&client_secret=${clientSecret}&redirect_uri=${encodeURIComponent(redirectUri)}&code=RECEIVED_AUTH_CODE`,
                    },
                    {
                        stepNumber: 3,
                        title: 'Expected JSON Response Payload',
                        description: 'Upon successful validation, Login Portal returns the authenticated user payload:',
                        filename: 'HTTP Response (200 OK)',
                        language: 'json',
                        code: `{
  "token": "sso_auth_bearer_token...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": 1,
    "name": "Admin User",
    "email": "user@example.com",
    "role": "admin"
  }
}`,
                    },
                    {
                        stepNumber: 4,
                        title: 'Insert Button [Login with LGUNET Portal] into Login Form/Page',
                        description: 'Add the SSO login button or link into your application’s login template:',
                        filename: 'login.html',
                        language: 'html',
                        code: `<!-- LGUNET Portal Single Sign-On Button -->
<div class="sso-container" style="margin-bottom: 20px;">
    <a
        href="${portalUrl}/sso/authorize?client_id=${clientId}&redirect_uri=${encodeURIComponent(redirectUri)}&response_type=code&state=RANDOM_SECURE_TOKEN"
        style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 12px 20px; background-color: #1b4332; color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px; border-radius: 8px;"
    >
        <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
        </svg>
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
    clientId = 'client_sample_id',
    clientSecret = 'sample_client_secret',
    redirectUri = 'http://localhost:8001/sso/callback',
    portalUrl = window?.location?.origin || 'http://localhost:8000',
} = {}) {
    const meta = getFrameworkMeta(frameworkKey);

    switch (frameworkKey) {
        case 'laravel_inertia':
            return `Task: Integrate Single Sign-On (SSO) with Central Login Portal
Target Application: ${clientName}
Tech Stack: Laravel + Jetstream + Inertia.js (Vue 3)

You are an expert full-stack Laravel & Inertia.js engineer. Integrate OAuth 2.0 Single Sign-On (SSO) into this repository so users can sign in using the central Login Portal while preserving existing local users and session authentication.

### SSO Credentials & Endpoints
- Login Portal Base URL: ${portalUrl}
- Client ID: ${clientId}
- Client Secret: ${clientSecret}
- Redirect URI: ${redirectUri}
- Authorization Endpoint: ${portalUrl}/sso/authorize
- Token Exchange Endpoint: ${portalUrl}/api/sso/token
- User Profile API: ${portalUrl}/api/user

### Implementation Instructions

1. Environment & Config:
   In .env, add:
   LOGIN_PORTAL_URL=${portalUrl}
   LOGIN_PORTAL_CLIENT_ID=${clientId}
   LOGIN_PORTAL_CLIENT_SECRET=${clientSecret}
   LOGIN_PORTAL_REDIRECT_URI=${redirectUri}

   In config/services.php, add:
   'login_portal' => [
       'url' => env('LOGIN_PORTAL_URL', '${portalUrl}'),
       'client_id' => env('LOGIN_PORTAL_CLIENT_ID'),
       'client_secret' => env('LOGIN_PORTAL_CLIENT_SECRET'),
       'redirect_uri' => env('LOGIN_PORTAL_REDIRECT_URI'),
   ],

2. Migration & User Model:
   - Create migration: php artisan make:migration add_sso_columns_to_users_table --table=users
   - Add 'login_portal_user_id' (unsignedBigInteger, nullable, unique, after id).
   - Add 'sso_provider' (string, nullable, default 'login_portal', after login_portal_user_id).
   - Run \`php artisan migrate\`.
   - Add 'login_portal_user_id' and 'sso_provider' to App\\Models\\User $fillable.

3. SSO Controller (App\\Http\\Controllers\\SsoClientController):
   - redirect(Request $request):
     Generate cryptographically secure random state (Str::random(40)) and store in $request->session()->put('sso_state', $state).
     Redirect to: config('services.login_portal.url') . '/sso/authorize?' . http_build_query([
         'client_id' => config('services.login_portal.client_id'),
         'redirect_uri' => config('services.login_portal.redirect_uri'),
         'response_type' => 'code',
         'state' => $state,
     ]);
   - callback(Request $request):
     Verify $request->query('state') matches $request->session()->pull('sso_state'). If invalid, redirect to login with error.
     Retrieve $request->query('code'). If absent, redirect to login with error.
     Exchange code by calling:
     Http::asForm()->post(config('services.login_portal.url') . '/api/sso/token', [
         'client_id' => config('services.login_portal.client_id'),
         'client_secret' => config('services.login_portal.client_secret'),
         'redirect_uri' => config('services.login_portal.redirect_uri'),
         'code' => $code,
     ]);
     Extract $userData from $response->json('user').
     Find user where 'login_portal_user_id' == $userData['id'], or where 'email' == $userData['email'].
     If not found, create new User with name, email, login_portal_user_id, and bcrypt(Str::random(32)) password.
     If found, update login_portal_user_id.
     Call Auth::login($user, remember: true) and $request->session()->regenerate().
     Redirect to intended destination via redirect()->intended('/dashboard').

4. Routes:
   In routes/web.php:
   Route::get('/sso/redirect', [App\\Http\\Controllers\\SsoClientController::class, 'redirect'])->name('sso.redirect');
   Route::get('/sso/callback', [App\\Http\\Controllers\\SsoClientController::class, 'callback'])->name('sso.callback');

5. UI Button:
   In resources/js/Pages/Auth/Login.vue:
   Insert Button [Login with LGUNET Portal] into Login Form/Page linking to route('sso.redirect').

6. Automated Tests:
   Add feature test in tests/Feature/SsoIntegrationTest.php verifying redirect generation, callback validation, user creation/matching, and authenticated session state.`;

        case 'laravel_livewire':
            return `Task: Integrate Single Sign-On (SSO) with Central Login Portal
Target Application: ${clientName}
Tech Stack: Laravel + Jetstream + Livewire (Blade)

You are an expert Laravel & Livewire engineer. Integrate OAuth 2.0 Single Sign-On (SSO) into this application so users can sign in using the central Login Portal.

### SSO Credentials & Endpoints
- Login Portal Base URL: ${portalUrl}
- Client ID: ${clientId}
- Client Secret: ${clientSecret}
- Redirect URI: ${redirectUri}
- Authorization Endpoint: ${portalUrl}/sso/authorize
- Token Exchange Endpoint: ${portalUrl}/api/sso/token

### Implementation Instructions
1. In .env, define LOGIN_PORTAL_URL, LOGIN_PORTAL_CLIENT_ID, LOGIN_PORTAL_CLIENT_SECRET, LOGIN_PORTAL_REDIRECT_URI.
2. In config/services.php, register 'login_portal' array pointing to these env variables.
3. Run migration to add 'login_portal_user_id' (nullable, unique) and 'sso_provider' to 'users' table. Update User model.
4. Implement App\\Http\\Controllers\\SsoClientController with redirect() and callback() methods handling state check and Http::asForm() token exchange.
5. In routes/web.php, register /sso/redirect and /sso/callback.
6. In resources/views/auth/login.blade.php: Insert Button [Login with LGUNET Portal] into Login Form/Page linking to route('sso.redirect').
7. Add feature tests covering the SSO redirection and authentication flow.`;

        case 'laravel_blade':
            return `Task: Integrate Single Sign-On (SSO) with Central Login Portal
Target Application: ${clientName}
Tech Stack: Laravel (MVC / Blade)

You are an expert Laravel engineer. Integrate Central Login Portal Single Sign-On (SSO) into this repository.

### SSO Credentials & Endpoints
- Base URL: ${portalUrl}
- Client ID: ${clientId}
- Client Secret: ${clientSecret}
- Redirect URI: ${redirectUri}
- Authorization Endpoint: ${portalUrl}/sso/authorize
- Token Endpoint: ${portalUrl}/api/sso/token

### Instructions
1. Configure .env with LOGIN_PORTAL_URL, LOGIN_PORTAL_CLIENT_ID, LOGIN_PORTAL_CLIENT_SECRET, LOGIN_PORTAL_REDIRECT_URI.
2. Register 'login_portal' in config/services.php.
3. Add 'login_portal_user_id' (unsignedBigInteger, nullable, unique) to 'users' table.
4. Create SsoClientController handling OAuth state validation, token exchange via Http::asForm(), and Auth::login($user, true).
5. Add routes /sso/redirect and /sso/callback in routes/web.php.
6. In resources/views/auth/login.blade.php: Insert Button [Login with LGUNET Portal] into Login Form/Page linking to route('sso.redirect').`;

        case 'php_vanilla':
            return `Task: Integrate Single Sign-On (SSO) in Native PHP Application
Target Application: ${clientName}
Tech Stack: PHP + Vanilla JavaScript / jQuery

You are an expert PHP engineer. Integrate OAuth 2.0 Single Sign-On (SSO) into this native PHP project using standard PHP sessions and cURL.

### SSO Configuration
- SSO Portal URL: ${portalUrl}
- Client ID: ${clientId}
- Client Secret: ${clientSecret}
- Redirect URI: ${redirectUri}

### Instructions
1. In config.php:
   Define SSO_PORTAL_URL, SSO_CLIENT_ID, SSO_CLIENT_SECRET, SSO_REDIRECT_URI.
2. In sso-login.php:
   Initialize session with session_start().
   Generate random state bin2hex(random_bytes(20)) and store in $_SESSION['sso_state'].
   Redirect browser to: SSO_PORTAL_URL . '/sso/authorize?client_id=' . SSO_CLIENT_ID . '&redirect_uri=' . urlencode(SSO_REDIRECT_URI) . '&response_type=code&state=' . $_SESSION['sso_state'].
3. In sso-callback.php:
   session_start().
   Validate $_GET['state'] === $_SESSION['sso_state'].
   Read $_GET['code'] and execute cURL POST request to SSO_PORTAL_URL . '/api/sso/token' with client_id, client_secret, redirect_uri, and code.
   Decode JSON response, set $_SESSION['user'] = $response['user'], $_SESSION['access_token'] = $response['access_token'].
   Redirect browser to index.php or protected dashboard.
4. In login.php: Insert Button [Login with LGUNET Portal] into Login Form/Page linking to sso-login.php.`;

        case 'nuxt_node':
            return `Task: Integrate Single Sign-On (SSO) in Nuxt 3 / Node.js
Target Application: ${clientName}
Tech Stack: Nuxt 3 + Nitro Server (Node.js)

You are an expert Nuxt 3 & Node.js full-stack engineer. Implement OAuth 2.0 Single Sign-On (SSO) into this Nuxt 3 application.

### SSO Configuration
- Login Portal URL: ${portalUrl}
- Client ID: ${clientId}
- Client Secret: ${clientSecret}
- Redirect URI: ${redirectUri}

### Instructions
1. Add environment variables to .env:
   NUXT_LOGIN_PORTAL_URL=${portalUrl}
   NUXT_LOGIN_PORTAL_CLIENT_ID=${clientId}
   NUXT_LOGIN_PORTAL_CLIENT_SECRET=${clientSecret}
   NUXT_LOGIN_PORTAL_REDIRECT_URI=${redirectUri}
2. Configure runtimeConfig in nuxt.config.ts for server secret and public client ID & URL.
3. Create server endpoint /server/api/auth/sso/login.get.ts to generate random state, store in secure cookie, and redirect to \${url}/sso/authorize.
4. Create server endpoint /server/api/auth/sso/callback.get.ts to verify state cookie, exchange code via $fetch('\${url}/api/sso/token'), store authenticated user in session cookie, and redirect to '/dashboard'.
5. In pages/login.vue: Insert Button [Login with LGUNET Portal] into Login Form/Page.`;

        case 'vue_spa':
            return `Task: Integrate Single Sign-On (SSO) in Vue.js / React Single Page Application (SPA)
Target Application: ${clientName}
Tech Stack: Vue.js / React (Client-Side SPA)

You are an expert frontend engineer. Implement OAuth 2.0 Authorization Code Single Sign-On (SSO) in this SPA application.

### SSO Configuration
- Login Portal URL: ${portalUrl}
- Client ID: ${clientId}
- Redirect URI: ${redirectUri}
- Authorization Endpoint: ${portalUrl}/sso/authorize
- Backend Token Endpoint: ${portalUrl}/api/sso/token

### Instructions
1. Define VITE_LOGIN_PORTAL_URL=${portalUrl}, VITE_LOGIN_PORTAL_CLIENT_ID=${clientId}, VITE_LOGIN_PORTAL_REDIRECT_URI=${redirectUri} in .env.local.
2. On login click, generate random UUID state in sessionStorage and redirect window.location to:
   \${portalUrl}/sso/authorize?client_id=${clientId}&redirect_uri=\${encodeURIComponent('${redirectUri}')}&response_type=code&state=\${state}
3. Register router route for callback (e.g. /sso/callback).
   In callback component onMounted:
   - Check 'state' param against sessionStorage.
   - Extract 'code' parameter from route query.
   - Send code to your application backend API (or direct token endpoint) to exchange for access token and user payload.
   - Store bearer token and user object in Pinia / Redux store and localStorage.
   - Redirect to authenticated dashboard.
4. In src/views/Login.vue: Insert Button [Login with LGUNET Portal] into Login Form/Page.`;

        default:
            return `Task: Implement OAuth 2.0 Single Sign-On (SSO) Integration
Target Application: ${clientName}
Tech Stack: ${meta.name}

You are an expert software engineer. Implement OAuth 2.0 Authorization Code flow Single Sign-On (SSO) using the central Login Portal.

### SSO Credentials & Endpoints
- Login Portal Base URL: ${portalUrl}
- Client ID: ${clientId}
- Client Secret: ${clientSecret}
- Redirect URI: ${redirectUri}
- Authorize Endpoint: ${portalUrl}/sso/authorize
- Token Endpoint: ${portalUrl}/api/sso/token
- User Profile Endpoint: ${portalUrl}/api/user

### Instructions
1. Step 1 (Authorize): Redirect user's browser to:
   ${portalUrl}/sso/authorize?client_id=${clientId}&redirect_uri=${encodeURIComponent(redirectUri)}&response_type=code&state={random_csrf_token}
2. Step 2 (Token Exchange): When redirected to ${redirectUri}?code={code}&state={state}:
   Verify state token. Send HTTP POST request to:
   POST ${portalUrl}/api/sso/token
   Content-Type: application/x-www-form-urlencoded
   client_id=${clientId}&client_secret=${clientSecret}&redirect_uri=${encodeURIComponent(redirectUri)}&code={code}
3. Step 3 (User Session): Parse JSON response containing user identity:
   Store access token and authenticated user profile in your local session or database.
4. Step 4 (UI Button): In your login form/page:
   Insert Button [Login with LGUNET Portal] into Login Form/Page pointing to the authorization redirect URL.`;
    }
}

/**
 * Main entry point: build complete guide data with steps, metadata, and AI Agent prompt.
 */
export function getIntegrationGuide({
    clientName = 'My Application',
    clientId = 'client_sample_id',
    clientSecret = 'sample_client_secret',
    redirectUri = 'http://localhost:8001/sso/callback',
    frameworkKey = 'laravel_inertia',
    portalUrl = window?.location?.origin || 'http://localhost:8000',
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
        clientId: docData.clientId || 'client_sample_id',
        clientSecret: docData.clientSecret || 'sample_client_secret',
        redirectUri: docData.redirectUri || 'http://localhost:8001/sso/callback',
        portalUrl: docData.portalUrl || window?.location?.origin || 'http://localhost:8000',
    });

    const portalUrl = docData.portalUrl || window?.location?.origin || 'http://localhost:8000';
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
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e293b;
            background: #f8fafc;
            line-height: 1.55;
            padding: 24px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .page-container {
            max-width: 860px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            border: 1px solid #e2e8f0;
            overflow: hidden;
            padding: 36px 42px;
        }
        .action-bar {
            background: #1b4332;
            color: #ffffff;
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(27, 67, 50, 0.2);
        }
        .action-bar-title {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.3px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .action-btns {
            display: flex;
            gap: 10px;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            font-size: 12px;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
        }
        .btn-primary {
            background: #d4a373;
            color: #1b4332;
        }
        .btn-primary:hover {
            background: #e9c496;
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.25);
        }
        .header {
            border-bottom: 3px solid #1b4332;
            padding-bottom: 20px;
            margin-bottom: 24px;
            position: relative;
        }
        .header-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }
        .header-org {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #1b4332;
            margin-bottom: 4px;
        }
        h1 {
            font-size: 23px;
            font-weight: 800;
            color: #081c15;
            line-height: 1.25;
            margin-bottom: 6px;
        }
        .header-subtitle {
            font-size: 13px;
            color: #475569;
            margin-bottom: 12px;
        }
        .meta-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 6px;
            background: #e8f5e9;
            color: #1b4332;
            border: 1px solid #c8e6c9;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-navy {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #cbd5e1;
        }
        .badge-gold {
            background: #fef3c7;
            color: #92400e;
            border-color: #fde68a;
        }
        .overview-box {
            background: #fdfbf7;
            border: 1px solid #e7dfd5;
            border-left: 4px solid #1b4332;
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 24px;
            font-size: 13.5px;
            color: #334155;
            line-height: 1.55;
        }
        .specs-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 28px;
        }
        .specs-header {
            background: #f1f5f9;
            padding: 10px 16px;
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0f172a;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .specs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .specs-table tr {
            border-bottom: 1px solid #f1f5f9;
        }
        .specs-table tr:nth-child(even) {
            background: #f8fafc;
        }
        .specs-table tr:last-child {
            border-bottom: none;
        }
        .specs-table td {
            padding: 8px 14px;
            vertical-align: middle;
        }
        .specs-key {
            font-weight: 700;
            color: #475569;
            width: 22%;
            white-space: nowrap;
        }
        .specs-val {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            color: #0f172a;
            font-size: 11.5px;
            word-break: break-all;
        }
        .section-divider {
            margin-top: 32px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2.5px solid #1b4332;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .section-badge {
            background: #1b4332;
            color: #ffffff;
            font-size: 11px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 0.5px;
        }
        .section-title {
            font-size: 17px;
            font-weight: 800;
            color: #081c15;
            margin: 0;
        }
        .step-card {
            margin-bottom: 24px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 18px 20px;
            page-break-inside: avoid;
        }
        .step-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        .step-num {
            background: #1b4332;
            color: #ffffff;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 800;
            flex-shrink: 0;
        }
        .step-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
        }
        .step-desc {
            font-size: 13px;
            color: #475569;
            margin-bottom: 10px;
            line-height: 1.5;
        }
        .file-label {
            font-size: 11px;
            font-weight: 700;
            color: #1b4332;
            background: #e8f5e9;
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            margin-bottom: 8px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .code-box {
            background: #0f172a;
            color: #e2e8f0;
            padding: 14px 16px;
            border-radius: 6px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 11.5px;
            white-space: pre-wrap;
            word-break: break-all;
            line-height: 1.45;
            border: 1px solid #1e293b;
        }
        .ai-section {
            margin-top: 36px;
            page-break-before: always;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 22px;
        }
        .checklist-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 4px solid #d97706;
            border-radius: 6px;
            padding: 16px 20px;
            margin-top: 28px;
            page-break-inside: avoid;
        }
        .checklist-title {
            font-size: 13px;
            font-weight: 800;
            color: #92400e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .checklist-item {
            font-size: 12.5px;
            color: #78350f;
            margin-bottom: 6px;
            line-height: 1.45;
        }
        .footer {
            margin-top: 36px;
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
            font-size: 11px;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .page-container {
                box-shadow: none;
                border: none;
                padding: 0;
                max-width: 100%;
            }
            .no-print {
                display: none !important;
            }
            .step-card {
                page-break-inside: avoid;
            }
            .ai-section {
                page-break-before: always;
            }
            @page {
                size: A4 portrait;
                margin: 14mm 12mm 16mm 12mm;
            }
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
                        <td class="specs-val">${escapeHtml(docData.clientId || 'N/A')}</td>
                        <td class="specs-key">Authorization URL:</td>
                        <td class="specs-val">${escapeHtml(portalUrl)}/sso/authorize</td>
                    </tr>
                    <tr>
                        <td class="specs-key">Client Secret:</td>
                        <td class="specs-val">${escapeHtml(docData.clientSecret || 'N/A')}</td>
                        <td class="specs-key">Token Endpoint:</td>
                        <td class="specs-val">${escapeHtml(portalUrl)}/api/sso/token</td>
                    </tr>
                    <tr>
                        <td class="specs-key">Redirect URI:</td>
                        <td class="specs-val">${escapeHtml(docData.redirectUri || 'N/A')}</td>
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

        <!-- Security & Production Checklist -->
        <div class="checklist-box">
            <div class="checklist-title">🛡️ Security & Production Readiness Checklist</div>
            <div class="checklist-item">• <strong>HTTPS / TLS Mandatory:</strong> Production environments MUST serve all redirect URIs and API calls over encrypted HTTPS.</div>
            <div class="checklist-item">• <strong>CSRF State Verification:</strong> Always generate a unique random state token before redirecting and verify it upon callback return.</div>
            <div class="checklist-item">• <strong>Secret Protection:</strong> Never commit client_secret to source control repositories. Keep strictly inside environment files (.env).</div>
            <div class="checklist-item">• <strong>User Identity Mapping:</strong> Store login_portal_user_id in your local database to seamlessly recognize returning users.</div>
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
            // Give layout a brief moment to stabilize then prompt print dialog
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
            ['Client ID', credentials.clientId || 'N/A', 'Authorization URL', `${credentials.portalUrl}/sso/authorize`],
            ['Client Secret', credentials.clientSecret || 'N/A', 'Token Endpoint', `${credentials.portalUrl}/api/sso/token`],
            ['Redirect URI', credentials.redirectUri || 'N/A', 'User Profile Endpoint', `${credentials.portalUrl}/api/user`],
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
        this.ensureSpace(80);
        const h = 72;
        const botY = this.y - h;

        this.addRect(this.margin, botY, this.contentWidth, h, [0.98, 0.97, 0.94], [0.88, 0.82, 0.72], 1);
        this.addRect(this.margin, botY, 4, h, [0.84, 0.65, 0.25], null);

        this.addText('SECURITY & PRODUCTION READINESS CHECKLIST', this.margin + 14, this.y - 12, '/F2', 8, [0.55, 0.35, 0.1]);

        const checks = [
            '1. HTTPS Requirement: Production environments MUST serve all redirect URIs and API calls over HTTPS/TLS.',
            '2. CSRF State Token: Always generate a cryptographically secure state token before redirecting and verify on callback.',
            '3. Client Secret Storage: Never commit client_secret to git. Store strictly in environment variables (.env).',
            '4. Identity Mapping: Match returning users by login_portal_user_id; fallback to email verification for existing accounts.',
        ];

        let curY = this.y - 24;
        checks.forEach(c => {
            this.addText(c, this.margin + 14, curY, '/F1', 7.5, [0.3, 0.25, 0.15]);
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
        const portalUrl = docData.portalUrl || window?.location?.origin || 'http://localhost:8000';

        const aiPrompt = docData.aiPrompt || getAiPrompt(docData.framework?.key || 'laravel_inertia', {
            clientName,
            clientId: docData.clientId || 'client_sample_id',
            clientSecret: docData.clientSecret || 'sample_client_secret',
            redirectUri: docData.redirectUri || 'http://localhost:8001/sso/callback',
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

