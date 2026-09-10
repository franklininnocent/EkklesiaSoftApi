<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use Modules\Tenants\Support\SubscriptionRouteAllowlist;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central subscription write policy: when read_only_when_expired is active,
 * block parish mutations after grace while allowing view/print/download.
 */
class EnsureSubscriptionAccessMode
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly SubscriptionRouteAllowlist $allowlist,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->subscriptionService->usesReadOnlyWhenExpiredPolicy()) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($this->shouldBypassForPlatformOperator($user)) {
            return $next($request);
        }

        $effectiveTenantId = app(TenantContext::class)->effectiveTenantId();
        if (! $effectiveTenantId) {
            return $next($request);
        }

        $tenant = Tenant::query()->find($effectiveTenantId);
        if (! $tenant) {
            return $next($request);
        }

        if ($this->subscriptionService->isWriteAllowed($tenant)) {
            return $next($request);
        }

        if ($this->isSafeReadMethod($request) || $this->allowlist->allows($request)) {
            return $next($request);
        }

        return $this->deny($tenant);
    }

    private function shouldBypassForPlatformOperator(mixed $user): bool
    {
        if (! method_exists($user, 'isSuperAdmin') || ! method_exists($user, 'isEkklesiaAdmin')) {
            return false;
        }

        return ($user->isSuperAdmin() || $user->isEkklesiaAdmin()) && $user->tenant_id === null;
    }

    private function isSafeReadMethod(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function deny(Tenant $tenant): Response
    {
        $status = $this->subscriptionService->resolveStatus($tenant);

        return response()->json([
            'success' => false,
            'code' => SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY,
            'reason' => 'subscription_blocked',
            'subscription_status' => $status,
            'access_mode' => SubscriptionService::ACCESS_MODE_READ_ONLY,
            'message' => 'Your subscription has ended. You can view, print, and download, but you cannot save changes. Contact EkklesiaSoft to renew.',
        ], 403);
    }
}
