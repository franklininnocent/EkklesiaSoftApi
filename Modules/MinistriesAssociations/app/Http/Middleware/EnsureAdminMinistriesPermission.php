<?php

namespace Modules\MinistriesAssociations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform Ministries Insights gate (cross-tenant; does not require an effective tenant).
 */
class EnsureAdminMinistriesPermission
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

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isEkklesiaAdmin = method_exists($user, 'isEkklesiaAdmin') && $user->isEkklesiaAdmin();

        if (! $isSuperAdmin && ! $isEkklesiaAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can access Ministries Insights.',
            ], 403);
        }

        if ($isSuperAdmin) {
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
                'message' => 'Insufficient Ministries Insights permission.',
                'missing_permissions' => $missing,
            ], 403);
        }

        return $next($request);
    }
}
