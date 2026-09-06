<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Controllers\Concerns\HandlesDonationIdempotency;
use Modules\Donations\Http\Requests\RequestRefundRequest;
use Modules\Donations\Http\Requests\ReversePaymentRequest;
use Modules\Donations\Http\Requests\StorePaymentRequest;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Services\DonationIdempotencyService;
use Modules\Donations\Services\DonationLedgerService;
use Modules\Donations\Support\MoneyMath;
use Modules\Tenants\Support\TenantContext;

class DonationPaymentsController extends Controller
{
    use HandlesDonationIdempotency;

    public function __construct(
        private readonly DonationLedgerService $ledgerService,
        private readonly DonationIdempotencyService $idempotency
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $query = DonationPayment::forTenant($tenantId)->with(['allocations', 'receipt', 'receipts'])->orderByDesc('payment_date');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('method')) {
            $query->where('method', $request->string('method'));
        }
        if ($request->filled('family_id')) {
            $query->where('family_id', $request->string('family_id'));
        }

        $paginator = $query->paginate((int) $request->input('per_page', 20));
        $paginator->getCollection()->transform(function (DonationPayment $payment) {
            $payment->setAttribute('refundable_remaining', MoneyMath::toApiNumber(
                $this->ledgerService->refundableRemaining($payment)
            ));

            return $payment;
        });

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $payload = $request->validated();
        $payload['idempotency_key'] = $this->idempotency->extractKey($request);

        return $this->withIdempotency($this->idempotency, $tenantId, 'payment.create', $request, function () use ($tenantId, $userId, $payload) {
            $payment = $this->ledgerService->createPayment($tenantId, $userId, $payload);

            return [
                'status' => 201,
                'message' => 'Payment captured successfully.',
                'data' => $payment,
                'resource_type' => 'payment',
                'resource_id' => $payment->id,
            ];
        });
    }

    public function reverse(string $id, ReversePaymentRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $payment = DonationPayment::forTenant($tenantId)->find($id);

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        $this->authorize('reverse', $payment);

        return $this->withIdempotency($this->idempotency, $tenantId, 'payment.reverse', $request, function () use ($tenantId, $userId, $id, $request) {
            $payment = $this->ledgerService->reversePayment($tenantId, $userId, $id, $request->string('reason')->toString());

            return [
                'status' => 200,
                'message' => 'Payment reversed successfully.',
                'data' => $payment,
                'resource_type' => 'payment',
                'resource_id' => $payment->id,
            ];
        });
    }

    public function requestRefund(string $id, RequestRefundRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $payment = DonationPayment::forTenant($tenantId)->find($id);

        if (! $payment) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        $this->authorize('refund', $payment);

        return $this->withIdempotency($this->idempotency, $tenantId, 'payment.refund', $request, function () use ($tenantId, $userId, $id, $request) {
            $refund = $this->ledgerService->requestRefund($tenantId, $userId, $id, $request->validated());

            return [
                'status' => 201,
                'message' => 'Refund requested successfully.',
                'data' => $refund,
                'resource_type' => 'refund',
                'resource_id' => $refund->id,
            ];
        });
    }
}
