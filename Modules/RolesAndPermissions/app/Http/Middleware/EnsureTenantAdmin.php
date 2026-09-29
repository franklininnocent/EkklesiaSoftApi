<?php

namespace Modules\RolesAndPermissions\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAdmin
{
    /**
     * Ensure authenticated user can manage tenant roles and permissions.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $effectiveTenantId = app(TenantContext::class)->effectiveTenantId();

        if (
            ! $user
            || ! $effectiveTenantId
            || ! $user->isTenantAdmin()
            || (int) $user->tenant_id !== (int) $effectiveTenantId
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant administrator access required.',
            ], 403);
        }

        return $next($request);
    }
}
