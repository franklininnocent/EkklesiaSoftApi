<?php

namespace Modules\MinistriesAssociations\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\MinistriesAssociations\Services\Admin\AdminMinistriesAnalyticsService;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;

class AdminMinistriesAnalyticsController extends Controller
{
    public function __construct(
        private readonly AdminMinistriesAnalyticsService $analytics,
    ) {
    }

    public function adoption(Request $request): JsonResponse
    {
        return $this->respond(fn (AdminMinistriesWindow $window) => $this->analytics->adoption($window), $request);
    }

    public function usage(Request $request): JsonResponse
    {
        return $this->respond(fn (AdminMinistriesWindow $window) => $this->analytics->usage($window), $request);
    }

    public function features(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window_days' => ['sometimes', 'integer', 'in:'.implode(',', AdminMinistriesWindow::ALLOWED_DAYS)],
            'category' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_keys(AdminMinistriesDefinitions::FEATURE_EVENT_MAP))],
        ]);

        $windowDays = (int) ($validated['window_days'] ?? AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS);

        try {
            $window = AdminMinistriesWindow::fromDays($windowDays);
            $data = $this->analytics->features($window, $validated['category'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function trends(Request $request): JsonResponse
    {
        return $this->respond(fn (AdminMinistriesWindow $window) => $this->analytics->trends($window), $request);
    }

    private function respond(callable $builder, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window_days' => ['sometimes', 'integer', 'in:'.implode(',', AdminMinistriesWindow::ALLOWED_DAYS)],
        ]);

        $windowDays = (int) ($validated['window_days'] ?? AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS);

        try {
            $window = AdminMinistriesWindow::fromDays($windowDays);
            $data = $builder($window);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
