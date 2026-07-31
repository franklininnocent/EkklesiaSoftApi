<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Donations\Services\PaymentReceiptOcrService;

class PaymentOcrController extends Controller
{
    public function __construct(private readonly PaymentReceiptOcrService $ocrService)
    {
    }

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'extracted_text' => ['nullable', 'string', 'max:5000'],
            'receipt_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->ocrService->analyze(
                $request->file('receipt_image'),
                $validated['extracted_text'] ?? null
            ),
        ]);
    }
}
