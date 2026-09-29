<?php

namespace Modules\Donations\Services;

use Carbon\Carbon;
use Modules\Donations\Models\DonationNumberSequence;
use Modules\Donations\Models\DonationSetting;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;

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
        $tenantId = (int) ($settings?->tenant_id ?? 0);
        if ($tenantId <= 0) {
            return (string) Carbon::now()->year;
        }

        return app(ChurchFinancialPeriodResolver::class)
            ->currentFiscalYear($tenantId)
            ->key;
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
