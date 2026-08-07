<?php

namespace Modules\Donations\Models\Concerns;

use Modules\Tenants\Models\Concerns\BelongsToTenant as SharedBelongsToTenant;

/**
 * @deprecated Prefer Modules\Tenants\Models\Concerns\BelongsToTenant directly.
 * Kept as a thin alias so existing model imports stay stable.
 */
trait BelongsToTenant
{
    use SharedBelongsToTenant;
}
