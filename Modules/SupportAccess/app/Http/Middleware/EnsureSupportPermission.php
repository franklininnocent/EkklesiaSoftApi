<?php

namespace Modules\SupportAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Authentication\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform support permission gate (does not require an effective tenant).
 *
 * Support Center ops routes must not depend on an active support session.
 * User::hasPermission() gates SCOPE_PLATFORM behind canResolvePlatformPermissions()
 * (parish-home operators only resolve during session elevation) — that is correct for
 * tenant product APIs, but wrong here. We check role/direct assignment via
 * getAllPermissions() instead, after confirming the actor is a platform operator.
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
            if (! $this->hasAssignedSupportPermission($user, $permission)) {
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

        return method_exists($user, 'hasRole') && $user->hasRole(Role::SUPPORT_ADMIN);
    }

    /**
     * True when the operator's roles/direct grants include the permission name.
     * Does not require canResolvePlatformPermissions() / support-session elevation.
     */
    private function hasAssignedSupportPermission(object $user, string $permission): bool
    {
        if (method_exists($user, 'getAllPermissions')) {
            return $user->getAllPermissions(true)->contains('name', $permission);
        }

        return method_exists($user, 'hasPermission') && $user->hasPermission($permission);
    }
}
