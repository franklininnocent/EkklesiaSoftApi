<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\UpdateDonationSettingsRequest;
use Modules\Donations\Models\DonationSetting;
use Modules\Donations\Services\DonationAuditService;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;
use Modules\Tenants\Support\TenantContext;

class DonationSettingsController extends Controller
{
    public function __construct(private readonly DonationAuditService $auditService) {}

    public function show(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $settings = DonationSetting::forTenant($tenantId)->first();
        $data = $settings?->toArray() ?? [];
        $code = app(ChurchCurrencyResolver::class)->currencyCodeForTenantId($tenantId);
        if ($code !== null) {
            $data['default_currency'] = $code;
        }

        $fyConfig = app(ChurchFinancialPeriodResolver::class)->resolveFyStartConfig($tenantId);
        $fyPeriod = app(ChurchFinancialPeriodResolver::class)->currentFiscalYear($tenantId);
        $data['financial_year_resolved'] = [
            'source' => $fyConfig['source'],
            'start_month' => str_pad((string) $fyConfig['month'], 2, '0', STR_PAD_LEFT),
            'start_day' => str_pad((string) $fyConfig['day'], 2, '0', STR_PAD_LEFT),
            'current_label' => $fyPeriod->label,
            'current_start' => $fyPeriod->start,
            'current_end' => $fyPeriod->end,
        ];

        return response()->json([
            'success' => true,
            'data' => $data === [] ? null : $data,
        ]);
    }

    public function update(UpdateDonationSettingsRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $payload = $request->validated();
        $resolvedCurrency = app(ChurchCurrencyResolver::class)->currencyCodeForTenantId($tenantId);
        if ($resolvedCurrency !== null) {
            $payload['default_currency'] = $resolvedCurrency;
        }

        $settings = DonationSetting::forTenant($tenantId)->first();
        $fySource = $payload['financial_year_source'] ?? $settings?->financial_year_source ?? 'country';
        $payload['financial_year_source'] = $fySource === 'tenant' ? 'tenant' : 'country';
        $oldValues = $settings?->toArray();

        if ($settings) {
            $payload['updated_by'] = $userId;
            $settings->update($payload);
        } else {
            $payload['tenant_id'] = $tenantId;
            $payload['created_by'] = $userId;
            $payload['updated_by'] = $userId;
            $settings = DonationSetting::create($payload);
        }

        if ($payload['financial_year_source'] === 'country') {
            app(ChurchFinancialPeriodResolver::class)->syncDerivedFyStartColumns($tenantId);
            $settings->refresh();
        }

        $this->auditService->log($tenantId, 'settings.updated', 'donation_settings', $settings->id, $oldValues, $settings->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Donation settings saved.',
            'data' => $settings,
        ]);
    }
}
