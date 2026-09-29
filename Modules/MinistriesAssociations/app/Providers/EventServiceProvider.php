<?php

namespace Modules\MinistriesAssociations\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Modules\Family\Events\FamilyMemberStatusChanged;
use Modules\MinistriesAssociations\Listeners\HandleFamilyMemberStatusChanged;
use Modules\MinistriesAssociations\Models\GuestMember;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\MinistriesAssociations\Models\Position;
use Modules\MinistriesAssociations\Policies\FamilyMinistriesPolicy;
use Modules\MinistriesAssociations\Policies\GuestMemberPolicy;
use Modules\MinistriesAssociations\Policies\LeadershipTermPolicy;
use Modules\MinistriesAssociations\Policies\MinistriesAuditLogPolicy;
use Modules\MinistriesAssociations\Policies\MinistriesModulePolicy;
use Modules\MinistriesAssociations\Policies\OrganizationCategoryPolicy;
use Modules\MinistriesAssociations\Policies\OrganizationMembershipPolicy;
use Modules\MinistriesAssociations\Policies\OrganizationPolicy;
use Modules\MinistriesAssociations\Policies\OrganizationTypePolicy;
use Modules\MinistriesAssociations\Policies\ParishionerLookupPolicy;
use Modules\MinistriesAssociations\Policies\PositionPolicy;

class EventServiceProvider extends ServiceProvider
{
    /** @var array<string, array<int, string>> */
    protected $listen = [
        FamilyMemberStatusChanged::class => [
            HandleFamilyMemberStatusChanged::class,
        ],
    ];

    public function boot(): void
    {
        Gate::define('ministries.viewModuleStatus', [MinistriesModulePolicy::class, 'viewStatus']);
        Gate::define('ministries.lookupParishioners', [ParishionerLookupPolicy::class, 'lookup']);
        Gate::define('ministries.viewFamilyAffiliations', [FamilyMinistriesPolicy::class, 'viewAffiliations']);
        Gate::define('ministries.enrollFamilyMember', [FamilyMinistriesPolicy::class, 'enroll']);

        Gate::policy(GuestMember::class, GuestMemberPolicy::class);
        Gate::policy(LeadershipTerm::class, LeadershipTermPolicy::class);
        Gate::policy(MinistriesAuditLog::class, MinistriesAuditLogPolicy::class);
        Gate::policy(OrganizationCategory::class, OrganizationCategoryPolicy::class);
        Gate::policy(OrganizationMembership::class, OrganizationMembershipPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(OrganizationType::class, OrganizationTypePolicy::class);
        Gate::policy(Position::class, PositionPolicy::class);
    }
}
