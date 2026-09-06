<?php

namespace Modules\Tenants\Tests\Feature\ChurchProfile;

use Modules\Tenants\Export\Contributors\ChurchProfileDataExportContributor;
use Modules\Tenants\Export\TenantExportStorage;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Testing\ChurchProfileCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class ChurchProfileModule360Test extends ChurchProfileCertificationTestCase
{
    #[Test]
    public function it_should_auto_create_profile_for_effective_tenant_only(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $otherTenant = Tenant::factory()->active()->create();

        $this->assertDatabaseMissing('church_profiles', ['tenant_id' => $ctx['tenant']->id]);
        $this->assertDatabaseMissing('church_profiles', ['tenant_id' => $otherTenant->id]);

        $this->getJson('/api/church-profile')->assertOk();

        $this->assertDatabaseHas('church_profiles', ['tenant_id' => $ctx['tenant']->id]);
        $this->assertDatabaseMissing('church_profiles', ['tenant_id' => $otherTenant->id]);
    }

    #[Test]
    public function it_should_serve_same_profile_apis_for_branch_tenant_without_parent_leakage(): void
    {
        $parent = Tenant::factory()->active()->create([
            'tenant_tier' => 'diocese',
            'name' => 'Parent Diocese',
        ]);
        $branch = Tenant::factory()->active()->create([
            'tenant_tier' => 'branch',
            'parent_tenant_id' => $parent->id,
            'name' => 'Branch Chapel',
        ]);

        $this->seedChurchProfile($parent, ['patron_name' => 'Diocese Patron']);
        $this->seedChurchProfile($branch, ['patron_name' => 'Branch Patron']);

        $ctx = $this->actingAsTenantWith(['church.settings.edit'], $branch);

        $this->getJson('/api/church-profile')
            ->assertOk()
            ->assertJsonPath('data.patron_name', 'Branch Patron');

        $this->getJson('/api/tenant/church-profile')
            ->assertOk()
            ->assertJsonPath('data.name', 'Branch Chapel');
    }

    #[Test]
    public function it_should_export_church_profile_and_leadership_assignment_csv_files(): void
    {
        $tenant = $this->makeOperationalTenant();
        $profile = $this->seedChurchProfile($tenant, [
            'patron_name' => 'St Export',
            'about' => 'Exportable about',
        ]);
        $this->seedLeadershipRoles();
        $person = $this->makePerson($tenant, 'Export', 'Leader');
        $deaconRole = LeadershipRole::query()->where('title', 'Deacon')->firstOrFail();
        LeadershipAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'church_profile_id' => $profile->id,
            'person_id' => $person->id,
            'role_id' => $deaconRole->id,
        ]);
        $this->seedStatistic($tenant);
        $this->seedSocial($tenant);

        $storage = new TenantExportStorage('local', 'csv');
        $storage->createWorkingDirectory($tenant->id, 'export-test');
        $contributor = new ChurchProfileDataExportContributor;

        $result = $contributor->export($tenant->id, $storage, 100, static function (): void {});
        $files = $result['files'];

        $this->assertArrayHasKey('data/church_profile.csv', $files);
        $this->assertArrayHasKey('data/leadership_assignments.csv', $files);
        $this->assertArrayHasKey('data/church_statistics.csv', $files);
        $this->assertArrayHasKey('data/church_social_media.csv', $files);
        $this->assertGreaterThanOrEqual(1, $files['data/church_profile.csv']);
        $this->assertGreaterThanOrEqual(1, $files['data/leadership_assignments.csv']);

        $profileCsv = file_get_contents($storage->workingAbsolutePath().'/data/church_profile.csv');
        $this->assertIsString($profileCsv);
        $this->assertStringContainsString('St Export', $profileCsv);
        $this->assertStringContainsString('Exportable about', $profileCsv);

        $storage->deleteWorkingDirectory();
    }

    #[Test]
    public function it_should_filter_leadership_roles_by_category(): void
    {
        $ctx = $this->actingAsTenantWith(['church.settings.edit']);
        $this->seedLeadershipRoles();
        $this->seedChurchProfile($ctx['tenant']);

        $all = $this->getJson('/api/church-profile/leadership/roles')->assertOk();
        $this->assertGreaterThan(0, count($all->json('data')));

        $filtered = $this->getJson('/api/church-profile/leadership/roles?category=PARISH_CLERGY')->assertOk();
        foreach ($filtered->json('data') as $role) {
            $this->assertSame('PARISH_CLERGY', $role['category']);
        }
    }
}
