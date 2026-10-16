<?php

namespace Modules\MassIntentions\Services;

use App\Support\MoneyMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Models\MassIntentionOffering;
use Modules\MassIntentions\Models\MassIntentionOfferingReceipt;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;

class MassIntentionOfferingService
{
    public function __construct(
        private readonly MassIntentionReceiptNumberService $receiptNumbers,
        private readonly MassIntentionAuditService $audits,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordReceipt(int $tenantId, User $actor, string $requestId, array $data): array
    {
        $request = MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $requestId)
            ->firstOrFail();

        if ($request->status !== MassIntentionStatus::ACCEPTED) {
            throw ValidationException::withMessages([
                'request' => 'Offerings can be recorded only after the intention is accepted.',
            ]);
        }

        $amount = MoneyMath::normalize($data['amount'] ?? '0');
        if (MoneyMath::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Enter an amount greater than zero.',
            ]);
        }

        $offering = MassIntentionOffering::query()
            ->where('tenant_id', $tenantId)
            ->where('request_id', $requestId)
            ->firstOrFail();

        $receivedOn = $data['received_on'] ?? now()->toDateString();
        $year = (int) date('Y', strtotime((string) $receivedOn));

        return DB::transaction(function () use ($tenantId, $actor, $offering, $amount, $data, $receivedOn, $year): array {
            $receipt = MassIntentionOfferingReceipt::query()->create([
                'tenant_id' => $tenantId,
                'offering_id' => $offering->id,
                'receipt_number' => $this->receiptNumbers->nextForTenant($tenantId, $year),
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'received_on' => $receivedOn,
                'recorded_by_user_id' => $actor->id,
            ]);

            return [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'amount' => $receipt->amount,
                'payment_method' => $receipt->payment_method,
                'received_on' => $receipt->received_on?->format('Y-m-d'),
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listReceiptsForRequest(int $tenantId, string $requestId): array
    {
        $offering = MassIntentionOffering::query()
            ->where('tenant_id', $tenantId)
            ->where('request_id', $requestId)
            ->first();

        if (! $offering) {
            return [];
        }

        return MassIntentionOfferingReceipt::query()
            ->where('tenant_id', $tenantId)
            ->where('offering_id', $offering->id)
            ->whereNull('voided_at')
            ->orderBy('received_on')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'receipt_number' => $r->receipt_number,
                'amount' => $r->amount,
                'payment_method' => $r->payment_method,
                'received_on' => $r->received_on?->format('Y-m-d'),
            ])
            ->all();
    }

    public function voidReceipt(int $tenantId, User $actor, string $receiptId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Enter why this receipt is voided.',
            ]);
        }

        $receipt = MassIntentionOfferingReceipt::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $receiptId)
            ->whereNull('voided_at')
            ->firstOrFail();

        $offering = MassIntentionOffering::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $receipt->offering_id)
            ->first();

        $receipt->voided_at = now();
        $receipt->void_reason = $reason;
        $receipt->save();

        $this->audits->record($tenantId, 'receipt.voided', $actor, $offering?->request_id, null, [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
        ]);
    }
}
