<?php

namespace Modules\Subscriptions\Support;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;

/** Resolves a platform user for catalog Artisan commands (audit attribution). */
final class SubscriptionConsoleActor
{
    public static function resolve(): User
    {
        $roleId = Role::query()->where('name', Role::EKKLESIA_ADMIN)->whereNull('tenant_id')->value('id');
        if ($roleId) {
            $existing = User::query()->where('role_id', $roleId)->where('active', 1)->orderBy('id')->first();
            if ($existing) {
                return $existing;
            }
        }

        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Admin',
                'level' => Role::LEVEL_EKKLESIA_ADMIN,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        return User::factory()->create([
            'user_type' => null,
            'tenant_id' => null,
            'is_primary_admin' => false,
            'role_id' => $role->id,
            'active' => 1,
        ]);
    }
}
