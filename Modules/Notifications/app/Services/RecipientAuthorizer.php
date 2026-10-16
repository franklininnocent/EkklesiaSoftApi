<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Collection;
use Modules\Authentication\Models\User;
use Modules\Notifications\Models\NotificationDefinition;
use Modules\Notifications\Support\InboxScope;
use Modules\Notifications\Support\NotificationIntent;

class RecipientAuthorizer
{
    /**
     * @param  Collection<int, User>  $candidates
     * @return list<int>
     */
    public function authorize(
        Collection $candidates,
        NotificationDefinition $definition,
        NotificationIntent $intent,
    ): array {
        $cap = (int) config('notifications.recipient_cap', 100);
        $actorId = $intent->actor?->id;
        $authorized = [];

        foreach ($candidates as $user) {
            if (! $this->matchesScope($user, $intent)) {
                continue;
            }

            if ($actorId !== null && (int) $user->id === (int) $actorId && ! $definition->notify_actor) {
                continue;
            }

            $authorized[] = (int) $user->id;

            if (count($authorized) >= $cap) {
                break;
            }
        }

        if (count($authorized) >= $cap) {
            $overflow = $this->overflowPrimaryAdmins($intent);
            foreach ($overflow as $id) {
                if (! in_array($id, $authorized, true)) {
                    $authorized[] = $id;
                }
            }
        }

        return array_values(array_unique($authorized));
    }

    private function matchesScope(User $user, NotificationIntent $intent): bool
    {
        if ($intent->scope === InboxScope::Platform) {
            return $user->tenant_id === null;
        }

        return $user->tenant_id !== null && (int) $user->tenant_id === (int) $intent->tenantId;
    }

    /**
     * @return list<int>
     */
    private function overflowPrimaryAdmins(NotificationIntent $intent): array
    {
        if ($intent->tenantId === null) {
            return [];
        }

        return User::query()
            ->where('tenant_id', $intent->tenantId)
            ->where('active', 1)
            ->where('is_primary_admin', 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
