<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Donations\Models\DonationAuditLog;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use Modules\RolesAndPermissions\Models\Permission;
use PHPUnit\Framework\Attributes\Test;

class ProjectInstallmentGenerationLifecycleTest extends DonationsCertificationTestCase
{
    #[Test]
    public function generate_is_idempotent_and_a_second_run_does_not_duplicate(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject();

        $first = $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments");
        $first->assertOk()
            ->assertJsonPath('data.outcome', 'generated')
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.installments', 3);

        $second = $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments");
        $second->assertOk()
            ->assertJsonPath('data.outcome', 'already_generated')
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0);

        $this->assertSame(3, ProjectInstallmentDue::where('project_id', $projectId)->where('family_id', $family->id)->count());
        $this->assertDatabaseHas('donation_audit_logs', [
            'tenant_id' => $ctx['tenant']->id,
            'event' => 'project.installments_generated',
            'target_id' => $projectId,
        ]);

        $schedule = $this->getJson("/api/tenant/donations/projects/{$projectId}/installment-schedule");
        $schedule->assertOk()
            ->assertJsonPath('data.state', 'generated')
            ->assertJsonPath('data.can_generate', false)
            ->assertJsonPath('data.can_regenerate', true)
            ->assertJsonPath('data.pending_count', 3);
    }

    #[Test]
    public function changing_project_settings_does_not_rewrite_installments_until_regeneration_is_confirmed(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject();
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $this->putJson("/api/tenant/donations/projects/{$projectId}", [
            'default_family_target' => 600,
            'installment_count' => 2,
        ])->assertOk();

        $this->assertDatabaseHas('project_installment_dues', [
            'project_id' => $projectId,
            'family_id' => $family->id,
            'installment_number' => 1,
            'amount_due' => 100,
            'status' => 'pending',
        ]);
        $this->assertSame(3, ProjectInstallmentDue::where('project_id', $projectId)->count());

        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments", [
            'mode' => 'regenerate',
        ])->assertStatus(422);

        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments", [
            'mode' => 'regenerate',
            'confirm' => true,
            'reason' => 'Council reduced the campaign to two installments.',
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'regenerated');

        $this->assertDatabaseHas('project_installment_dues', [
            'project_id' => $projectId,
            'installment_number' => 1,
            'amount_due' => 300,
            'status' => 'pending',
            'amount_paid' => 0,
        ]);
        $this->assertDatabaseHas('project_installment_dues', [
            'project_id' => $projectId,
            'installment_number' => 2,
            'amount_due' => 300,
            'status' => 'pending',
        ]);
        $surplus = ProjectInstallmentDue::where('project_id', $projectId)->where('installment_number', 3)->first();
        $this->assertNotNull($surplus);
        $this->assertSame('cancelled', $surplus->status);
        $this->assertSame('0.00', number_format((float) $surplus->amount_paid, 2, '.', ''));
        $this->assertStringContainsString('[schedule_superseded]', (string) $surplus->notes);

        $audit = DonationAuditLog::where('target_id', $projectId)
            ->where('event', 'project.installments_regenerated')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('Council reduced the campaign to two installments.', $audit->metadata['reason'] ?? null);
    }

    #[Test]
    public function lengthening_the_schedule_restores_only_installments_cancelled_by_regeneration(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject();
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $userCancelled = ProjectInstallmentDue::where('project_id', $projectId)->where('installment_number', 2)->firstOrFail();
        $this->postJson("/api/tenant/donations/project-installments/{$userCancelled->id}/cancel", [
            'reason' => 'Family left the parish',
        ])->assertOk();

        $this->putJson("/api/tenant/donations/projects/{$projectId}", [
            'installment_count' => 1,
        ])->assertOk();
        $this->regenerate($projectId, 'Drop the unused third installment.');

        $this->putJson("/api/tenant/donations/projects/{$projectId}", [
            'installment_count' => 3,
        ])->assertOk();
        $this->regenerate($projectId, 'Restore the three-installment schedule.');

        $userCancelled->refresh();
        $this->assertSame('cancelled', $userCancelled->status);
        $this->assertStringNotContainsString('[schedule_superseded]', (string) $userCancelled->notes);

        $restored = ProjectInstallmentDue::where('project_id', $projectId)
            ->where('family_id', $family->id)
            ->where('installment_number', 3)
            ->firstOrFail();
        $this->assertSame('pending', $restored->status);
        $this->assertSame(3, ProjectInstallmentDue::where('project_id', $projectId)->where('family_id', $family->id)->count());
    }

    #[Test]
    public function a_partial_payment_locks_the_whole_family_schedule_including_unpaid_siblings(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage', 'donations.collect']);
        $paidFamily = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $openFamily = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject();
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $first = ProjectInstallmentDue::where('project_id', $projectId)
            ->where('family_id', $paidFamily->id)
            ->where('installment_number', 1)
            ->firstOrFail();
        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $paidFamily->id,
            'payer_name' => 'Roof donor',
            'payment_date' => now()->toDateString(),
            'amount' => 40,
            'method' => 'cash',
            'allocations' => [[
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $first->id,
                'amount' => 40,
            ]],
        ])->assertCreated();
        $first->refresh();
        $this->assertSame('partially_paid', $first->status);

        $this->putJson("/api/tenant/donations/projects/{$projectId}", [
            'default_family_target' => 600,
        ])->assertOk();
        $response = $this->regenerate($projectId, 'Raise the family target for families who have not paid.');
        $response->assertJsonPath('data.families_locked', 1);

        $first->refresh();
        $this->assertSame('40.00', number_format((float) $first->amount_paid, 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $first->amount_due, 2, '.', ''));
        $this->assertSame('partially_paid', $first->status);

        $sibling = ProjectInstallmentDue::where('project_id', $projectId)
            ->where('family_id', $paidFamily->id)
            ->where('installment_number', 2)
            ->firstOrFail();
        $this->assertSame('pending', $sibling->status);
        $this->assertSame('100.00', number_format((float) $sibling->amount_due, 2, '.', ''));

        $open = ProjectInstallmentDue::where('project_id', $projectId)
            ->where('family_id', $openFamily->id)
            ->orderBy('installment_number')
            ->get();
        $this->assertSame('200.00', number_format((float) $open[0]->amount_due, 2, '.', ''));
        $this->assertSame('200.00', number_format((float) $open[1]->amount_due, 2, '.', ''));
        $this->assertSame('200.00', number_format((float) $open[2]->amount_due, 2, '.', ''));

        $allocation = DB::table('payment_allocations')->where('allocatable_id', $first->id)->first();
        $this->assertNotNull($allocation);
        $this->assertSame('project_installment', $allocation->allocatable_type);
        $this->assertSame($first->id, $allocation->allocatable_id);
        $this->assertSame((int) $ctx['tenant']->id, (int) $allocation->tenant_id);
        $this->assertSame('40.00', number_format((float) $allocation->amount, 2, '.', ''));
        $this->assertSame((int) $ctx['tenant']->id, (int) $first->tenant_id);

        $outstanding = '0.00';
        $openDues = ProjectInstallmentDue::where('project_id', $projectId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->get();
        foreach ($openDues as $due) {
            $outstanding = MoneyMath::add($outstanding, MoneyMath::outstanding($due->amount_due, $due->amount_paid));
        }
        $this->assertSame('860.00', $outstanding);

        $this->postJson("/api/tenant/donations/project-installments/{$first->id}/cancel", [
            'reason' => 'Should not erase a payment',
        ])->assertStatus(422);
        $this->postJson("/api/tenant/donations/project-installments/{$first->id}/waive", [
            'reason' => 'Should not erase a payment',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_failed_regeneration_rolls_the_schedule_back(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject();
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();
        $this->putJson("/api/tenant/donations/projects/{$projectId}", [
            'default_family_target' => 900,
        ])->assertOk();

        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if (! $armed) {
                return;
            }
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'project_installment_dues') || ! str_starts_with(ltrim($sql), 'update')) {
                return;
            }
            $armed = false;
            throw new \RuntimeException('forced regeneration failure');
        });

        $this->withoutExceptionHandling();
        $failed = false;
        try {
            $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments", [
                'mode' => 'regenerate',
                'confirm' => true,
                'reason' => 'This write is forced to fail midway.',
            ]);
        } catch (\RuntimeException $exception) {
            $failed = $exception->getMessage() === 'forced regeneration failure';
        }

        $this->assertTrue($failed);
        $amounts = ProjectInstallmentDue::where('family_id', $family->id)
            ->orderBy('installment_number')
            ->pluck('amount_due')
            ->map(fn ($amount) => number_format((float) $amount, 2, '.', ''))
            ->all();
        $this->assertSame(['100.00', '100.00', '100.00'], $amounts);
        $this->assertSame(3, ProjectInstallmentDue::where('project_id', $projectId)->count());
    }

    #[Test]
    public function fully_paid_installments_block_replacement_of_that_family(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject(['installment_count' => 1, 'default_family_target' => 100]);
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $due = ProjectInstallmentDue::where('project_id', $projectId)->where('family_id', $family->id)->firstOrFail();
        $this->postJson('/api/tenant/donations/payments', [
            'family_id' => $family->id,
            'payer_name' => 'Roof donor',
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'method' => 'cash',
            'allocations' => [[
                'allocatable_type' => 'project_installment',
                'allocatable_id' => $due->id,
                'amount' => 100,
            ]],
        ])->assertCreated();
        $due->refresh();
        $this->assertSame('paid', $due->status);

        $this->putJson("/api/tenant/donations/projects/{$projectId}", [
            'default_family_target' => 250,
        ])->assertOk();

        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments", [
            'mode' => 'regenerate',
            'confirm' => true,
            'reason' => 'Try to replace a paid installment.',
        ])->assertStatus(409);

        $due->refresh();
        $this->assertSame('paid', $due->status);
        $this->assertSame('100.00', number_format((float) $due->amount_due, 2, '.', ''));
        $this->assertSame('100.00', number_format((float) $due->amount_paid, 2, '.', ''));
        $this->assertSame(1, ProjectInstallmentDue::where('project_id', $projectId)->count());

        $this->getJson("/api/tenant/donations/projects/{$projectId}/installment-schedule")
            ->assertOk()
            ->assertJsonPath('data.state', 'payments_recorded')
            ->assertJsonPath('data.can_regenerate', false)
            ->assertJsonPath('data.paid_count', 1);
    }

    #[Test]
    public function waived_installments_stay_waived_when_the_unpaid_schedule_is_regenerated(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject();
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $waived = ProjectInstallmentDue::where('project_id', $projectId)->where('installment_number', 1)->firstOrFail();
        $this->postJson("/api/tenant/donations/project-installments/{$waived->id}/waive", [
            'reason' => 'Pastoral waiver',
        ])->assertOk();

        $this->putJson("/api/tenant/donations/projects/{$projectId}", [
            'default_family_target' => 600,
        ])->assertOk();
        $this->regenerate($projectId, 'Update the remaining unpaid installments.');

        $waived->refresh();
        $this->assertSame('waived', $waived->status);
        $this->assertSame('100.00', number_format((float) $waived->amount_due, 2, '.', ''));

        $next = ProjectInstallmentDue::where('project_id', $projectId)->where('installment_number', 2)->firstOrFail();
        $this->assertSame('pending', $next->status);
        $this->assertSame('250.00', number_format((float) $next->amount_due, 2, '.', ''));
        $last = ProjectInstallmentDue::where('project_id', $projectId)->where('installment_number', 3)->firstOrFail();
        $this->assertSame('250.00', number_format((float) $last->amount_due, 2, '.', ''));
    }

    #[Test]
    public function overdue_unpaid_installments_can_be_redated_and_paid_history_cannot(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject([
            'start_date' => now()->subMonths(4)->startOfMonth()->toDateString(),
            'installment_frequency' => 'monthly',
        ]);
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $this->getJson("/api/tenant/donations/projects/{$projectId}/installment-schedule")
            ->assertOk()
            ->assertJsonPath('data.overdue_count', 3);

        $originalDue = ProjectInstallmentDue::where('project_id', $projectId)->where('installment_number', 2)->value('due_date');
        $this->putJson("/api/tenant/donations/projects/{$projectId}", [
            'start_date' => now()->addMonth()->endOfMonth()->toDateString(),
        ])->assertOk();
        $this->regenerate($projectId, 'Move the unpaid due dates after the council postponed the start.');

        $moved = ProjectInstallmentDue::where('project_id', $projectId)->where('installment_number', 2)->firstOrFail();
        $this->assertSame('pending', $moved->status);
        $this->assertNotSame(
            substr((string) $originalDue, 0, 10),
            $moved->due_date->toDateString()
        );
        $this->getJson("/api/tenant/donations/projects/{$projectId}/installment-schedule")
            ->assertOk()
            ->assertJsonPath('data.overdue_count', 0);
    }

    #[Test]
    public function generate_adds_a_newly_enrolled_family_without_touching_the_existing_schedule(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $original = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject();
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $joined = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'generated')
            ->assertJsonPath('data.created', 3);

        $this->assertSame(3, ProjectInstallmentDue::where('family_id', $original->id)->count());
        $this->assertSame(3, ProjectInstallmentDue::where('family_id', $joined->id)->count());
        $this->assertSame(
            '100.00',
            number_format((float) ProjectInstallmentDue::where('family_id', $original->id)->where('installment_number', 1)->value('amount_due'), 2, '.', '')
        );
    }

    #[Test]
    public function odd_targets_balance_on_the_last_installment_without_float_drift(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject([
            'default_family_target' => 100,
            'installment_count' => 3,
        ]);
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertOk();

        $amounts = ProjectInstallmentDue::where('family_id', $family->id)
            ->orderBy('installment_number')
            ->pluck('amount_due')
            ->map(fn ($amount) => number_format((float) $amount, 2, '.', ''))
            ->all();
        $this->assertSame(['33.33', '33.33', '33.34'], $amounts);
    }

    #[Test]
    public function generation_is_tenant_scoped_and_ignores_foreign_family_ids(): void
    {
        $owner = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        Family::factory()->create(['tenant_id' => $owner['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject();

        $intruder = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        $foreignFamily = Family::factory()->create(['tenant_id' => $intruder['tenant']->id, 'status' => 'active']);
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertNotFound();

        Passport::actingAs($owner['user']);
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments", [
            'family_ids' => [$foreignFamily->id],
        ])->assertOk()->assertJsonPath('data.outcome', 'empty');
        $this->assertDatabaseMissing('project_installment_dues', [
            'project_id' => $projectId,
            'family_id' => $foreignFamily->id,
        ]);
    }

    #[Test]
    public function a_view_only_user_can_read_schedule_state_but_cannot_generate(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject();

        $viewer = $this->userWithPermissions($ctx['tenant']->id, ['donations.view']);
        Passport::actingAs($viewer);

        $this->getJson("/api/tenant/donations/projects/{$projectId}/installment-schedule")->assertOk();
        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")->assertForbidden();
    }

    #[Test]
    public function concurrent_generation_is_rejected_while_a_lock_is_held(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.manage']);
        Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $projectId = $this->createUniformProject();

        $lock = Cache::lock("donations:project-installments:{$ctx['tenant']->id}:{$projectId}", 120);
        $this->assertTrue($lock->get());
        try {
            $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")
                ->assertStatus(409)
                ->assertJsonPath('success', false);
        } finally {
            $lock->release();
        }

        $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'generated');
    }

    #[Test]
    public function unauthenticated_generation_is_denied(): void
    {
        $this->postJson('/api/tenant/donations/projects/00000000-0000-0000-0000-000000000000/generate-installments')
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createUniformProject(array $overrides = []): string
    {
        $response = $this->postJson('/api/tenant/donations/projects', array_merge([
            'name' => 'Church Roof',
            'code' => 'ROOF-'.substr(uniqid(), -6),
            'assignment_mode' => 'uniform',
            'default_family_target' => 300,
            'installment_count' => 3,
            'installment_frequency' => 'monthly',
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'auto_generate_installments' => false,
        ], $overrides));

        $response->assertCreated();

        return (string) $response->json('data.id');
    }

    private function regenerate(string $projectId, string $reason): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/tenant/donations/projects/{$projectId}/generate-installments", [
            'mode' => 'regenerate',
            'confirm' => true,
            'reason' => $reason,
        ])->assertOk();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(int $tenantId, array $permissions): User
    {
        $role = Role::create([
            'name' => 'Viewer '.uniqid(),
            'description' => 'View only',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenantId,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenantId,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);
        $ids = [];
        foreach ($permissions as $name) {
            $permission = Permission::where('name', $name)->firstOrFail();
            $ids[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
        $user->clearRequestPermissionCache();
        $user->clearPermissionsCache();

        return $user->fresh();
    }
}
