<?php

namespace Modules\RolesAndPermissions\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenant
{
    /**
     * Ensure authenticated user belongs to a tenant.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context required.',
            ], 403);
        }

        return $next($request);
    }
}
