<?php

namespace Modules\Tenants\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\Tenants\Support\ActiveSupportSession;

interface SupportSessionResolver
{
    /**
     * Resolve an active support session for the authenticated actor, if any.
     */
    public function resolve(Request $request, Authenticatable $actor): ?ActiveSupportSession;
}
