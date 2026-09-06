<?php

namespace Modules\Family\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Family\app\Services\ParishionerFamilyAccessService;
use Modules\Family\Models\Person;
use Modules\Tenants\Database\Seeders\LeadershipRolesSeeder;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\LeadershipAssignmentStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ParishionerFamilyAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private ParishionerFamilyAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeadershipRolesSeeder::class);
        $this->service = app(ParishionerFamilyAccessService::class);
    }

    #[Test]
    public function user_with_person_id_and_no_clergy_assignment_is_parishioner(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $person = Person::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'person_id' => $person->id,
        ]);

        $this->assertTrue($this->service->isParishioner($user));
    }

    #[Test]
    public function user_linked_to_active_parish_clergy_is_not_parishioner(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $profile = ChurchProfile::factory()->create(['tenant_id' => $tenant->id]);
        $person = Person::factory()->create(['tenant_id' => $tenant->id]);
        $pastorRole = LeadershipRole::query()->where('title', 'Pastor')->firstOrFail();

        LeadershipAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'church_profile_id' => $profile->id,
            'person_id' => $person->id,
            'role_id' => $pastorRole->id,
            'status' => LeadershipAssignmentStatus::ACTIVE,
            'start_date' => now()->subMonth()->toDateString(),
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'person_id' => $person->id,
        ]);

        $this->assertFalse($this->service->isParishioner($user));
    }
}
