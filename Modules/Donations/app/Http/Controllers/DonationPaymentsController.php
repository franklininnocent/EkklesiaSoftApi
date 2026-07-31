<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\RequestRefundRequest;
use Modules\Donations\Http\Requests\ReversePaymentRequest;
use Modules\Donations\Http\Requests\StorePaymentRequest;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Services\DonationLedgerService;

class DonationPaymentsController extends Controller
{
    public function __construct(private readonly DonationLedgerService $ledgerService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $query = DonationPayment::forTenant($tenantId)->with(['allocations', 'receipt'])->orderByDesc('payment_date');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('method')) {
            $query->where('method', $request->string('method'));
        }
        if ($request->filled('family_id')) {
            $query->where('family_id', $request->string('family_id'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->input('per_page', 20)),
        ]);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();

        $payment = $this->ledgerService->createPayment($tenantId, $userId, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Payment captured successfully.',
            'data' => $payment,
        ], 201);
    }

    public function reverse(string $id, ReversePaymentRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();

        $payment = $this->ledgerService->reversePayment($tenantId, $userId, $id, $request->string('reason')->toString());

        return response()->json([
            'success' => true,
            'message' => 'Payment reversed successfully.',
            'data' => $payment,
        ]);
    }

    public function requestRefund(string $id, RequestRefundRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();

        $refund = $this->ledgerService->requestRefund($tenantId, $userId, $id, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Refund requested successfully.',
            'data' => $refund,
        ], 201);
    }
}
