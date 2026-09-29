<?php

namespace Modules\Tenants\Database\Seeders\Support;

use Modules\BCC\Models\BCC;
use Modules\Donations\Models\ContributionPlan;
use Modules\Family\Models\Family;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\ChurchSocialMedia;
use Modules\Tenants\Models\ChurchStatistic;

final class TenantDemoVerifier
{
    /**
     * @return list<string>
     */
    public static function verify(int $tenantId): array
    {
        $errors = [];

        if (BCC::query()->where('tenant_id', $tenantId)->count() < 1) {
            $errors[] = 'Expected at least one BCC.';
        }

        if (! ChurchProfile::query()->where('tenant_id', $tenantId)->exists()) {
            $errors[] = 'Church profile missing.';
        }

        if (ChurchSocialMedia::query()->where('tenant_id', $tenantId)->count() < 1) {
            $errors[] = 'Expected at least one social media row.';
        }

        if (ChurchStatistic::query()->where('tenant_id', $tenantId)->count() < 6) {
            $errors[] = 'Expected multi-month church statistics.';
        }

        if (PastoralCareRequest::query()->where('tenant_id', $tenantId)->where('notes', TenantDemoMarkers::MARKER)->count() < 4) {
            $errors[] = 'Expected pastoral care demo requests.';
        }

        $familyCount = Family::query()->where('tenant_id', $tenantId)->count();
        if ($familyCount > 0) {
            $withBcc = Family::query()->where('tenant_id', $tenantId)->whereNotNull('bcc_id')->count();
            if ($withBcc === 0) {
                $errors[] = 'Families exist but none are assigned to a BCC.';
            }
        }

        if (ContributionPlan::forTenant($tenantId)->where('code', 'like', 'DEMO_%')->exists()) {
            $paid = ContributionPlan::forTenant($tenantId)->where('code', 'DEMO_MONTHLY_PARISH')->exists();
            if (! $paid) {
                $errors[] = 'Stewardship demo monthly plan missing.';
            }
        }

        if (OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('remarks', TenantDemoMarkers::MARKER)
            ->count() < 1
            && $familyCount >= 1) {
            $errors[] = 'Expected ministries membership demo rows when families exist.';
        }

        return $errors;
    }
}
