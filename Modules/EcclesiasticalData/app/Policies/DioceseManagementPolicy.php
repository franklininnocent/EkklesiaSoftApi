<?php

namespace Modules\EcclesiasticalData\Policies;

use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\DioceseManagement;

class DioceseManagementPolicy
{
    /**
     * Determine if user can view any dioceses
     */
    public function viewAny(User $user): bool
    {
        return $user->is_primary_admin || 
               $user->role?->name === 'SuperAdmin' ||
               $user->permissions->contains('name', 'view_dioceses');
    }

    /**
     * Determine if user can view the diocese
     */
    public function view(User $user, DioceseManagement $diocese): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine if user can create dioceses
     */
    public function create(User $user): bool
    {
        return $user->is_primary_admin || 
               $user->role?->name === 'SuperAdmin' ||
               $user->permissions->contains('name', 'create_dioceses');
    }

    /**
     * Determine if user can update the diocese
     */
    public function update(User $user, DioceseManagement $diocese): bool
    {
        return $user->is_primary_admin || 
               $user->role?->name === 'SuperAdmin' ||
               $user->permissions->contains('name', 'edit_dioceses');
    }

    /**
     * Determine if user can delete the diocese
     */
    public function delete(User $user, DioceseManagement $diocese): bool
    {
        return $user->is_primary_admin || 
               $user->role?->name === 'SuperAdmin' ||
               $user->permissions->contains('name', 'delete_dioceses');
    }
}

