<?php

namespace Modules\ApplicationAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ApplicationAccess\Services\ApplicationAccessDashboardService;

class ApplicationAccessDashboardController extends Controller
{
    public function __construct(
        private readonly ApplicationAccessDashboardService $dashboard,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->dashboard->metrics(),
        ]);
    }
}
