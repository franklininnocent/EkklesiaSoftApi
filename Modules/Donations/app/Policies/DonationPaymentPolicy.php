<?php

namespace Modules\Donations\Policies;

use Modules\Authentication\Models\User;
use Modules\Donations\Models\DonationPayment;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;

class DonationPaymentPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'donations.view');
    }

    public function view(User $user, DonationPayment $payment): bool
    {
        return $this->allows($user, 'donations.view')
            && $this->matchesTenant($user, $payment->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'donations.collect');
    }

    public function reverse(User $user, DonationPayment $payment): bool
    {
        return $this->allows($user, 'donations.reverse')
            && $this->matchesTenant($user, $payment->tenant_id);
    }

    public function refund(User $user, DonationPayment $payment): bool
    {
        return $this->allows($user, 'donations.refund')
            && $this->matchesTenant($user, $payment->tenant_id);
    }
}
