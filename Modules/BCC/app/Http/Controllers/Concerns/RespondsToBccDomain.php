<?php

namespace Modules\BCC\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Modules\BCC\Exceptions\BccDomainException;
use Modules\Tenants\Support\TenantContext;

trait RespondsToBccDomain
{
    protected function tenantId(): int
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        if ($tenantId === null) {
            abort(403, 'Tenant context is required.');
        }

        return (int) $tenantId;
    }

    protected function domainError(BccDomainException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'errors' => $e->errors,
        ], $e->httpStatus);
    }
}
