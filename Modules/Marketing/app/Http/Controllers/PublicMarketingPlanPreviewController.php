<?php

namespace Modules\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Modules\Marketing\Services\MarketingPlanCatalogService;

/**
 * Server-to-server preview payload for Next.js (token-scoped, not a public catalog).
 */
class PublicMarketingPlanPreviewController extends Controller
{
    public function show(MarketingPlanCatalogService $catalog): JsonResponse
    {
        $session = (string) request()->query('session', '');
        $token = (string) request()->query('token', '');

        try {
            if ($session !== '') {
                $plans = $catalog->redeemPreviewSession($session);

                return response()->json([
                    'success' => true,
                    'data' => $plans,
                ])->header('Cache-Control', 'no-store');
            }

            if ($token === '') {
                return response()->json(['success' => false, 'message' => 'This preview is unavailable or has expired.'], 403);
            }

            $exchanged = $catalog->exchangePreviewToken($token);
        } catch (InvalidArgumentException) {
            return response()->json(['success' => false, 'message' => 'This preview is unavailable or has expired.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $exchanged['plans'],
            'session' => $exchanged['session'],
            'expires_in' => $exchanged['expires_in'],
        ])->header('Cache-Control', 'no-store');
    }
}
