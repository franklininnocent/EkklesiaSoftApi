<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\ProcessPaymentBatchUploadRequest;
use Modules\Donations\Http\Requests\StorePaymentBatchRequest;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\PaymentBatch;
use Modules\Donations\Services\DonationLedgerService;
use Modules\Donations\Services\DonationAuditService;

class PaymentBatchesController extends Controller
{
    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly DonationLedgerService $ledgerService
    )
    {
    }

    public function index(): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $batches = PaymentBatch::forTenant($tenantId)
            ->withCount('payments')
            ->orderByDesc('batch_date')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $batches,
        ]);
    }

    public function store(StorePaymentBatchRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $payload = $request->validated();

        $batch = PaymentBatch::create([
            'tenant_id' => $tenantId,
            'batch_number' => $this->buildBatchNumber($tenantId),
            'batch_date' => $payload['batch_date'],
            'source' => $payload['source'] ?? 'manual',
            'status' => $payload['status'] ?? 'draft',
            'notes' => $payload['notes'] ?? null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        if (!empty($payload['payment_ids'])) {
            $payments = DonationPayment::forTenant($tenantId)
                ->whereIn('id', $payload['payment_ids'])
                ->get();

            if ($payments->count() !== count($payload['payment_ids'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more payments are invalid for this tenant.',
                ], 422);
            }

            $payments->each(function (DonationPayment $payment) use ($batch, $userId): void {
                $payment->payment_batch_id = $batch->id;
                $payment->updated_by = $userId;
                $payment->save();
            });

            $batch->payments_count = $payments->count();
            $batch->total_amount = (float) $payments->sum('amount');
            $batch->save();
        }

        $this->auditService->log($tenantId, 'batch.created', 'payment_batch', $batch->id, null, $batch->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Payment batch created successfully.',
            'data' => $batch->load('payments'),
        ], 201);
    }

    public function upload(ProcessPaymentBatchUploadRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $payload = $request->validated();
        $rows = $payload['rows'];
        $commit = (bool) ($payload['commit'] ?? false);

        $preview = [
            'rows_count' => count($rows),
            'total_amount' => round(array_sum(array_map(fn($row) => (float) $row['amount'], $rows)), 2),
            'valid_rows' => count($rows),
            'invalid_rows' => 0,
        ];

        if (!$commit) {
            return response()->json([
                'success' => true,
                'message' => 'Batch upload preview generated.',
                'data' => $preview,
            ]);
        }

        $batch = PaymentBatch::create([
            'tenant_id' => $tenantId,
            'batch_number' => $this->buildBatchNumber($tenantId),
            'batch_date' => $payload['batch_date'],
            'source' => 'upload',
            'status' => 'posted',
            'payments_count' => 0,
            'total_amount' => 0,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $createdPayments = [];
        foreach ($rows as $row) {
            $payment = $this->ledgerService->createPayment($tenantId, $userId, array_merge($row, [
                'payment_batch_id' => $batch->id,
            ]));
            $createdPayments[] = $payment;
        }

        $batch->payments_count = count($createdPayments);
        $batch->total_amount = round(array_sum(array_map(fn($payment) => (float) $payment->amount, $createdPayments)), 2);
        $batch->save();

        $this->auditService->log(
            $tenantId,
            'batch.upload_committed',
            'payment_batch',
            $batch->id,
            null,
            $batch->toArray(),
            ['rows_count' => count($rows)]
        );

        return response()->json([
            'success' => true,
            'message' => 'Batch upload processed successfully.',
            'data' => [
                'batch' => $batch,
                'preview' => $preview,
            ],
        ], 201);
    }

    private function buildBatchNumber(int $tenantId): string
    {
        $count = PaymentBatch::forTenant($tenantId)->whereDate('created_at', now()->toDateString())->count() + 1;
        return sprintf('BATCH-%d-%s-%03d', $tenantId, now()->format('Ymd'), $count);
    }
}
