<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Models\DonationSetting;

class DonationReceiptService
{
    public function buildReceiptNumber(int $tenantId): string
    {
        $settings = DonationSetting::forTenant($tenantId)->first();
        $finYear = now()->format('Y');
        $count = DonationReceipt::forTenant($tenantId)->whereYear('created_at', now()->year)->count() + 1;

        if ($settings?->receipt_prefix_enabled && $settings->receipt_prefix) {
            return sprintf('%s-%d-%s-%06d', $settings->receipt_prefix, $tenantId, $finYear, $count);
        }

        return sprintf('RCPT-%d-%s-%06d', $tenantId, $finYear, $count);
    }

    public function createReceipt(int $tenantId, int $userId, DonationPayment $payment): DonationReceipt
    {
        return DonationReceipt::create([
            'tenant_id' => $tenantId,
            'payment_id' => $payment->id,
            'receipt_number' => $this->buildReceiptNumber($tenantId),
            'issued_on' => now()->toDateString(),
            'issued_by' => $userId,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReceiptPayload(int $tenantId, DonationPayment $payment, DonationReceipt $receipt): array
    {
        $payment->loadMissing(['allocations', 'family', 'donor']);

        $settings = DonationSetting::forTenant($tenantId)->first();
        $taxDeductibleTotal = 0.0;
        $lineItems = [];

        foreach ($payment->allocations as $allocation) {
            if ($allocation->allocatable_type !== 'donation') {
                continue;
            }

            $donation = \Modules\Donations\Models\Donation::forTenant($tenantId)
                ->with('category')
                ->find($allocation->allocatable_id);

            if (!$donation) {
                continue;
            }

            $amount = (float) $allocation->amount;
            $isTaxDeductible = (bool) ($donation->category?->is_tax_deductible ?? false);

            if ($isTaxDeductible) {
                $taxDeductibleTotal += $amount;
            }

            $lineItems[] = [
                'description' => $donation->title ?: ($donation->category?->name ?? 'Voluntary Donation'),
                'category' => $donation->category?->name,
                'amount' => $amount,
                'is_tax_deductible' => $isTaxDeductible,
            ];
        }

        $isAnonymous = (bool) $payment->is_anonymous;

        return [
            'receipt' => $receipt,
            'payment' => $payment,
            'settings' => $settings,
            'payer' => [
                'name' => $isAnonymous ? 'Anonymous Donor' : $payment->payer_name,
                'email' => $isAnonymous ? null : $payment->payer_email,
                'phone' => $isAnonymous ? null : $payment->payer_phone,
                'is_anonymous' => $isAnonymous,
            ],
            'line_items' => $lineItems,
            'tax_acknowledgement' => [
                'registration_number' => $settings?->tax_registration_number,
                'note' => $settings?->tax_acknowledgement_note,
                'tax_deductible_amount' => round($taxDeductibleTotal, 2),
                'has_tax_deductible_portion' => $taxDeductibleTotal > 0,
            ],
            'totals' => [
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency ?? 'INR',
            ],
        ];
    }
}
