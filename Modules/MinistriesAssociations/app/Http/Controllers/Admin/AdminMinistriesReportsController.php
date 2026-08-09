<?php

namespace Modules\MinistriesAssociations\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\MinistriesAssociations\Services\Admin\AdminMinistriesReportsService;
use Modules\MinistriesAssociations\Support\AdminMinistriesReportTypes;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminMinistriesReportsController extends Controller
{
    public function __construct(
        private readonly AdminMinistriesReportsService $reports,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reports->catalog(),
        ]);
    }

    public function show(Request $request, string $type): JsonResponse
    {
        if (! AdminMinistriesReportTypes::isValid($type)) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown report type.',
            ], 404);
        }

        $filters = $this->validateFilters($request);

        try {
            $data = $this->reports->summary($type, $filters);
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

    public function export(Request $request, string $type): StreamedResponse|JsonResponse
    {
        if (! AdminMinistriesReportTypes::isValid($type)) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown report type.',
            ], 404);
        }

        $filters = $this->validateFilters($request);

        try {
            return $this->reports->exportCsv($type, $filters);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'window_days' => ['sometimes', 'integer', 'in:'.implode(',', AdminMinistriesWindow::ALLOWED_DAYS)],
        ]);
    }
}
