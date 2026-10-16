<?php

namespace Modules\RolesAndPermissions\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Services\SupportSessionAuthorizationService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantPermission
{
    /**
     * Ensure authenticated user may act in the effective tenant with the given permissions.
     *
     * Support sessions (Phase 1) authorize via support.sessions.* mode permissions,
     * not borrowed tenant-user RBAC.
     */
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        if (!$user || $context->effectiveTenantId() === null) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context required.',
            ], 403);
        }

        if ($context->isSupportSession()) {
            return $this->authorizeSupportSession($request, $next, $context);
        }

        // Super admin still bypasses tenant permission checks when acting in home tenant.
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        // Mirror AuthorizesTenantPermission::allows() — tenant admins manage their parish.
        if (method_exists($user, 'isTenantAdmin') && $user->isTenantAdmin()) {
            return $next($request);
        }

        if ($user->is_primary_admin ?? false) {
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

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    private function authorizeSupportSession(Request $request, Closure $next, TenantContext $context): Response
    {
        $user = $request->user();
        $supportAuth = app(SupportSessionAuthorizationService::class);

        if (! $supportAuth->grantsTenantProductAccess($user)) {
            $modePermission = $supportAuth->modePermissionName($context->supportMode());

            return response()->json([
                'success' => false,
                'message' => 'Support session mode is not authorized.',
                'missing_permissions' => array_values(array_filter([$modePermission])),
            ], 403);
        }

        return $next($request);
    }
}
