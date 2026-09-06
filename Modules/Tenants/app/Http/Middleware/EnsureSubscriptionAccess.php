<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft-gate: block gated modules when subscription is EXPIRED or SUSPENDED.
 * Usage: middleware('tenant.subscription:donations')
 */
class EnsureSubscriptionAccess
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService
    ) {
    }

    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $user = $request->user();
        $effectiveTenantId = app(TenantContext::class)->effectiveTenantId();

        if (! $user || ! $effectiveTenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
                'reason' => 'account_inactive',
            ], 403);
        }

        // Platform admins operating without a parish context skip soft-gate
        if (method_exists($user, 'isSuperAdmin') && ($user->isSuperAdmin() || $user->isEkklesiaAdmin())) {
            if ($user->tenant_id === null) {
                return $next($request);
            }
        }

        $tenant = Tenant::query()->find($effectiveTenantId);
        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
                'reason' => 'account_inactive',
            ], 403);
        }

        $result = $this->subscriptionService->evaluateModuleAccess($tenant, $moduleKey);

        if (! $result['allowed']) {
            $message = match ($result['reason']) {
                'account_inactive' => 'This church account is inactive.',
                'feature_not_entitled' => 'This feature is not included in your subscription plan.',
                default => 'Your subscription has ended or is suspended. Contact EkklesiaSoft or your administrator to restore access.',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
                'reason' => $result['reason'],
                'subscription_status' => $result['status'],
            ], 403);
        }

        return $next($request);
    }
}
