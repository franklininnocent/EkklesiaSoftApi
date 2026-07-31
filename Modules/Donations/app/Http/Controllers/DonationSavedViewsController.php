<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Services\DonationSavedViewService;

class DonationSavedViewsController extends Controller
{
    public function __construct(private readonly DonationSavedViewService $savedViewService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->savedViewService->presets(),
        ]);
    }

    public function apply(string $key): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->savedViewService->apply((int) Auth::user()->tenant_id, $key),
        ]);
    }
}
