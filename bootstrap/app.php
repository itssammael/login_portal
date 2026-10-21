<?php

use App\Http\Middleware\CheckBanned;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityThreatDetection;
use App\Http\Middleware\SetSecurityHeaders;
use App\Http\Middleware\TrustCloudflareTunnel;
use App\Services\Security\ThreatDetectorService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES');
        if ($trustedProxies === '*') {
            $middleware->trustProxies(at: '*');
        } elseif (! empty($trustedProxies)) {
            $middleware->trustProxies(at: array_filter(array_map('trim', explode(',', (string) $trustedProxies))));
        } else {
            $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        }

        $middleware->prepend([
            TrustCloudflareTunnel::class,
            SecurityThreatDetection::class,
        ]);

        $middleware->web(append: [
            SetSecurityHeaders::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            CheckBanned::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'embed/feedback/*',
            'sso/token',
        ]);

        $middleware->alias(['admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            try {
                $logged = app(ThreatDetectorService::class)->record404($request);
                if ($logged && $logged->action_taken === 'auto_blocked') {
                    abort(403, 'Access Denied: Malicious probe detected. IP quarantined by automated defense.');
                }
            } catch (Throwable $e) {
                if ($e instanceof HttpExceptionInterface) {
                    throw $e;
                }
                // Fail-safe: never disrupt error rendering
            }

            return null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            try {
                app(ThreatDetectorService::class)->recordRateLimitExceeded($request);
            } catch (Throwable) {
                // Fail-safe: never disrupt error rendering
            }

            return null;
        });
    })->create();
