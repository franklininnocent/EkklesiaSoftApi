<?php

namespace Modules\RolesAndPermissions\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenant
{
    /**
     * Ensure the request has an effective tenant (home membership or support session).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        if (!$user || $context->effectiveTenantId() === null) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context required.',
            ], 403);
        }

        return $next($request);
    }
}
