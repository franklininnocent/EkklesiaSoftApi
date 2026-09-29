<?php

namespace Modules\Tenants\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Modules\Authentication\Models\User;
use Modules\Tenants\Services\SupportSessionAuthorizationService;
use Modules\Tenants\Support\TenantContext;

/**
 * Controller guard for parish-product APIs used by tenant staff or elevated support sessions.
 *
 * Platform operators without an active owned support session must not access parish data.
 */
trait VerifiesParishProductAccess
{
    protected function verifyParishProductUser(
        User $user,
        string $noContextMessage = 'Tenant context required.',
        string $ekklesiaDeniedMessage = 'Parish context is required. Start a Support Center session for the parish you are helping.',
    ): ?JsonResponse {
        $context = app(TenantContext::class);

        if ($context->effectiveTenantId() === null) {
            return response()->json([
                'success' => false,
                'message' => $noContextMessage,
            ], 403);
        }

        if (app(SupportSessionAuthorizationService::class)->grantsTenantProductAccess($user)) {
            return null;
        }

        if (method_exists($user, 'hasEkklesiaRole') && $user->hasEkklesiaRole()) {
            return response()->json([
                'success' => false,
                'message' => $ekklesiaDeniedMessage,
            ], 403);
        }

        return null;
    }
}
