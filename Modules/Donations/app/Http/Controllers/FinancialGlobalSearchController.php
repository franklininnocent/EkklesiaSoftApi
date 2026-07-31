<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Services\FinancialGlobalSearchService;

class FinancialGlobalSearchController extends Controller
{
    public function __construct(private readonly FinancialGlobalSearchService $searchService)
    {
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->searchService->search(
                (int) Auth::user()->tenant_id,
                $validated['q'],
                (int) ($validated['limit'] ?? 6)
            ),
        ]);
    }
}
