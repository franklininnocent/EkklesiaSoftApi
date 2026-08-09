<?php

namespace Modules\MinistriesAssociations\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\MinistriesAssociations\Services\Admin\AdminMinistriesOverviewService;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;

class AdminMinistriesOverviewController extends Controller
{
    public function __construct(
        private readonly AdminMinistriesOverviewService $overview,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window_days' => ['sometimes', 'integer', 'in:'.implode(',', AdminMinistriesWindow::ALLOWED_DAYS)],
        ]);

        $windowDays = (int) ($validated['window_days'] ?? AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS);

        try {
            $data = $this->overview->build($windowDays);
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

    public function attention(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window_days' => ['sometimes', 'integer', 'in:'.implode(',', AdminMinistriesWindow::ALLOWED_DAYS)],
        ]);

        $windowDays = (int) ($validated['window_days'] ?? AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS);

        try {
            $data = $this->overview->attention($windowDays);
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

    public function activity(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->overview->activity(),
        ]);
    }
}
