<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    /**
     * Handle an incoming request.
     *
     * This middleware ensures that users can only access data from their own tenant,
     * unless they are SuperAdmin.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user = auth()->user();

        // SuperAdmin can access all tenants
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // User must belong to a tenant
        if (!$user->tenant_id) {
            return response()->json([
                'message' => 'You must belong to a tenant to access this resource',
            ], 403);
        }

        // Check if route has tenant_id parameter
        $routeTenantId = $request->route('tenant_id') ?? $request->input('tenant_id');

        if ($routeTenantId && $routeTenantId != $user->tenant_id) {
            return response()->json([
                'message' => 'You can only access resources from your own tenant',
            ], 403);
        }

        return $next($request);
    }
}

