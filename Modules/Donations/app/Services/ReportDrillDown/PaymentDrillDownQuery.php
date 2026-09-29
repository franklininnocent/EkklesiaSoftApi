<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Support\MoneyMath;

class PaymentDrillDownQuery
{
    /**
     * @return array{amount_total: float, record_count: int}
     */
    public function unfilteredSummary(Builder $query): array
    {
        $clone = clone $query;
        $amount = MoneyMath::toApiNumber((clone $clone)->sum('amount'));
        $count = (int) (clone $clone)->count();

        return ['amount_total' => $amount, 'record_count' => $count];
    }

    public function applyPaymentSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = '%'.addcslashes(mb_substr($search, 0, 80), '%_\\').'%';
        $likeOp = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(function (Builder $inner) use ($term, $likeOp): void {
            $inner->where('donation_payments.payer_name', $likeOp, $term)
                ->orWhere('donation_payments.payment_number', $likeOp, $term)
                ->orWhereHas('family', function (Builder $family) use ($term, $likeOp): void {
                    $family->where('family_name', $likeOp, $term)
                        ->orWhere('head_of_family', $likeOp, $term)
                        ->orWhere('family_code', $likeOp, $term);
                });
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function mapPaymentRows(iterable $rows): array
    {
        $items = [];
        foreach ($rows as $payment) {
            /** @var DonationPayment $payment */
            $family = $payment->family;
            $anonymous = (bool) $payment->is_anonymous;
            $items[] = [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'payment_date' => $payment->payment_date?->toDateString(),
                'amount' => MoneyMath::toApiNumber($payment->amount),
                'method' => $payment->method,
                'family_id' => $payment->family_id,
                'family_name' => $anonymous || ! $payment->family_id
                    ? 'Anonymous'
                    : ($family?->family_name ?? 'Family record unavailable'),
                'head_of_family' => $anonymous ? null : $family?->head_of_family,
                'family_code' => $anonymous ? null : $family?->family_code,
                'payer_name' => $anonymous ? 'Anonymous' : $payment->payer_name,
                'is_anonymous' => $anonymous,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function paymentColumns(): array
    {
        return [
            ['key' => 'payment_date', 'label' => 'Date'],
            ['key' => 'family', 'label' => 'Family'],
            ['key' => 'payer_name', 'label' => 'Payer'],
            ['key' => 'method', 'label' => 'Method'],
            ['key' => 'amount', 'label' => 'Amount'],
        ];
    }
}
