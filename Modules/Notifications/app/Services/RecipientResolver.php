<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Collection;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Notifications\Models\NotificationDefinition;
use Modules\Notifications\Support\InboxScope;
use Modules\Notifications\Support\NotificationIntent;

class RecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function resolve(NotificationDefinition $definition, NotificationIntent $intent): Collection
    {
        $strategy = $definition->recipient_strategy;
        $users = match (true) {
            $strategy === 'explicit' => $this->explicit($intent),
            $strategy === 'primary_admins' => $this->primaryAdmins($intent),
            $strategy === 'assignee' => $this->assignee($intent),
            str_starts_with($strategy, 'permission:') => $this->byPermissionFromStrategy($strategy, $intent),
            default => collect(),
        };

        if ($intent->explicitRecipientIds !== []) {
            $extra = User::query()
                ->whereIn('id', $intent->explicitRecipientIds)
                ->where('active', 1)
                ->get();
            $users = $users->merge($extra);
        }

        return $users->unique('id')->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function explicit(NotificationIntent $intent): Collection
    {
        if ($intent->explicitRecipientIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $intent->explicitRecipientIds)
            ->where('active', 1)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function assignee(NotificationIntent $intent): Collection
    {
        $assigneeId = $intent->data['assignee_user_id'] ?? null;
        if (! $assigneeId) {
            return collect();
        }

        return User::query()->where('id', (int) $assigneeId)->where('active', 1)->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function primaryAdmins(NotificationIntent $intent): Collection
    {
        if ($intent->tenantId === null) {
            return collect();
        }

        return User::query()
            ->where('tenant_id', $intent->tenantId)
            ->where('active', 1)
            ->where(function ($q) use ($intent) {
                $q->where('is_primary_admin', 1)
                    ->orWhereHas('roles', function ($roles) use ($intent) {
                        $roles->where('name', Role::TENANT_ADMINISTRATOR)
                            ->where('tenant_id', $intent->tenantId)
                            ->where('active', 1)
                            ->whereNull('deleted_at');
                    });
            })
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function byPermissionFromStrategy(string $strategy, NotificationIntent $intent): Collection
    {
        if (! str_starts_with($strategy, 'permission:')) {
            return collect();
        }

        $permission = substr($strategy, strlen('permission:'));

        return $this->byPermission($intent, $permission);
    }

    /**
     * @return Collection<int, User>
     */
    private function byPermission(NotificationIntent $intent, string $permission): Collection
    {
        if ($permission === '') {
            return collect();
        }

        $query = User::query()->where('active', 1);

        if ($intent->scope === InboxScope::Tenant && $intent->tenantId !== null) {
            $query->where('tenant_id', $intent->tenantId);
        } else {
            $query->whereNull('tenant_id');
        }

        return $query->where(function ($outer) use ($permission, $intent) {
            $outer->whereHas('permissions', fn ($q) => $q->where('name', $permission))
                ->orWhereHas('roles', function ($roles) use ($permission) {
                    $roles->where('roles.active', 1)
                        ->whereNull('roles.deleted_at')
                        ->whereHas('permissions', fn ($q) => $q->where('name', $permission));
                });

            if ($intent->scope === InboxScope::Platform) {
                $outer->orWhere(function ($superAdmin) {
                    $superAdmin->whereHas('roles', function ($roles) {
                        $roles->where('name', Role::SUPER_ADMIN)
                            ->where('active', 1)
                            ->whereNull('deleted_at');
                    })->orWhereHas('role', function ($role) {
                        $role->where('name', Role::SUPER_ADMIN)
                            ->where('active', 1)
                            ->whereNull('deleted_at');
                    });
                });
            }
        })->get();
    }
}
