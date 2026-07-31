<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\Donation;

class DonationEntryBalanceService
{
    public function applyPayment(int $userId, Donation $donation, float $amount): void
    {
        $donation->collected_amount = round((float) $donation->collected_amount + $amount, 2);
        $pledged = (float) $donation->pledged_amount;

        if ($pledged > 0) {
            $donation->status = $donation->collected_amount >= $pledged ? 'paid' : 'partially_paid';
        } else {
            $donation->status = 'paid';
        }

        if (!$donation->received_at) {
            $donation->received_at = now()->toDateString();
        }

        $donation->updated_by = $userId;
        $donation->save();
    }

    public function reversePayment(int $userId, Donation $donation, float $amount): void
    {
        $donation->collected_amount = max(0, round((float) $donation->collected_amount - $amount, 2));
        $pledged = (float) $donation->pledged_amount;

        if ($donation->collected_amount <= 0) {
            $donation->status = $pledged > 0 ? 'pledged' : 'cancelled';
        } elseif ($pledged > 0 && $donation->collected_amount < $pledged) {
            $donation->status = 'partially_paid';
        }

        $donation->updated_by = $userId;
        $donation->save();
    }
}
