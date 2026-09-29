<?php

namespace Modules\Family\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Family\app\Services\FamilyMemberParentNameResolver;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FamilyMemberParentNameResolverTest extends TestCase
{
    use RefreshDatabase;

    private FamilyMemberParentNameResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(FamilyMemberParentNameResolver::class);
    }

    #[Test]
    public function it_resolves_father_and_mother_from_family_relationships(): void
    {
        $tenant = Tenant::factory()->create();
        $family = Family::factory()->create(['tenant_id' => $tenant->id]);

        $father = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'John',
            'last_name' => 'Anderson',
            'relationship_to_head' => 'self',
            'gender' => 'male',
        ]);
        $mother = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Mary',
            'last_name' => 'Anderson',
            'relationship_to_head' => 'spouse',
            'gender' => 'female',
        ]);
        $child = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Olivia',
            'last_name' => 'Anderson',
            'relationship_to_head' => 'daughter',
            'gender' => 'female',
        ]);

        $familyMembers = FamilyMember::query()
            ->where('family_id', $family->id)
            ->get(['id', 'family_id', 'first_name', 'middle_name', 'last_name', 'relationship_to_head', 'gender', 'person_id']);

        $resolved = $this->resolver->resolveForMember($child, $familyMembers);

        $expectedFather = trim(implode(' ', array_filter([$father->first_name, $father->middle_name, $father->last_name])));
        $expectedMother = trim(implode(' ', array_filter([$mother->first_name, $mother->middle_name, $mother->last_name])));
        $this->assertSame($expectedFather, $resolved['father_name']);
        $this->assertSame($expectedMother, $resolved['mother_name']);
        $this->assertNotSame($father->id, $child->id);
        $this->assertNotSame($mother->id, $child->id);
    }

    #[Test]
    public function it_attaches_parent_names_to_member_api_payloads(): void
    {
        $tenant = Tenant::factory()->create();
        $family = Family::factory()->create(['tenant_id' => $tenant->id]);

        $father = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Paul',
            'middle_name' => null,
            'last_name' => 'Smith',
            'relationship_to_head' => 'father',
            'gender' => 'male',
        ]);
        $mother = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Anna',
            'middle_name' => null,
            'last_name' => 'Smith',
            'relationship_to_head' => 'mother',
            'gender' => 'female',
        ]);
        $child = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Olivia',
            'last_name' => 'Smith',
            'relationship_to_head' => 'daughter',
            'gender' => 'female',
        ]);

        $members = collect([$child]);
        $this->resolver->attachToMembers($members);

        $this->assertSame('Paul Smith', $child->father_name);
        $this->assertSame('Anna Smith', $child->mother_name);
    }
}
