<?php

namespace Modules\Subscriptions\Services\Billing;

use App\Support\MoneyMath;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\BillingAllocation;
use Modules\Subscriptions\Models\BillingCredit;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingOfflineClaim;
use Modules\Subscriptions\Models\BillingReceipt;
use Modules\Subscriptions\Models\TenantBillingAgreement;
use Modules\Subscriptions\Services\SubscriptionPolicyService;

final class OfflineReceiptAllocationService
{
    public function __construct(
        private readonly BillingAuditService $audit,
        private readonly BillingFulfillmentService $fulfillment,
        private readonly SubscriptionPolicyService $policies,
        private readonly BillingNotificationPublisher $notifications,
    ) {}

    public function reject(BillingOfflineClaim $claim, User $reviewer, string $reason): BillingOfflineClaim
    {
        if (! in_array($claim->status, [BillingOfflineClaim::STATUS_PENDING, BillingOfflineClaim::STATUS_INFO_REQUIRED], true)) {
            throw SubscriptionException::invalidState('This claim cannot be rejected.');
        }

        $claim->forceFill([
            'status' => BillingOfflineClaim::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'rejection_reason' => $reason,
        ])->save();

        $this->audit->record('offline_claim_rejected', $reviewer->id, null, (int) $claim->tenant_id, $reason, null, [
            'claim_id' => $claim->id,
        ]);

        $this->notifications->offlineClaimDecided($claim->fresh(), 'Rejected');

        return $claim->fresh();
    }

    public function requestInformation(BillingOfflineClaim $claim, User $reviewer, string $message): BillingOfflineClaim
    {
        if ($claim->status !== BillingOfflineClaim::STATUS_PENDING) {
            throw SubscriptionException::invalidState('Only pending claims can request more information.');
        }

        $claim->forceFill([
            'status' => BillingOfflineClaim::STATUS_INFO_REQUIRED,
            'reviewed_by' => $reviewer->id,
            'rejection_reason' => $message,
        ])->save();

        $this->audit->record('offline_claim_info_requested', $reviewer->id, null, (int) $claim->tenant_id, $message, null, [
            'claim_id' => $claim->id,
        ]);

        $this->notifications->offlineClaimDecided($claim->fresh(), 'More information needed');

        return $claim->fresh();
    }

    public function approve(BillingOfflineClaim $claim, User $approver, ?User $secondApprover = null): BillingReceipt
    {
        if ($claim->status !== BillingOfflineClaim::STATUS_PENDING && $claim->status !== BillingOfflineClaim::STATUS_INFO_REQUIRED) {
            throw SubscriptionException::invalidState('This claim cannot be approved.');
        }

        if ((int) $claim->submitted_by_user_id === (int) $approver->id) {
            throw SubscriptionException::billingDenied('reviewer_conflict', 'The approver cannot be the same person who submitted the claim.');
        }

        $threshold = (string) ($this->policies->get('billing.offline_dual_approval_threshold') ?? '50000.00');
        if (MoneyMath::compare((string) $claim->claimed_amount, $threshold) >= 0) {
            if ($secondApprover === null || (int) $secondApprover->id === (int) $approver->id) {
                throw SubscriptionException::billingDenied('dual_approval_required', 'A second approver is required for this amount.');
            }
            if ((int) $secondApprover->id === (int) $claim->submitted_by_user_id) {
                throw SubscriptionException::billingDenied('reviewer_conflict', 'The second approver cannot be the claimant.');
            }
        }

        if ($claim->reference_number) {
            $duplicate = BillingReceipt::query()
                ->where('tenant_id', $claim->tenant_id)
                ->where('external_reference', $claim->reference_number)
                ->where('status', '!=', BillingReceipt::STATUS_REVERSED)
                ->exists();
            if ($duplicate) {
                $claim->forceFill(['status' => BillingOfflineClaim::STATUS_DUPLICATE])->save();
                throw SubscriptionException::billingDenied('duplicate_reference', 'This reference number was already recorded.');
            }
        }

        $invoice = $this->resolveInvoice($claim);
        if ($invoice === null) {
            throw SubscriptionException::invalidState('An invoice is required to allocate this payment.');
        }

        $receipt = DB::transaction(function () use ($claim, $approver, $secondApprover, $invoice) {
            $lockedInvoice = BillingInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $outstanding = $lockedInvoice->outstandingAmount();
            if (! MoneyMath::isPositive($outstanding)) {
                throw SubscriptionException::invalidState('This invoice has no outstanding balance.');
            }

            $allocate = MoneyMath::min((string) $claim->claimed_amount, $outstanding);
            $excess = MoneyMath::subtract((string) $claim->claimed_amount, $allocate);

            $receipt = BillingReceipt::query()->create([
                'tenant_id' => $claim->tenant_id,
                'source' => BillingReceipt::SOURCE_OFFLINE,
                'billing_offline_claim_id' => $claim->id,
                'amount' => $claim->claimed_amount,
                'currency_code' => $claim->currency_code,
                'status' => BillingReceipt::STATUS_CONFIRMED,
                'external_reference' => $claim->reference_number,
                'confirmed_by' => $approver->id,
                'confirmed_at' => now(),
            ]);

            BillingAllocation::query()->create([
                'billing_receipt_id' => $receipt->id,
                'billing_invoice_id' => $lockedInvoice->id,
                'amount' => $allocate,
            ]);

            $newPaid = MoneyMath::add((string) $lockedInvoice->amount_paid, $allocate);
            $lockedInvoice->forceFill(['amount_paid' => $newPaid]);

            if (MoneyMath::compare($newPaid, (string) $lockedInvoice->gross_amount) >= 0) {
                $lockedInvoice->forceFill(['status' => BillingInvoice::STATUS_PAID]);
            } else {
                $lockedInvoice->forceFill(['status' => BillingInvoice::STATUS_PARTIALLY_PAID]);
            }
            $lockedInvoice->save();

            $receipt->forceFill([
                'status' => MoneyMath::isPositive($excess)
                    ? BillingReceipt::STATUS_PARTIALLY_ALLOCATED
                    : BillingReceipt::STATUS_FULLY_ALLOCATED,
            ])->save();

            if (MoneyMath::isPositive($excess)) {
                BillingCredit::query()->create([
                    'tenant_id' => $claim->tenant_id,
                    'billing_receipt_id' => $receipt->id,
                    'amount' => $excess,
                    'amount_remaining' => $excess,
                    'currency_code' => $claim->currency_code,
                ]);
            }

            $claim->forceFill([
                'status' => BillingOfflineClaim::STATUS_APPROVED,
                'reviewed_by' => $approver->id,
                'second_approved_by' => $secondApprover?->id,
            ])->save();

            $this->maybeFulfillFromInvoice($lockedInvoice, $receipt, $approver->id);

            $this->audit->record('offline_claim_approved', $approver->id, null, (int) $claim->tenant_id, null, null, [
                'claim_id' => $claim->id,
                'receipt_id' => $receipt->id,
                'invoice_id' => $lockedInvoice->id,
            ]);

            return $receipt;
        });

        $this->notifications->offlineClaimDecided($claim->fresh(), 'Approved');

        return $receipt;
    }

    private function resolveInvoice(BillingOfflineClaim $claim): ?BillingInvoice
    {
        if ($claim->billing_invoice_id) {
            return BillingInvoice::query()
                ->where('tenant_id', $claim->tenant_id)
                ->whereKey($claim->billing_invoice_id)
                ->first();
        }

        if ($claim->billing_order_id) {
            return BillingInvoice::query()
                ->where('tenant_id', $claim->tenant_id)
                ->where('billing_order_id', $claim->billing_order_id)
                ->first();
        }

        return null;
    }

    private function maybeFulfillFromInvoice(BillingInvoice $invoice, BillingReceipt $receipt, int $actorId): void
    {
        if ($invoice->status !== BillingInvoice::STATUS_PAID) {
            $agreement = TenantBillingAgreement::query()
                ->where('tenant_id', $invoice->tenant_id)
                ->where('active', true)
                ->where('entitlement_on_partial', TenantBillingAgreement::ENTITLEMENT_ON_FIRST_RECEIPT)
                ->first();
            if ($agreement === null) {
                return;
            }
        }

        $order = $invoice->order;
        if ($order === null || $order->status === \Modules\Subscriptions\Models\BillingOrder::STATUS_FULFILLED) {
            return;
        }

        $attempt = \Modules\Subscriptions\Models\BillingPaymentAttempt::query()->create([
            'billing_order_id' => $order->id,
            'provider_payment_id' => 'offline_'.$receipt->id,
            'status' => \Modules\Subscriptions\Models\BillingPaymentAttempt::STATUS_CAPTURED,
            'amount' => $invoice->gross_amount,
            'currency_code' => $invoice->currency_code,
            'environment' => $order->environment,
            'verified_at' => now(),
            'provider_payload_summary' => ['source' => 'offline_receipt', 'receipt_id' => $receipt->id],
        ]);

        $order->forceFill(['status' => \Modules\Subscriptions\Models\BillingOrder::STATUS_CAPTURED])->save();
        $this->fulfillment->fulfillFromOrder($order, $attempt, $actorId);
    }
}
