<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use Modules\Tenants\Support\SubscriptionAccessRoutePolicy;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central subscription access policy: writes, exports, and (when enabled) locked read denial.
 */
class EnsureSubscriptionAccessMode
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly SubscriptionAccessRoutePolicy $routePolicy,
    ) {}

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
        $homeTenantId = isset($user->tenant_id) ? $user->tenant_id : null;

        if (! $effectiveTenantId) {
            if ($homeTenantId) {
                return $this->denyUnresolvedTenant($request) ?? $next($request);
            }

            return $next($request);
        }

        $tenant = Tenant::query()->find($effectiveTenantId);
        if (! $tenant) {
            return $this->denyUnresolvedTenant($request) ?? $next($request);
        }

        if ($this->routePolicy->isRecovery($request)) {
            return $next($request);
        }

        $access = $this->subscriptionService->evaluateAccess($tenant);
        $mode = $access['access_mode'];

        if ($mode === SubscriptionService::ACCESS_MODE_FULL) {
            return $next($request);
        }

        if ($this->subscriptionService->usesViewOnlyLockedEnforcement()) {
            if ($this->routePolicy->isRestrictedGeneration($request)) {
                return $this->deny($tenant, $access);
            }

            if ($mode === SubscriptionService::ACCESS_MODE_LOCKED) {
                return $this->deny($tenant, $access);
            }

            if ($this->isSafeReadMethod($request)) {
                return $next($request);
            }

            if ($this->routePolicy->isReadOnlyPost($request)) {
                return $next($request);
            }

            return $this->deny($tenant, $access);
        }

        if ($this->subscriptionService->isWriteAllowed($tenant)) {
            return $next($request);
        }

        if ($this->isSafeReadMethod($request) || SubscriptionAccessRoutePolicy::legacyWriteAllowlist()->allows($request)) {
            return $next($request);
        }

        return $this->deny($tenant, $access);
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

    private function denyUnresolvedTenant(Request $request): ?Response
    {
        if ($this->isSafeReadMethod($request) || $this->routePolicy->isRecovery($request)) {
            return null;
        }

        if (SubscriptionAccessRoutePolicy::legacyWriteAllowlist()->allows($request)) {
            return null;
        }

        return response()->json([
            'success' => false,
            'code' => SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY,
            'reason' => 'subscription_blocked',
            'access_mode' => SubscriptionService::ACCESS_MODE_READ_ONLY,
            'message' => 'This account cannot save changes until a parish subscription is available.',
        ], 403);
    }

    /**
     * @param  array<string, mixed>  $access
     */
    private function deny(Tenant $tenant, array $access): Response
    {
        $mode = (string) ($access['access_mode'] ?? SubscriptionService::ACCESS_MODE_READ_ONLY);
        $endsAt = $access['subscription_ends_at'] ?? $tenant->subscription_ends_at;
        $graceEnds = $access['grace_ends_at'] ?? null;

        $code = match ($mode) {
            SubscriptionService::ACCESS_MODE_VIEW_ONLY => SubscriptionService::CODE_SUBSCRIPTION_VIEW_ONLY,
            SubscriptionService::ACCESS_MODE_LOCKED => SubscriptionService::CODE_SUBSCRIPTION_LOCKED,
            default => SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY,
        };

        $message = match ($mode) {
            SubscriptionService::ACCESS_MODE_VIEW_ONLY => 'Your subscription and grace period have expired. You can view existing records, but operational actions are restricted. Renew your subscription to restore full access.',
            SubscriptionService::ACCESS_MODE_LOCKED => 'Your subscription access has ended. Renew your subscription or contact EkklesiaNexus for assistance.',
            default => 'Your subscription has ended. You can view records, but you cannot save changes. Contact EkklesiaNexus to renew.',
        };

        Log::info('Subscription access denied', [
            'code' => $code,
            'tenant_id' => $tenant->id,
            'actor_id' => request()->user()?->id,
            'route' => request()->path(),
            'method' => request()->method(),
        ]);

        return response()->json([
            'success' => false,
            'code' => $code,
            'reason' => 'subscription_blocked',
            'subscription_status' => $access['stored_status'] ?? $this->subscriptionService->resolveStatus($tenant),
            'effective_status' => $access['effective_status'] ?? null,
            'access_mode' => $mode,
            'subscription_expired_date' => $endsAt?->toIso8601String(),
            'grace_period_end_date' => $graceEnds?->toIso8601String(),
            'renewal_available' => (bool) ($access['renewal_available'] ?? false),
            'message' => $message,
        ], 403);
    }
}
