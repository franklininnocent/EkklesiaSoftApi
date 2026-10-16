<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Jobs\GenerateDueScheduleJob;
use Modules\Donations\Jobs\SendContributionReminderJob;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\Fund;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DonationJobsTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function contribution_reminder_job_does_not_notify_for_another_tenants_due(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();
        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);

        $fund = Fund::create([
            'tenant_id' => $tenantB->id,
            'name' => 'General',
            'code' => 'GEN',
            'status' => 'active',
        ]);
        $plan = ContributionPlan::create([
            'tenant_id' => $tenantB->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'MON',
            'frequency' => 'monthly',
            'default_amount' => 10,
            'status' => 'active',
        ]);
        $dueB = ContributionDue::create([
            'tenant_id' => $tenantB->id,
            'family_id' => $familyB->id,
            'plan_id' => $plan->id,
            'amount_due' => 10,
            'amount_paid' => 0,
            'due_date' => now()->addWeek()->toDateString(),
            'status' => 'pending',
            'period_label' => 'Test',
        ]);

        (new SendContributionReminderJob($tenantA->id, $dueB->id))->handle(
            app(\Modules\Donations\Services\DonationNotificationService::class)
        );

        $this->assertSame(0, \Modules\Donations\Models\DonationNotificationLog::query()->count());
    }

    #[Test]
    public function generate_due_schedule_job_does_not_run_for_another_tenants_plan(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();
        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);

        $fund = Fund::create([
            'tenant_id' => $tenantB->id,
            'name' => 'General',
            'code' => 'GEN2',
            'status' => 'active',
        ]);
        $planB = ContributionPlan::create([
            'tenant_id' => $tenantB->id,
            'fund_id' => $fund->id,
            'name' => 'Annual',
            'code' => 'ANN',
            'frequency' => 'monthly',
            'default_amount' => 100,
            'status' => 'active',
        ]);

        $before = ContributionDue::query()->where('tenant_id', $tenantB->id)->count();

        (new GenerateDueScheduleJob(
            $tenantA->id,
            1,
            $planB->id,
            [$familyB->id],
            'FY Test',
            now()->addMonth()->toDateString(),
        ))->handle(app(\Modules\Donations\Services\ContributionDueService::class));

        $this->assertSame($before, ContributionDue::query()->where('tenant_id', $tenantB->id)->count());
    }
}
