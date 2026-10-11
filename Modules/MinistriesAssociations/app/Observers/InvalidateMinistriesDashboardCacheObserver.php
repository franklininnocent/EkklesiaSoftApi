<?php

namespace Modules\MinistriesAssociations\Observers;

use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\MinistriesAssociations\Models\Position;
use Modules\Tenants\Support\TenantCacheVersion;

/**
 * Executive ministry KPIs are cached per tenant version; bump on organization graph changes.
 */
final class InvalidateMinistriesDashboardCacheObserver
{
    public function saved(
        Organization|OrganizationMembership|LeadershipTerm|Position|OrganizationType|OrganizationCategory $model
    ): void {
        $this->bumpTenant($model->tenant_id);
    }

    public function deleted(
        Organization|OrganizationMembership|LeadershipTerm|Position|OrganizationType|OrganizationCategory $model
    ): void {
        $this->bumpTenant($model->tenant_id);
    }

    public function restored(
        Organization|OrganizationMembership|LeadershipTerm|Position|OrganizationType|OrganizationCategory $model
    ): void {
        $this->bumpTenant($model->tenant_id);
    }

    private function bumpTenant(mixed $tenantId): void
    {
        $id = (int) $tenantId;
        if ($id > 0) {
            TenantCacheVersion::bump($id);
        }
    }
}
