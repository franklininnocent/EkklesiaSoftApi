<?php

namespace Modules\Family\Observers;

use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Support\TenantCacheVersion;

/**
 * Parish participation / gap analytics depend on family membership; bump tenant cache on changes.
 */
final class InvalidateSacramentDashboardCacheObserver
{
    public function saved(FamilyMember|Family $model): void
    {
        $this->bumpTenant($model->tenant_id);
    }

    public function deleted(FamilyMember|Family $model): void
    {
        $this->bumpTenant($model->tenant_id);
    }

    public function restored(FamilyMember|Family $model): void
    {
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
