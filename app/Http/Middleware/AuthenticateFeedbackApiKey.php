<?php

namespace App\Http\Middleware;

use App\Models\FbEvent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFeedbackApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-KEY');

        if (empty($apiKey)) {
            $bearerToken = $request->bearerToken();
            if (! empty($bearerToken)) {
                $apiKey = $bearerToken;
            }
        }

        if (empty($apiKey)) {
            return response()->json([
                'message' => 'Unauthenticated: Missing Event API Key. Provide via X-API-KEY header.',
            ], 401);
        }

        $event = FbEvent::query()->where('api_key', $apiKey)->first();

        if (! $event || $event->trashed()) {
            return response()->json([
                'message' => 'Unauthenticated: Invalid or inactive Event API Key.',
            ], 401);
        }

        $request->attributes->set('feedback_event', $event);

        return $next($request);
    }
}
