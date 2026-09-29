<?php

namespace Modules\Donations\Services\Reports;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Authentication\Models\User;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Tenants\Contracts\TenantEntitlementGate;
use Modules\Tenants\Models\Tenant;

final class DonationReportAuthorization
{
    public function assertCanPreview(User $user): void
    {
        if (! $user->hasPermission('donations.reports')) {
            throw new AuthorizationException('You do not have access to this page.');
        }
    }

    public function assertCanExport(User $user): void
    {
        if (! $user->hasAnyPermission(['reports.export', 'donations.export', 'donations.reports'])) {
            throw new AuthorizationException('You do not have access to this page.');
        }
    }

    public function assertReportEntitlements(Tenant $tenant, string $reportType): void
    {
        if (
            $reportType === DonationReportCatalog::TYPE_PARISH_COMPARISON
            && ! DonationReportCatalog::tierMayUseParishComparison((string) ($tenant->tenant_tier ?? ''))
        ) {
            throw new AuthorizationException('Parish comparison is not available for this church.');
        }

        $definition = DonationReportCatalog::definition($reportType);
        if (! ($definition['requires_advanced'] ?? false)) {
            return;
        }

        $gate = app(TenantEntitlementGate::class);
        if (! $gate->allows($tenant, 'ADVANCED_FINANCIAL_REPORTING')) {
            throw new AuthorizationException('This report is included in larger plans.');
        }
    }
}
