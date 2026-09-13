<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPendingSsoRequest
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && session()->has('sso_authorize_params')) {
            $params = session()->pull('sso_authorize_params');

            return redirect()->route('sso.authorize', $params);
        }

        return $next($request);
    }
}
