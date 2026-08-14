<?php

namespace Modules\BCC\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BCCLeader;
use Modules\BCC\Models\BccAuditLog;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\BCC\Policies\BCCLeaderPolicy;
use Modules\BCC\Policies\BCCPolicy;
use Modules\BCC\Policies\BccAuditLogPolicy;
use Modules\BCC\Policies\BccFamilyMembershipPolicy;

class EventServiceProvider extends ServiceProvider
{
    /** @var array<string, array<int, string>> */
    protected $listen = [];

    public function boot(): void
    {
        Gate::policy(BCC::class, BCCPolicy::class);
        Gate::policy(BccFamilyMembership::class, BccFamilyMembershipPolicy::class);
        Gate::policy(BCCLeader::class, BCCLeaderPolicy::class);
        Gate::policy(BccAuditLog::class, BccAuditLogPolicy::class);
    }
}
