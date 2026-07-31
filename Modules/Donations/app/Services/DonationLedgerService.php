<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\Donation;
use Modules\Donations\Jobs\SendPaymentReceiptJob;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationApproval;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationRefund;
use Modules\Donations\Models\Fund;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Models\PaymentReversal;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Family\Models\Family;

class DonationLedgerService
{
    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly DonationProjectService $projectService,
        private readonly ProjectInstallmentDueService $projectInstallmentService,
        private readonly DonationEntryBalanceService $donationBalanceService,
        private readonly DonationReceiptService $receiptService
    ) {
    }

    public function createPayment(int $tenantId, int $userId, array $payload): DonationPayment
    {
        return DB::transaction(function () use ($tenantId, $userId, $payload): DonationPayment {
            if (!empty($payload['family_id'])) {
                $familyExists = Family::query()
                    ->where('id', $payload['family_id'])
                    ->where('tenant_id', $tenantId)
                    ->exists();
                if (!$familyExists) {
                    throw new \RuntimeException('Family does not belong to the tenant.');
                }
            }

            $payment = DonationPayment::create([
                'tenant_id' => $tenantId,
                'family_id' => $payload['family_id'] ?? null,
                'donor_id' => $payload['donor_id'] ?? null,
                'payment_batch_id' => $payload['payment_batch_id'] ?? null,
                'payment_number' => $this->buildPaymentNumber($tenantId),
                'payer_name' => $payload['payer_name'],
                'payer_email' => $payload['payer_email'] ?? null,
                'payer_phone' => $payload['payer_phone'] ?? null,
                'payment_date' => $payload['payment_date'],
                'amount' => $payload['amount'],
                'currency' => $payload['currency'] ?? 'INR',
                'method' => $payload['method'],
                'gateway_reference' => $payload['gateway_reference'] ?? null,
                'status' => $payload['status'] ?? 'succeeded',
                'source_type' => $payload['source_type'] ?? 'general',
                'is_anonymous' => (bool) ($payload['is_anonymous'] ?? false),
                'notes' => $payload['notes'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $allocatedTotal = 0;
            foreach ($payload['allocations'] ?? [] as $allocation) {
                $amount = (float) $allocation['amount'];
                $allocatedTotal += $amount;
                $allocatableType = $allocation['allocatable_type'];
                $allocatableId = $allocation['allocatable_id'];

                if ($allocatableType !== 'advance' && !$this->isValidAllocatable($tenantId, $allocatableType, $allocatableId)) {
                    throw new \RuntimeException("Invalid {$allocatableType} allocation target for tenant.");
                }

                PaymentAllocation::create([
                    'tenant_id' => $tenantId,
                    'payment_id' => $payment->id,
                    'allocatable_type' => $allocatableType,
                    'allocatable_id' => $allocatableId,
                    'amount' => $allocation['amount'],
                    'notes' => $allocation['notes'] ?? null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);

                if ($allocatableType === 'due') {
                    $due = ContributionDue::forTenant($tenantId)->find($allocatableId);
                    if ($due) {
                        $due->amount_paid = (float) $due->amount_paid + $amount;
                        $due->status = $due->amount_paid >= (float) $due->amount_due ? 'paid' : 'partially_paid';
                        $due->updated_by = $userId;
                        $due->save();
                    }
                }

                if ($allocatableType === 'project_installment') {
                    $installment = ProjectInstallmentDue::forTenant($tenantId)->find($allocatableId);
                    if ($installment) {
                        $this->projectInstallmentService->applyPayment($tenantId, $userId, $installment, $amount);
                    }
                }

                if ($allocatableType === 'project') {
                    $project = DonationProject::forTenant($tenantId)->find($allocatableId);
                    if ($project) {
                        $familyId = $payload['family_id'] ?? null;
                        if ($familyId) {
                            $this->projectService->recordCollection($tenantId, $project, $familyId, $amount);
                        } else {
                            $project->raised_amount = round((float) $project->raised_amount + $amount, 2);
                            $project->save();
                            $this->projectService->maybeMarkCompleted($project->fresh());
                        }
                    }
                }

                if ($allocatableType === 'donation') {
                    $donation = Donation::forTenant($tenantId)->find($allocatableId);
                    if ($donation) {
                        $this->donationBalanceService->applyPayment($userId, $donation, $amount);
                    }
                }
            }

            $paymentAmount = round((float) $payload['amount'], 2);
            $allocatedTotal = round($allocatedTotal, 2);

            if (!empty($payload['allocations']) && $allocatedTotal > $paymentAmount) {
                throw new \RuntimeException('Allocated amount cannot exceed payment amount.');
            }

            // Automatically store any unallocated balance as an advance credit.
            if ($paymentAmount > $allocatedTotal) {
                PaymentAllocation::create([
                    'tenant_id' => $tenantId,
                    'payment_id' => $payment->id,
                    'allocatable_type' => 'advance',
                    'allocatable_id' => $payment->id,
                    'amount' => $paymentAmount - $allocatedTotal,
                    'notes' => 'Auto-created advance balance',
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
            }

            $receipt = $this->receiptService->createReceipt($tenantId, $userId, $payment);

            $this->auditService->log(
                $tenantId,
                'payment.created',
                'payment',
                $payment->id,
                null,
                $payment->toArray(),
                ['receipt_id' => $receipt->id]
            );

            if (!empty($payment->payer_email)) {
                SendPaymentReceiptJob::dispatch($tenantId, $payment->id);
            }

            return $payment->load(['allocations', 'receipt']);
        });
    }

    public function requestRefund(int $tenantId, int $userId, string $paymentId, array $payload): DonationRefund
    {
        return DB::transaction(function () use ($tenantId, $userId, $paymentId, $payload): DonationRefund {
            $payment = DonationPayment::forTenant($tenantId)->findOrFail($paymentId);

            if (in_array($payment->status, ['reversed', 'refunded'], true)) {
                throw new \RuntimeException('Refund is not allowed for reversed/refunded payments.');
            }

            if ((float) $payload['amount'] > (float) $payment->amount) {
                throw new \RuntimeException('Refund amount cannot exceed payment amount.');
            }

            $approval = DonationApproval::create([
                'tenant_id' => $tenantId,
                'target_type' => 'payment',
                'target_id' => $payment->id,
                'action' => 'refund',
                'status' => 'pending',
                'reason' => $payload['reason'] ?? null,
                'requested_by' => $userId,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $refund = DonationRefund::create([
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
                'approval_id' => $approval->id,
                'amount' => $payload['amount'],
                'refund_date' => $payload['refund_date'],
                'status' => 'pending',
                'reason' => $payload['reason'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->auditService->log(
                $tenantId,
                'refund.requested',
                'refund',
                $refund->id,
                null,
                $refund->toArray(),
                ['payment_id' => $payment->id, 'approval_id' => $approval->id]
            );

            return $refund->load(['approval', 'payment']);
        });
    }

    public function reversePayment(int $tenantId, int $userId, string $paymentId, string $reason): DonationPayment
    {
        return DB::transaction(function () use ($tenantId, $userId, $paymentId, $reason): DonationPayment {
            $payment = DonationPayment::forTenant($tenantId)->with('allocations')->findOrFail($paymentId);
            $oldValues = $payment->toArray();

            if (in_array($payment->status, ['reversed', 'refunded'], true)) {
                throw new \RuntimeException('Payment is already reversed or refunded.');
            }

            foreach ($payment->allocations as $allocation) {
                $amount = (float) $allocation->amount;

                if ($allocation->allocatable_type === 'due') {
                    $due = ContributionDue::forTenant($tenantId)->find($allocation->allocatable_id);
                    if (!$due) {
                        continue;
                    }

                    $due->amount_paid = max(0, (float) $due->amount_paid - $amount);
                    $due->status = (float) $due->amount_paid <= 0 ? 'pending' : 'partially_paid';
                    $due->updated_by = $userId;
                    $due->save();
                    continue;
                }

                if ($allocation->allocatable_type === 'project_installment') {
                    $installment = ProjectInstallmentDue::forTenant($tenantId)->find($allocation->allocatable_id);
                    if ($installment) {
                        $this->projectInstallmentService->reversePayment($tenantId, $userId, $installment, $amount);
                    }
                    continue;
                }

                if ($allocation->allocatable_type === 'project') {
                    $project = DonationProject::forTenant($tenantId)->find($allocation->allocatable_id);
                    if ($project) {
                        $familyId = $payment->family_id;
                        if ($familyId) {
                            $this->projectService->reverseCollection($tenantId, $project, $familyId, $amount);
                        } else {
                            $project->raised_amount = max(0, round((float) $project->raised_amount - $amount, 2));
                            $project->save();
                        }
                    }
                    continue;
                }

                if ($allocation->allocatable_type === 'donation') {
                    $donation = Donation::forTenant($tenantId)->find($allocation->allocatable_id);
                    if ($donation) {
                        $this->donationBalanceService->reversePayment($userId, $donation, $amount);
                    }
                }
            }

            $payment->status = 'reversed';
            $payment->notes = trim(($payment->notes ?? '').' Reversal reason: '.$reason);
            $payment->updated_by = $userId;
            $payment->save();

            PaymentReversal::create([
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
                'reason' => $reason,
                'amount' => $payment->amount,
                'reversed_at' => now()->toDateString(),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->auditService->log(
                $tenantId,
                'payment.reversed',
                'payment',
                $payment->id,
                $oldValues,
                $payment->toArray(),
                ['reason' => $reason]
            );

            return $payment;
        });
    }

    private function buildPaymentNumber(int $tenantId): string
    {
        $datePart = now()->format('Ymd');
        $count = DonationPayment::forTenant($tenantId)->whereDate('created_at', now()->toDateString())->count() + 1;
        return sprintf('PAY-%d-%s-%04d', $tenantId, $datePart, $count);
    }

    private function isValidAllocatable(int $tenantId, string $type, string $id): bool
    {
        return match ($type) {
            'due' => ContributionDue::forTenant($tenantId)->where('id', $id)->exists(),
            'donation' => Donation::forTenant($tenantId)->where('id', $id)->exists(),
            'project' => DonationProject::forTenant($tenantId)->where('id', $id)->exists(),
            'project_installment' => ProjectInstallmentDue::forTenant($tenantId)->where('id', $id)->exists(),
            'plan' => ContributionPlan::forTenant($tenantId)->where('id', $id)->exists(),
            'fund' => Fund::forTenant($tenantId)->where('id', $id)->exists(),
            default => false,
        };
    }
}
