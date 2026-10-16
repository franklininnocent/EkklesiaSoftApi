<?php

namespace Modules\Subscriptions\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Services\Entitlements\SubscriptionEntitlementGate;
use Modules\Subscriptions\Services\SubscriptionAuditService;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate: `entitlement:CODE[,CODE...]` — every listed feature must be enabled for the
 * effective tenant (resolved server-side from the authenticated context, never the request).
 */
class EnsureEntitlement
{
    public function __construct(
        private readonly SubscriptionEntitlementGate $gate,
        private readonly EntitlementCatalog $catalog,
        private readonly SubscriptionService $lifecycle,
        private readonly SubscriptionAuditService $audit,
    ) {}

    public function handle(Request $request, Closure $next, string ...$featureCodes): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $tenantId = app(TenantContext::class)->effectiveTenantId();
        if (! $tenantId) {
            $isPlatformActor = $user->tenant_id === null && ($user->isSuperAdmin() || $user->isEkklesiaAdmin());
            if ($isPlatformActor) {
                return $next($request);
            }

            return response()->json(['success' => false, 'message' => 'Tenant context is required.'], 403);
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'Tenant context is required.'], 403);
        }

        if ((int) $tenant->active !== 1 || ! $this->lifecycle->allowsGatedAccess($tenant)) {
            $access = $this->lifecycle->evaluateAccess($tenant);
            $mode = (string) ($access['access_mode'] ?? '');
            $code = $mode === SubscriptionService::ACCESS_MODE_LOCKED
                ? SubscriptionService::CODE_SUBSCRIPTION_LOCKED
                : 'SUBSCRIPTION_EXPIRED';
            $message = $mode === SubscriptionService::ACCESS_MODE_LOCKED
                ? 'Your subscription access has ended. Renew your subscription or contact EkklesiaNexus for assistance.'
                : 'Your subscription has ended or is suspended. Contact EkklesiaNexus or your administrator to restore access.';

            return response()->json([
                'success' => false,
                'code' => $code,
                'message' => $message,
                'reason' => 'subscription_blocked',
                'subscription_status' => $access['stored_status'] ?? $this->lifecycle->resolveStatus($tenant),
                'effective_status' => $access['effective_status'] ?? null,
                'access_mode' => $mode !== '' ? $mode : null,
                'renewal_available' => (bool) ($access['renewal_available'] ?? false),
            ], 403);
        }

        foreach ($featureCodes as $code) {
            $code = strtoupper(trim($code));
            if ($code === '' || $this->gate->allows($tenant, $code)) {
                continue;
            }

            $this->audit->denial('feature_not_available', (int) $tenant->id, ['feature_code' => $code, 'path' => $request->path()]);

            return SubscriptionException::featureNotAvailable($code, $this->catalog->feature($code)['name'] ?? null)->render();
        }

        return $next($request);
    }
}
