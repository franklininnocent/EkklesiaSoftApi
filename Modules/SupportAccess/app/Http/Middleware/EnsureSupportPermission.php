<?php

namespace Modules\SupportAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform support permission gate (does not require an effective tenant).
 */
class EnsureSupportPermission
{
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        if (! $this->isPlatformOperator($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
                'error' => 'Support Center access is restricted to platform operators.',
            ], 403);
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $missing = [];
        foreach ($permissions as $permission) {
            if (! method_exists($user, 'hasPermission') || ! $user->hasPermission($permission)) {
                $missing[] = $permission;
            }
        }

        if ($missing !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient support permission.',
                'missing_permissions' => $missing,
            ], 403);
        }

        return $next($request);
    }

    private function isPlatformOperator(object $user): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if (method_exists($user, 'hasEkklesiaRole') && $user->hasEkklesiaRole()) {
            return true;
        }

        return method_exists($user, 'hasRole') && $user->hasRole(\Modules\Authentication\Models\Role::SUPPORT_ADMIN);
    }
}
