<?php

namespace Modules\SupportAccess\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce support session mode restrictions (Phase 1 MVP).
 */
class EnforceSupportSessionMode
{
    /** @var list<string> */
    private const STANDARD_DENY_PREFIXES = [
        'api/subscriptions',
        'api/billing',
        'api/tenant/subscription',
        'api/tenant/billing',
        'api/tenant/license',
        'api/tenant/licenses',
        'api/platform/billing',
        'api/platform/subscriptions',
        'api/tenants/transfer',
        'api/tenant/owner',
        'api/tenant/ownership',
        'api/tenant/purge',
        'api/tenant/export/bulk',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        if (! $context->isSupportSession()) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');

        // Always allow Support Center session management while elevated.
        if (str_starts_with($path, 'api/support/')) {
            return $next($request);
        }

        $mode = $context->supportMode();

        if ($mode === SupportSessionMode::Readonly
            && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Support session is read-only.',
            ], 403);
        }

        if ($mode === SupportSessionMode::Standard || $mode === SupportSessionMode::Emergency) {
            foreach (self::STANDARD_DENY_PREFIXES as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This action is blocked during a support session.',
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
