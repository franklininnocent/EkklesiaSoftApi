<?php

namespace Modules\EcclesiasticalData\Policies;

use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\BishopManagement;

class BishopManagementPolicy
{
    /**
     * Determine if user can view any bishops
     */
    public function viewAny(User $user): bool
    {
        return $user->is_primary_admin || 
               $user->role?->name === 'SuperAdmin' ||
               $user->permissions->contains('name', 'view_bishops');
    }

    /**
     * Determine if user can view the bishop
     */
    public function view(User $user, BishopManagement $bishop): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine if user can create bishops
     */
    public function create(User $user): bool
    {
        return $user->is_primary_admin || 
               $user->role?->name === 'SuperAdmin' ||
               $user->permissions->contains('name', 'create_bishops');
    }

    /**
     * Determine if user can update the bishop
     */
    public function update(User $user, BishopManagement $bishop): bool
    {
        return $user->is_primary_admin || 
               $user->role?->name === 'SuperAdmin' ||
               $user->permissions->contains('name', 'edit_bishops');
    }

    /**
     * Determine if user can delete the bishop
     */
    public function delete(User $user, BishopManagement $bishop): bool
    {
        return $user->is_primary_admin || 
               $user->role?->name === 'SuperAdmin' ||
               $user->permissions->contains('name', 'delete_bishops');
    }
}

