<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Controllers\Concerns\HandlesDonationIdempotency;
use Modules\Donations\Http\Requests\ReissueReceiptRequest;
use Modules\Donations\Http\Requests\VoidReceiptRequest;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Services\DonationIdempotencyService;
use Modules\Donations\Services\DonationReceiptPrintService;
use Modules\Donations\Services\DonationReceiptService;
use Modules\Donations\Support\MoneyMath;
use Modules\Tenants\Support\TenantContext;

class DonationReceiptsController extends Controller
{
    use HandlesDonationIdempotency;

    public function __construct(
        private readonly DonationReceiptService $receiptService,
        private readonly DonationReceiptPrintService $printService,
        private readonly DonationIdempotencyService $idempotency
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $query = DonationReceipt::forTenant($tenantId)
            ->with([
                'payment:id,payment_number,payer_name,amount,payment_date,method,status,family_id,is_anonymous',
                'payment.family:id,family_name,family_code',
            ])
            ->orderByDesc('issued_on')
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search): void {
                $builder->where('receipt_number', 'like', "%{$search}%")
                    ->orWhereHas('payment', function ($paymentQuery) use ($search): void {
                        $paymentQuery->where('payer_name', 'like', "%{$search}%")
                            ->orWhere('payment_number', 'like', "%{$search}%")
                            ->orWhereHas('family', function ($familyQuery) use ($search): void {
                                $familyQuery->where('family_name', 'like', "%{$search}%")
                                    ->orWhere('family_code', 'like', "%{$search}%");
                            });
                    });
            });
        }

        if ($request->filled('family_id')) {
            $familyId = $request->string('family_id')->toString();
            $query->whereHas('payment', fn ($paymentQuery) => $paymentQuery->where('family_id', $familyId));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('issued_on', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('issued_on', '<=', $request->string('date_to')->toString());
        }

        if ($request->filled('is_void')) {
            $query->where('is_void', filter_var($request->input('is_void'), FILTER_VALIDATE_BOOLEAN));
        }

        $paginator = $query->paginate((int) $request->input('per_page', 20));

        $paginator->getCollection()->transform(function (DonationReceipt $receipt): array {
            $payment = $receipt->payment;
            $amount = $receipt->snapshot['totals']['amount'] ?? ($payment?->amount ?? 0);

            return [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'issued_on' => $receipt->issued_on?->toDateString(),
                'is_void' => (bool) $receipt->is_void,
                'void_reason' => $receipt->void_reason,
                'payment_id' => $receipt->payment_id,
                'payment_number' => $payment?->payment_number,
                'payer_name' => $payment?->is_anonymous ? 'Anonymous' : $payment?->payer_name,
                'amount' => MoneyMath::toApiNumber($amount),
                'payment_date' => $payment?->payment_date?->toDateString(),
                'method' => $payment?->method,
                'status' => $payment?->status,
                'family_id' => $payment?->family_id,
                'family_name' => $payment?->family?->family_name,
                'family_code' => $payment?->family?->family_code,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function showByPayment(string $paymentId): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        $payment = DonationPayment::forTenant($tenantId)
            ->with(['allocations', 'receipts', 'family', 'donor'])
            ->findOrFail($paymentId);

        $receipt = $this->receiptService->latestReceipt($payment);
        if (! $receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found for payment.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->receiptService->buildReceiptPayload($tenantId, $payment, $receipt),
        ]);
    }

    public function printByPayment(string $paymentId)
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        $payment = DonationPayment::forTenant($tenantId)
            ->with(['allocations', 'receipts', 'family', 'donor'])
            ->findOrFail($paymentId);

        $receipt = $this->receiptService->latestReceipt($payment);
        if (! $receipt) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt not found for payment.',
            ], 404);
        }

        $payload = $this->printService->buildPrintPayload($tenantId, $payment, $receipt);
        $html = $this->printService->renderHtml($payload);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $receipt = DonationReceipt::forTenant($tenantId)
            ->with(['payment.allocations', 'payment.family', 'payment.donor'])
            ->findOrFail($id);

        $payment = $receipt->payment;
        if (! $payment) {
            return response()->json([
                'success' => true,
                'data' => ['receipt' => $receipt],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->receiptService->buildReceiptPayload($tenantId, $payment, $receipt),
        ]);
    }

    public function voidReceipt(string $id, VoidReceiptRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();

        return $this->withIdempotency($this->idempotency, $tenantId, 'receipt.void', $request, function () use ($tenantId, $userId, $id, $request) {
            $receipt = DonationReceipt::forTenant($tenantId)->lockForUpdate()->findOrFail($id);
            $voided = $this->receiptService->voidReceipt($tenantId, $userId, $receipt, $request->string('reason')->toString());

            return [
                'status' => 200,
                'message' => 'Receipt voided.',
                'data' => $voided,
                'resource_type' => 'receipt',
                'resource_id' => $voided->id,
            ];
        });
    }

    public function reissue(string $id, ReissueReceiptRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();

        return $this->withIdempotency($this->idempotency, $tenantId, 'receipt.reissue', $request, function () use ($tenantId, $userId, $id, $request) {
            $receipt = DonationReceipt::forTenant($tenantId)->with('payment')->lockForUpdate()->findOrFail($id);
            $payment = $receipt->payment;
            if (! $payment) {
                throw new \RuntimeException('Receipt is not linked to a payment.');
            }
            $replacement = $this->receiptService->reissueReceipt(
                $tenantId,
                $userId,
                $payment,
                $request->string('reason')->toString()
            );

            return [
                'status' => 201,
                'message' => 'Replacement receipt issued.',
                'data' => $replacement,
                'resource_type' => 'receipt',
                'resource_id' => $replacement->id,
            ];
        });
    }
}
