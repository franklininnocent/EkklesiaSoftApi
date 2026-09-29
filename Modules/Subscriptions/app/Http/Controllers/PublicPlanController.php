<?php

namespace Modules\Subscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Services\SubscriptionPresenter;
use Modules\Subscriptions\Support\EntitlementCacheVersion;

/**
 * Unauthenticated pricing catalog: only ACTIVE, public, non-legacy plans with an ACTIVE version.
 */
class PublicPlanController extends Controller
{
    public function __construct(private readonly SubscriptionPresenter $presenter) {}

    public function index(): JsonResponse
    {
        $ttl = max(0, (int) config('subscriptions.public_api.cache_seconds', 300));
        $key = 'subscriptions:public_plans:c_'.EntitlementCacheVersion::catalog();

        $build = fn () => Plan::query()->publiclyListed()
            ->with('activeVersion.entitlements.feature')
            ->orderBy('display_order')
            ->get()
            ->filter(fn (Plan $p) => $p->activeVersion !== null)
            ->map(fn (Plan $p) => $this->presenter->publicPlan($p, $p->activeVersion))
            ->values()
            ->all();

        $plans = $ttl > 0 ? Cache::remember($key, $ttl, $build) : $build();

        return response()->json(['success' => true, 'data' => $plans])
            ->header('Cache-Control', 'public, max-age='.min($ttl, 300));
    }
}
