<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\Donation;
use Modules\Donations\Support\MoneyMath;

class DonationEntryBalanceService
{
    public function applyPayment(int $userId, Donation $donation, string|float|int $amount): void
    {
        $collected = MoneyMath::add($donation->collected_amount ?? 0, $amount);
        $pledged = MoneyMath::normalize($donation->pledged_amount ?? 0);
        if (MoneyMath::isPositive($pledged) && MoneyMath::compare($collected, $pledged) > 0) {
            $collected = $pledged;
        }

        $donation->collected_amount = $collected;
        $donation->status = $this->statusFromCollected($donation);
        if (! $donation->received_at) {
            $donation->received_at = now()->toDateString();
        }
        $donation->updated_by = $userId;
        $donation->save();
    }

    public function reversePayment(int $userId, Donation $donation, string|float|int $amount): void
    {
        $donation->collected_amount = MoneyMath::floorAtZero(
            MoneyMath::subtract($donation->collected_amount ?? 0, $amount)
        );
        $donation->status = $this->statusFromCollected($donation);
        $donation->updated_by = $userId;
        $donation->save();
    }

    private function statusFromCollected(Donation $donation): string
    {
        $current = (string) $donation->status;
        if ($current === 'cancelled') {
            return 'cancelled';
        }

        $pledged = MoneyMath::normalize($donation->pledged_amount ?? 0);
        $collected = MoneyMath::normalize($donation->collected_amount ?? 0);

        if (! MoneyMath::isPositive($collected)) {
            return MoneyMath::isPositive($pledged) ? 'pledged' : 'pledged';
        }

        if (MoneyMath::isPositive($pledged) && MoneyMath::compare($collected, $pledged) >= 0) {
            return 'paid';
        }

        return 'partially_paid';
    }
}
