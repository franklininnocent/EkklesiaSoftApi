<?php

namespace Modules\Notifications\Services;

use Modules\Authentication\Models\User;
use Modules\Notifications\Support\InboxContext;
use Modules\Notifications\Support\InboxScope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InboxContextResolver
{
    public function forUser(User $user): InboxContext
    {
        if ($user->tenant_id !== null) {
            return new InboxContext(
                scope: InboxScope::Tenant,
                tenantId: (int) $user->tenant_id,
                userId: (int) $user->id,
            );
        }

        return new InboxContext(
            scope: InboxScope::Platform,
            tenantId: null,
            userId: (int) $user->id,
        );
    }

    public function assertRouteMatches(InboxContext $context, string $routePrefix): void
    {
        $expected = $context->scope === InboxScope::Tenant ? 'tenant' : 'admin';

        if ($routePrefix !== $expected) {
            throw new NotFoundHttpException('Notification inbox not found.');
        }
    }
}
