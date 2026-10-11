<?php

namespace Modules\Subscriptions\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Marketing\Services\MarketingPlanCatalogService;

/**
 * Unauthenticated pricing catalog: only the Super Admin–approved marketing revision.
 */
class PublicPlanController extends Controller
{
    public function __construct(private readonly MarketingPlanCatalogService $catalog) {}

    public function index(): JsonResponse
    {
        $ttl = max(0, (int) config('marketing.plan_catalog.public_cache_seconds', 300));

        try {
            $plans = $this->catalog->getPublishedPlans();
        } catch (\Illuminate\Database\QueryException) {
            return response()->json([
                'success' => false,
                'message' => 'Published plan catalog is not available.',
            ], 503);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to load the published plan catalog.',
            ], 500);
        }

        return response()->json(['success' => true, 'data' => $plans])
            ->header('Cache-Control', 'public, max-age='.min($ttl, 300));
    }
}
