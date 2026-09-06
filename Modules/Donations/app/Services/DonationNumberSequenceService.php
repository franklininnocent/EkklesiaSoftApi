<?php

namespace Modules\Donations\Services;

use Carbon\Carbon;
use Modules\Donations\Models\DonationNumberSequence;
use Modules\Donations\Models\DonationSetting;

class DonationNumberSequenceService
{
    public function nextReceiptNumber(int $tenantId): string
    {
        $settings = DonationSetting::forTenant($tenantId)->first();
        $period = $this->financialYearLabel($settings);
        $sequence = $this->next($tenantId, 'receipt', $period);
        $prefix = ($settings?->receipt_prefix_enabled && $settings->receipt_prefix)
            ? $settings->receipt_prefix
            : 'RCPT';

        return sprintf('%s-%d-%s-%06d', $prefix, $tenantId, $period, $sequence);
    }

    public function nextPaymentNumber(int $tenantId): string
    {
        $period = now()->format('Ymd');
        $sequence = $this->next($tenantId, 'payment', $period);

        return sprintf('PAY-%d-%s-%04d', $tenantId, $period, $sequence);
    }

    public function financialYearLabel(?DonationSetting $settings): string
    {
        $month = (int) ($settings?->financial_year_start_month ?? 1);
        $day = (int) ($settings?->financial_year_start_day ?? 1);
        $now = Carbon::now();
        $fyStart = $now->copy()->setMonth(max(1, $month))->setDay(max(1, $day))->startOfDay();
        if ($now->lt($fyStart)) {
            $fyStart->subYear();
        }

        if ($month === 1 && $day === 1) {
            return (string) $fyStart->year;
        }

        return $fyStart->format('Y').'-'.$fyStart->copy()->addYear()->format('y');
    }

    private function next(int $tenantId, string $kind, string $period): int
    {
        $row = DonationNumberSequence::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', $kind)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            DonationNumberSequence::query()->create([
                'tenant_id' => $tenantId,
                'kind' => $kind,
                'period' => $period,
                'last_value' => 0,
            ]);

            $row = DonationNumberSequence::query()
                ->where('tenant_id', $tenantId)
                ->where('kind', $kind)
                ->where('period', $period)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $row->last_value = (int) $row->last_value + 1;
        $row->save();

        return (int) $row->last_value;
    }
}
