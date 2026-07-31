<?php

namespace Modules\RolesAndPermissions\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAdmin
{
    /**
     * Ensure authenticated user can manage tenant roles and permissions.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->tenant_id || !$user->isTenantAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant administrator access required.',
            ], 403);
        }

        return $next($request);
    }
}
