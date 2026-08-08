<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Services\SubscriptionService;
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
        if (! $user || ! $user->tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
                'reason' => 'account_inactive',
            ], 403);
        }

        // Platform admins operating without a parish context skip soft-gate
        if (method_exists($user, 'isSuperAdmin') && ($user->isSuperAdmin() || $user->isEkklesiaAdmin())) {
            // If they have tenant_id and are acting as tenant user, still enforce;
            // SuperAdmin users typically have null tenant_id.
            if (! $user->tenant_id) {
                return $next($request);
            }
        }

        $result = $this->subscriptionService->evaluateModuleAccess($user->tenant, $moduleKey);

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
