<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckBanned
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isBanned()) {
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
            }

            Auth::forgetGuards();
            $request->setUserResolver(fn () => null);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your account has been suspended by an administrator.',
                ], 403);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been suspended by an administrator.',
            ]);
        }

        if ($user && (! $user->last_seen_at || $user->last_seen_at->diffInMinutes(now()) >= 2)) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
