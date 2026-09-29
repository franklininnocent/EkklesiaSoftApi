<?php

namespace Modules\Tenants\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\Tenants\Contracts\SupportSessionResolver;

/**
 * Phase 0 default: no support session overlay. Effective tenant === home tenant.
 */
final class NullSupportSessionResolver implements SupportSessionResolver
{
    public function resolve(Request $request, Authenticatable $actor): ?ActiveSupportSession
    {
        return null;
    }
}
