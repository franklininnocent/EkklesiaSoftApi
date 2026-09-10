<?php

namespace Modules\EcclesiasticalData\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEcclesiasticalPermission
{
    /**
     * Require an Ekklesia platform role and a specific ecclesiastical permission.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $user->hasEkklesiaRole()) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
                'error' => 'Ekklesia platform access required.',
            ], 403);
        }

        if (! $user->isSuperAdmin()
            && ! $user->is_primary_admin
            && ! $user->hasPermission($permission)
            && ! $this->hasLegacyPermission($user, $permission)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
                'error' => 'Missing required permission: '.$permission,
            ], 403);
        }

        return $next($request);
    }

    private function hasLegacyPermission($user, string $permission): bool
    {
        $legacy = match ($permission) {
            'bishops.view' => 'view_bishops',
            'bishops.create' => 'create_bishops',
            'bishops.update' => 'edit_bishops',
            'bishops.archive' => 'delete_bishops',
            'dioceses.view' => 'view_dioceses',
            'dioceses.create' => 'create_dioceses',
            'dioceses.update' => 'edit_dioceses',
            'dioceses.delete' => 'delete_dioceses',
            default => null,
        };

        return $legacy !== null && $user->hasPermission($legacy);
    }
}
