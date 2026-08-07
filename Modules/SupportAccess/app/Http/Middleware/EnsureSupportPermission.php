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
}
