<?php

namespace Modules\ApplicationAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\ApplicationAccess\Support\ApplicationAccessAuthorization;
use Modules\Authentication\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform Application Access gate — does not use hasPermission() platform-scope gate.
 */
class EnsureApplicationAccessPermission
{
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        if (! $user->hasEkklesiaRole()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. Application Access is restricted to platform administrators.',
            ], 403);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if ($permissions === []) {
            $permissions = ['application_access.view'];
        }

        $missing = ApplicationAccessAuthorization::missing($user, $permissions);

        if ($missing !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient Application Access permission.',
                'missing_permissions' => $missing,
            ], 403);
        }

        return $next($request);
    }
}
