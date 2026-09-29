<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\BCC\Models\BCC;
use Modules\Donations\Models\DonationPayment;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\Sacraments\Models\Sacrament;
use Modules\Tenants\Models\TenantDataExport;
use Modules\Tenants\Tests\Concerns\BuildsCrossTenantPenetrationFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Consolidated cross-tenant penetration suite (Phase 9).
 *
 * Attacker: tenant A admin with broad module permissions.
 * Victim: seeded records in tenant B — reads/mutations must fail closed (404/403).
 */
class CrossTenantPenetrationSuiteTest extends TestCase
{
    use BuildsCrossTenantPenetrationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->setUpCrossTenantPenetrationFixtures();
    }

    #[Test]
    public function family_reads_and_mutations_are_blocked_across_tenants(): void
    {
        $foreign = $this->victim->family;

        $this->assertDeniedCrossTenantAccess('GET', '/api/families/'.$foreign->id);
        $this->assertDeniedCrossTenantAccess('PUT', '/api/families/'.$foreign->id, [403, 404], [
            'family_name' => 'Hacked Family',
        ]);
        $this->assertDeniedCrossTenantAccess('DELETE', '/api/families/'.$foreign->id, [403, 404]);
        $this->assertDeniedCrossTenantAccess('GET', '/api/families/'.$foreign->id.'/members', [403, 404]);

        $list = $this->getJson('/api/families?per_page=100');
        $list->assertOk();
        $ids = collect($list->json('data.data') ?? $list->json('data') ?? [])->pluck('id');
        $this->assertFalse($ids->contains($foreign->id));

        $this->assertDatabaseHas('families', [
            'id' => $foreign->id,
            'family_name' => 'Victim Foreign Family',
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function person_reads_are_blocked_across_tenants(): void
    {
        $foreign = $this->victim->person;

        $this->assertDeniedCrossTenantAccess('GET', '/api/persons/'.$foreign->id, [404, 422]);
        $this->assertDeniedCrossTenantAccess('POST', '/api/persons/'.$foreign->id.'/reconcile-identity', [403, 404, 422], [
            'field' => 'first_name',
            'new_value' => 'Hacked',
            'reason' => 'probe',
        ]);
    }

    #[Test]
    public function sacrament_reads_and_mutations_are_blocked_across_tenants(): void
    {
        $foreign = $this->victim->sacrament;

        $this->assertDeniedCrossTenantAccess('GET', '/api/sacraments/'.$foreign->id);
        $this->assertDeniedCrossTenantAccess('PUT', '/api/sacraments/'.$foreign->id, [403, 404], [
            'recipient_name' => 'Hacked Recipient',
        ]);
        $this->assertDeniedCrossTenantAccess('DELETE', '/api/sacraments/'.$foreign->id, [403, 404]);

        $list = $this->getJson('/api/sacraments?per_page=50');
        $list->assertOk();
        $ids = collect($list->json('data.data') ?? $list->json('data') ?? [])->pluck('id');
        $this->assertFalse($ids->contains($foreign->id));

        $this->assertDatabaseHas('sacraments', [
            'id' => $foreign->id,
            'recipient_name' => 'Victim Sacrament Recipient',
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function donation_records_are_blocked_across_tenants(): void
    {
        $foreign = $this->victim->payment;

        $this->assertDeniedCrossTenantAccess('GET', '/api/tenant/donations/payments/'.$foreign->id.'/receipt');
        $this->assertDeniedCrossTenantAccess('POST', '/api/tenant/donations/payments/'.$foreign->id.'/reverse', [403, 404], [
            'reason' => 'cross-tenant probe',
        ]);

        $list = $this->getJson('/api/tenant/donations/payments?per_page=50');
        $list->assertOk();
        $ids = collect($list->json('data.data') ?? [])->pluck('id');
        $this->assertFalse($ids->contains($foreign->id));

        $this->assertDatabaseHas('donation_payments', [
            'id' => $foreign->id,
            'tenant_id' => $this->victimTenant->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function pastoral_care_requests_are_blocked_across_tenants(): void
    {
        $foreign = $this->victim->pastoralRequest;

        $this->assertDeniedCrossTenantAccess('GET', '/api/tenant/pastoral/requests/'.$foreign->id);
        $this->assertDeniedCrossTenantAccess('POST', '/api/tenant/pastoral/requests/'.$foreign->id.'/assign', [403, 404], [
            'assigned_to_user_id' => $this->attacker->id,
        ]);
        $this->assertDeniedCrossTenantAccess('POST', '/api/tenant/pastoral/requests', [403, 404], [
            'family_id' => $this->victim->family->id,
            'type' => 'home_visit',
            'summary' => 'Cross-tenant family probe',
        ]);

        $list = $this->getJson('/api/tenant/pastoral/requests');
        $list->assertOk();
        $this->assertSame(0, (int) ($list->json('total') ?? $list->json('data.total') ?? 0));
    }

    #[Test]
    public function bcc_records_are_blocked_across_tenants(): void
    {
        $foreign = $this->victim->bcc;

        $this->assertDeniedCrossTenantAccess('GET', '/api/bccs/'.$foreign->id);
        $this->assertDeniedCrossTenantAccess('PUT', '/api/bccs/'.$foreign->id, [403, 404], [
            'name' => 'Hacked BCC',
        ]);
        $this->assertDeniedCrossTenantAccess('DELETE', '/api/bccs/'.$foreign->id, [403, 404]);

        $list = $this->getJson('/api/bccs');
        $list->assertOk();
        $this->assertSame(0, (int) ($list->json('total') ?? 0));

        $this->assertDatabaseHas('bccs', [
            'id' => $foreign->id,
            'name' => 'Victim BCC',
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function tenant_data_exports_are_blocked_across_tenants(): void
    {
        $foreign = $this->victim->export;

        $this->assertDeniedCrossTenantAccess('GET', '/api/tenant/export/bulk/'.$foreign->id);
        $this->assertDeniedCrossTenantAccess('GET', '/api/tenant/export/bulk/'.$foreign->id.'/download', [403, 404]);
        $this->assertDeniedCrossTenantAccess('POST', '/api/tenant/export/bulk/'.$foreign->id.'/cancel', [403, 404]);

        $list = $this->getJson('/api/tenant/export/bulk');
        $list->assertOk();
        $ids = collect($list->json('data') ?? [])->pluck('id');
        $this->assertFalse($ids->contains($foreign->id));
    }

    #[Test]
    public function secure_file_paths_reject_cross_tenant_family_assets(): void
    {
        $foreignPath = 'families/'.$this->victimTenant->id.'/profiles/foreign-probe.jpg';
        Storage::disk('public')->put($foreignPath, 'foreign');

        $this->postJson('/api/tenant/files/signed-url', [
            'path' => $foreignPath,
        ])->assertForbidden();
    }

    #[Test]
    public function sensitive_reads_return_not_found_not_forbidden(): void
    {
        $probes = [
            ['/api/families/'.$this->victim->family->id, Family::class, $this->victim->family->id],
            ['/api/sacraments/'.$this->victim->sacrament->id, Sacrament::class, $this->victim->sacrament->id],
            ['/api/tenant/pastoral/requests/'.$this->victim->pastoralRequest->id, PastoralCareRequest::class, $this->victim->pastoralRequest->id],
            ['/api/bccs/'.$this->victim->bcc->id, BCC::class, $this->victim->bcc->id],
        ];

        foreach ($probes as [$uri, $model, $id]) {
            $response = $this->getJson($uri);
            $this->assertSame(404, $response->getStatusCode(), 'Enumeration probe failed for '.$uri);
            $this->assertDatabaseHas((new $model)->getTable(), ['id' => $id]);
        }

        $personResponse = $this->getJson('/api/persons/'.$this->victim->person->id);
        $this->assertContains($personResponse->getStatusCode(), [404, 422]);
        $this->assertDatabaseHas('persons', ['id' => $this->victim->person->id]);

        $this->getJson('/api/tenant/donations/payments/'.$this->victim->payment->id.'/receipt')
            ->assertNotFound();
        $this->assertDatabaseHas('donation_payments', ['id' => $this->victim->payment->id]);

        $this->getJson('/api/tenant/export/bulk/'.$this->victim->export->id)
            ->assertNotFound();
        $this->assertDatabaseHas('tenant_data_exports', ['id' => $this->victim->export->id]);
    }

    #[Test]
    public function victim_records_remain_unmodified_after_probe_batch(): void
    {
        $familySnapshot = $this->victim->family->only(['id', 'family_name', 'tenant_id']);
        $memberSnapshot = $this->victim->member->only(['id', 'family_id', 'tenant_id']);
        $paymentSnapshot = $this->victim->payment->only(['id', 'tenant_id', 'status', 'amount']);

        $this->assertDeniedCrossTenantAccess('PUT', '/api/families/'.$this->victim->family->id, [403, 404], [
            'family_name' => 'Probe Mutation',
        ]);
        $this->assertDeniedCrossTenantAccess('DELETE', '/api/families/'.$this->victim->family->id.'/members/'.$this->victim->member->id, [403, 404]);
        $this->assertDeniedCrossTenantAccess('POST', '/api/tenant/donations/payments/'.$this->victim->payment->id.'/reverse', [403, 404], [
            'reason' => 'probe',
        ]);

        $this->assertDatabaseHas('families', $familySnapshot);
        $this->assertDatabaseHas('family_members', $memberSnapshot);
        $this->assertDatabaseHas('donation_payments', $paymentSnapshot);
    }
}
