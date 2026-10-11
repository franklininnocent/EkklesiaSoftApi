<?php

namespace Modules\Marketing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website-wide settings and contact enquiries — Ekklesia platform only.
 *
 * SuperAdmin and EkklesiaAdmin have default access. Other Ekklesia roles
 * require an assigned platform permission (grantable via Roles & Permissions).
 */
class EnsureWebSettingsPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        if (! method_exists($user, 'hasEkklesiaRole') || ! $user->hasEkklesiaRole()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        if (method_exists($user, 'isEkklesiaAdmin') && $user->isEkklesiaAdmin()) {
            return $next($request);
        }

        if (! $this->hasAssignedPermission($user, $permission)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }

    private function hasAssignedPermission(object $user, string $permission): bool
    {
        if (method_exists($user, 'getAllPermissions')) {
            return $user->getAllPermissions(true)->contains('name', $permission);
        }

        return method_exists($user, 'hasPermission') && $user->hasPermission($permission);
    }
}
