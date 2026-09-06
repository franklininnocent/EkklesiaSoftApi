<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPlatformHealthToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredToken = (string) config('tenants.platform.health.token', '');

        if ($configuredToken === '') {
            return $next($request);
        }

        $provided = (string) ($request->header('X-Platform-Health-Token') ?? $request->query('token', ''));

        if (! hash_equals($configuredToken, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid health probe token.',
            ], 403);
        }

        return $next($request);
    }
}
