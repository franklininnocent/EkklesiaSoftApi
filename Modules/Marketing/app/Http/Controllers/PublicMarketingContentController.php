<?php

namespace Modules\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Modules\Marketing\Services\MarketingContentService;

class PublicMarketingContentController extends Controller
{
    public function show(MarketingContentService $marketingContent): JsonResponse
    {
        $cacheKey = MarketingContentService::CACHE_KEY_PUBLISHED;
        $payload = Cache::get($cacheKey);
        if (! is_array($payload)) {
            $payload = $marketingContent->getPublishedPayload();
            if ($payload === null) {
                Cache::forget($cacheKey);

                return response()->json([
                    'success' => false,
                    'message' => 'No published marketing content is available.',
                    'data' => null,
                ], 404);
            }
            Cache::put($cacheKey, $payload, 60);
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $payload,
        ]);
    }
}
