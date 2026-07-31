<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Services\UpiPaymentIntentService;

class UpiPaymentController extends Controller
{
    public function __construct(private readonly UpiPaymentIntentService $upiService)
    {
    }

    public function intent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'family_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->upiService->build(
                (int) Auth::user()->tenant_id,
                (float) $validated['amount'],
                $validated['family_id'] ?? null,
                $validated['note'] ?? null
            ),
        ]);
    }
}
