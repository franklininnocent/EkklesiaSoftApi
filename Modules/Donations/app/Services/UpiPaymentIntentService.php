<?php

namespace Modules\Donations\Services;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Modules\Donations\Models\DonationSetting;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;

class UpiPaymentIntentService
{
    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId, float $amount, ?string $familyId = null, ?string $note = null): array
    {
        if ($amount <= 0) {
            return [
                'available' => false,
                'message' => 'Amount must be greater than zero.',
            ];
        }

        $settings = DonationSetting::forTenant($tenantId)->first();
        $metadata = is_array($settings?->metadata) ? $settings->metadata : [];
        $vpa = trim((string) ($metadata['upi_vpa'] ?? ''));
        $payeeName = trim((string) ($metadata['upi_payee_name'] ?? ''));

        if ($payeeName === '') {
            $payeeName = Tenant::query()->find($tenantId)?->name ?? 'Church';
        }

        if ($vpa === '') {
            return [
                'available' => false,
                'message' => 'UPI VPA is not configured in donation settings.',
            ];
        }

        $currency = $settings?->default_currency ?? 'INR';
        $transactionNote = $this->buildTransactionNote($tenantId, $familyId, $note);

        $query = http_build_query([
            'pa' => $vpa,
            'pn' => $payeeName,
            'am' => number_format($amount, 2, '.', ''),
            'cu' => $currency,
            'tn' => $transactionNote,
        ], '', '&', PHP_QUERY_RFC3986);

        $upiUri = 'upi://pay?' . $query;

        return [
            'available' => true,
            'upi_uri' => $upiUri,
            'qr_data_uri' => $this->buildQrDataUri($upiUri),
            'vpa' => $vpa,
            'payee_name' => $payeeName,
            'amount' => round($amount, 2),
            'currency' => $currency,
            'transaction_note' => $transactionNote,
        ];
    }

    private function buildQrDataUri(string $payload): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            data: $payload,
            size: 240,
            margin: 8,
        );

        return $builder->build()->getDataUri();
    }

    private function buildTransactionNote(int $tenantId, ?string $familyId, ?string $note): string
    {
        if ($note !== null && trim($note) !== '') {
            return mb_substr(trim($note), 0, 80);
        }

        if ($familyId) {
            $family = Family::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $familyId)
                ->first();

            if ($family) {
                return mb_substr('Contribution ' . ($family->family_code ?: $family->family_name), 0, 80);
            }
        }

        return 'Church contribution';
    }
}
