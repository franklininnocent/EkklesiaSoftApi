<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API clients must receive JSON even when they omit Accept.
 * Without this, unauthenticated guests are redirected to a missing `login`
 * named route and Laravel dumps a debug HTML page instead of 401 JSON.
 */
class ForceJsonOnApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
