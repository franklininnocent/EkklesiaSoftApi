<?php

namespace Modules\RolesAndPermissions\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantPermission
{
    /**
     * Ensure authenticated tenant user has all required permissions.
     */
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $user = $request->user();

        if (!$user || !$user->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context required.',
            ], 403);
        }

        // Super admin still bypasses permission checks.
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $missing = [];
        foreach ($permissions as $permission) {
            if (!$user->hasPermission($permission)) {
                $missing[] = $permission;
            }
        }

        if (!empty($missing)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permission for this tenant action.',
                'missing_permissions' => $missing,
            ], 403);
        }

        return $next($request);
    }
}
