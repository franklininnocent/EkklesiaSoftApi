<?php

namespace Modules\Tenants\Database\Seeders\Support;

/**
 * Shared marker for tenant-wide demo fixtures (idempotency + verification).
 */
final class TenantDemoMarkers
{
    public const MARKER = 'tenant_demo_v1';

    public const ENV_TENANT_ID = 'TENANT_DEMO_TENANT_ID';

    public const ENV_SKIP_FAMILIES = 'TENANT_DEMO_SKIP_FAMILIES';

    public const ENV_SKIP_STEWARDSHIP = 'TENANT_DEMO_SKIP_STEWARDSHIP';

    public const ENV_SKIP_BCC_LEADERSHIP = 'TENANT_DEMO_SKIP_BCC_LEADERSHIP';

    public const ENV_DRY_RUN = 'TENANT_DEMO_DRY_RUN';
}
