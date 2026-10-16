<?php

namespace Modules\EcclesiasticalData\Policies;

use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Policies\Concerns\AuthorizesEcclesiasticalPermission;

class DioceseManagementPolicy
{
    use AuthorizesEcclesiasticalPermission;

    public function viewAny(User $user): bool
    {
        return $this->allowsPlatform($user, 'dioceses.view');
    }

    public function view(User $user, DioceseManagement $diocese): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allowsPlatform($user, 'dioceses.create');
    }

    public function update(User $user, DioceseManagement $diocese): bool
    {
        return $this->allowsPlatform($user, 'dioceses.update');
    }

    public function delete(User $user, DioceseManagement $diocese): bool
    {
        return $this->allowsPlatform($user, 'dioceses.delete');
    }
}
