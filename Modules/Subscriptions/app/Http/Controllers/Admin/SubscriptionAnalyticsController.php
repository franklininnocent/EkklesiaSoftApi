<?php

namespace Modules\Subscriptions\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Services\SubscriptionAnalyticsService;

class SubscriptionAnalyticsController extends Controller
{
    public function __construct(private readonly SubscriptionAnalyticsService $analytics) {}

    public function overview(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->analytics->overview()]);
    }

    public function revenue(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->analytics->revenue()]);
    }

    public function usage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attention' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $result = $this->analytics->usageList(
            (bool) ($validated['attention'] ?? false),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 25),
        );

        return response()->json(['success' => true] + $result);
    }
}
