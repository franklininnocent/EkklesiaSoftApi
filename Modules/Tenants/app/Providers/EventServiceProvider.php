<?php

namespace Modules\Tenants\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Policies\DonationPaymentPolicy;
use Modules\Family\Models\Family;
use Modules\Family\Models\Person;
use Modules\Family\Policies\FamilyPolicy;
use Modules\Family\Policies\PersonPolicy;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\PastoralCare\Policies\PastoralCareRequestPolicy;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Policies\SupportTicketPolicy;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Policies\SacramentPolicy;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Policies\LeadershipAssignmentPolicy;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    public function boot(): void
    {
        Gate::policy(LeadershipAssignment::class, LeadershipAssignmentPolicy::class);
        Gate::policy(Family::class, FamilyPolicy::class);
        Gate::policy(Person::class, PersonPolicy::class);
        Gate::policy(Sacrament::class, SacramentPolicy::class);
        Gate::policy(DonationPayment::class, DonationPaymentPolicy::class);
        Gate::policy(PastoralCareRequest::class, PastoralCareRequestPolicy::class);
        Gate::policy(SupportTicket::class, SupportTicketPolicy::class);
    }

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
