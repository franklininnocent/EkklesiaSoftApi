<?php

namespace Modules\EcclesiasticalData\Policies;

use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Policies\Concerns\AuthorizesEcclesiasticalPermission;

class BishopManagementPolicy
{
    use AuthorizesEcclesiasticalPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowsPlatform($user, 'bishops.view');
    }

    public function view(User $user, BishopManagement $bishop): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allowsPlatform($user, 'bishops.create');
    }

    public function update(User $user, BishopManagement $bishop): bool
    {
        return $this->allowsPlatform($user, 'bishops.update');
    }

    public function delete(User $user, BishopManagement $bishop): bool
    {
        return $this->allowsPlatform($user, 'bishops.archive');
    }

    public function manageAppointments(User $user, BishopManagement $bishop): bool
    {
        return $this->allowsPlatform($user, 'bishops.manage_appointments');
    }

    public function manageImages(User $user, BishopManagement $bishop): bool
    {
        return $this->allowsPlatform($user, 'bishops.manage_images');
    }

    public function viewAudit(User $user, BishopManagement $bishop): bool
    {
        return $this->allowsPlatform($user, 'bishops.view_audit');
    }
}
