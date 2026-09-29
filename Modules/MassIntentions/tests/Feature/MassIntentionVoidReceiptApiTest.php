<?php

namespace Modules\MassIntentions\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Database\Seeders\MassIntentionsPermissionSeeder;
use Modules\MassIntentions\Models\MassIntentionOffering;
use Modules\MassIntentions\Models\MassIntentionOfferingReceipt;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Modules\MassIntentions\Tests\Support\SkipsLegacyMassObligationWorkflow;
use Tests\TestCase;

class MassIntentionVoidReceiptApiTest extends TestCase
{
    use SkipsLegacyMassObligationWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MassIntentionsPermissionSeeder::class);
    }

    #[Test]
    public function void_receipt_requires_reason_and_marks_voided(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['mass_intentions'], 'currency_code' => 'USD']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $this->grantPermissions($user, ['mass.intentions.view', 'mass.intentions.offerings.record']);

        $request = MassIntentionRequest::query()->create([
            'tenant_id' => $tenant->id,
            'status' => MassIntentionStatus::ACCEPTED,
            'beneficiary_name' => 'Pat',
            'intention_text' => 'Thanks',
            'created_by_user_id' => $user->id,
        ]);
        $offering = MassIntentionOffering::query()->create([
            'tenant_id' => $tenant->id,
            'request_id' => $request->id,
            'currency_code' => 'USD',
            'status' => 'open',
        ]);
        $receipt = MassIntentionOfferingReceipt::query()->create([
            'tenant_id' => $tenant->id,
            'offering_id' => $offering->id,
            'receipt_number' => 'MI-2026-0099',
            'amount' => '25.00',
            'payment_method' => 'cash',
            'received_on' => now()->toDateString(),
            'recorded_by_user_id' => $user->id,
        ]);

        Passport::actingAs($user);

        $this->postJson("/api/tenant/mass-intentions/receipts/{$receipt->id}/void", [])
            ->assertUnprocessable();

        $this->postJson("/api/tenant/mass-intentions/receipts/{$receipt->id}/void", [
            'reason' => 'Entered twice by mistake',
        ])->assertOk();

        $this->assertNotNull($receipt->fresh()->voided_at);
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(User $user, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $ids[] = Permission::query()->where('name', $name)->firstOrFail()->id;
        }
        $user->permissions()->syncWithoutDetaching($ids);
        $user->clearPermissionsCache();
    }
}
