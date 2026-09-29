<?php

namespace Modules\Subscriptions\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Subscriptions\Services\SubscriptionAuditService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform subscription administration gate.
 *
 * Only platform accounts (Super Admin / Ekklesia Admin without a tenant) may pass; Super Admin
 * holds every subscriptions.* permission, Ekklesia Admin needs each listed permission.
 */
class EnsureSubscriptionsPlatformPermission
{
    public function __construct(private readonly SubscriptionAuditService $audit) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $isSuperAdmin = $user->isSuperAdmin();
        $isPlatformActor = $user->tenant_id === null && ($isSuperAdmin || $user->isEkklesiaAdmin());

        if (! $isPlatformActor) {
            $this->audit->denial('platform_access_denied', $user->tenant_id ? (int) $user->tenant_id : null, [
                'path' => $request->path(),
                'required' => $permissions,
            ]);

            return response()->json([
                'success' => false,
                'code' => 'FORBIDDEN',
                'message' => 'Only Ekklesia platform administrators can manage subscriptions.',
            ], 403);
        }

        if ($isSuperAdmin) {
            return $next($request);
        }

        $missing = array_values(array_filter($permissions, static fn (string $p) => ! $user->hasPermission($p)));
        if ($missing !== []) {
            $this->audit->denial('platform_permission_denied', null, [
                'path' => $request->path(),
                'missing' => $missing,
            ]);

            return response()->json([
                'success' => false,
                'code' => 'FORBIDDEN',
                'message' => 'You do not have permission to perform this subscription action.',
                'missing_permissions' => $missing,
            ], 403);
        }

        return $next($request);
    }
}
