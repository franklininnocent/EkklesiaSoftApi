<?php

namespace Modules\Family\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Tenants\Models\Tenant;

/**
 * Deterministic, idempotent sacrament E2E fixtures for a single tenant.
 *
 * Run:
 * SACRAMENT_E2E_TENANT_ID=<tenant-uuid> php artisan db:seed --class=Modules\\Family\\Database\\Seeders\\SacramentE2eSeeder
 */
class SacramentE2eSeeder extends Seeder
{
    private const MARKER = 'sacrament_e2e_v1';

    private const BOTH_PARENTS_FAMILY_ID = 'e2e00001-0001-4000-8000-000000000001';

    /** @var array<string, array{family: string, person: string, member: string}> */
    private const SINGLE_MEMBER_IDS = [
        'memberA' => [
            'family' => 'e2e00001-0001-4000-8000-000000000002',
            'person' => 'e2e00002-0001-4000-8000-000000000020',
            'member' => 'e2e00003-0001-4000-8000-000000000020',
        ],
        'memberB' => [
            'family' => 'e2e00001-0001-4000-8000-000000000003',
            'person' => 'e2e00002-0001-4000-8000-000000000021',
            'member' => 'e2e00003-0001-4000-8000-000000000021',
        ],
        'fatherOnly' => [
            'family' => 'e2e00001-0001-4000-8000-000000000004',
            'person' => 'e2e00002-0001-4000-8000-000000000022',
            'member' => 'e2e00003-0001-4000-8000-000000000022',
        ],
        'motherOnly' => [
            'family' => 'e2e00001-0001-4000-8000-000000000005',
            'person' => 'e2e00002-0001-4000-8000-000000000023',
            'member' => 'e2e00003-0001-4000-8000-000000000023',
        ],
        'noParents' => [
            'family' => 'e2e00001-0001-4000-8000-000000000006',
            'person' => 'e2e00002-0001-4000-8000-000000000024',
            'member' => 'e2e00003-0001-4000-8000-000000000024',
        ],
    ];

    public function run(): void
    {
        $tenantId = env('SACRAMENT_E2E_TENANT_ID');
        if (! $tenantId) {
            $this->command?->error('SACRAMENT_E2E_TENANT_ID is required.');

            return;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            $this->command?->error("Tenant not found: {$tenantId}");

            return;
        }

        $this->seedBothParentsFamily($tenantId);
        $this->seedSingleMemberFamily($tenantId, 'memberA', [
            'first_name' => 'E2E Member',
            'last_name' => 'Alpha',
            'date_of_birth' => '1995-03-14',
            'gender' => 'male',
            'relationship_to_head' => 'self',
            'person' => [
                'father_name' => 'Alpha Father',
                'mother_name' => 'Alpha Mother',
            ],
        ]);
        $this->seedSingleMemberFamily($tenantId, 'memberB', [
            'first_name' => 'E2E Member',
            'last_name' => 'Beta',
            'date_of_birth' => '1998-11-02',
            'gender' => 'female',
            'relationship_to_head' => 'self',
            'person' => [
                'father_name' => 'Beta Father',
                'mother_name' => 'Beta Mother',
            ],
        ]);
        $this->seedSingleMemberFamily($tenantId, 'fatherOnly', [
            'first_name' => 'E2E James',
            'last_name' => 'FatherOnly',
            'date_of_birth' => '2001-06-01',
            'gender' => 'male',
            'relationship_to_head' => 'self',
            'person' => [
                'father_name' => 'James E2E Sr',
                'mother_name' => null,
            ],
        ]);
        $this->seedSingleMemberFamily($tenantId, 'motherOnly', [
            'first_name' => 'E2E Sarah',
            'last_name' => 'MotherOnly',
            'date_of_birth' => '2002-07-02',
            'gender' => 'female',
            'relationship_to_head' => 'self',
            'person' => [
                'father_name' => null,
                'mother_name' => 'Sarah E2E Sr',
            ],
        ]);
        $this->seedSingleMemberFamily($tenantId, 'noParents', [
            'first_name' => 'E2E Alex',
            'last_name' => 'NoParents',
            'date_of_birth' => '2004-08-03',
            'gender' => 'male',
            'relationship_to_head' => 'self',
            'person' => [
                'father_name' => null,
                'mother_name' => null,
            ],
        ]);

        $this->command?->info('Sacrament E2E fixtures seeded for tenant '.$tenantId);
    }

    private function seedBothParentsFamily(string $tenantId): void
    {
        $familyId = self::BOTH_PARENTS_FAMILY_ID;

        Family::query()->updateOrCreate(
            ['id' => $familyId],
            [
                'tenant_id' => $tenantId,
                'family_code' => 'E2E-BOTH',
                'family_name' => 'E2E BothParents Family',
                'head_of_family' => 'John E2E Anderson',
                'status' => 'active',
                'notes' => self::MARKER,
            ]
        );

        $fatherPersonId = 'e2e00002-0001-4000-8000-000000000011';
        $motherPersonId = 'e2e00002-0001-4000-8000-000000000012';
        $childPersonId = 'e2e00002-0001-4000-8000-000000000013';

        $this->upsertPerson($fatherPersonId, $tenantId, [
            'first_name' => 'John E2E',
            'last_name' => 'Anderson',
            'gender' => 'male',
        ]);
        $this->upsertPerson($motherPersonId, $tenantId, [
            'first_name' => 'Mary E2E',
            'last_name' => 'Anderson',
            'gender' => 'female',
        ]);
        $this->upsertPerson($childPersonId, $tenantId, [
            'first_name' => 'E2E Olivia',
            'last_name' => 'BothParents',
            'date_of_birth' => '2003-09-27',
            'gender' => 'female',
        ]);

        $this->upsertMember('e2e00003-0001-4000-8000-000000000011', $familyId, $fatherPersonId, [
            'first_name' => 'John E2E',
            'last_name' => 'Anderson',
            'gender' => 'male',
            'relationship_to_head' => 'self',
            'is_primary_contact' => true,
        ]);
        $this->upsertMember('e2e00003-0001-4000-8000-000000000012', $familyId, $motherPersonId, [
            'first_name' => 'Mary E2E',
            'last_name' => 'Anderson',
            'gender' => 'female',
            'relationship_to_head' => 'spouse',
        ]);
        $this->upsertMember('e2e00003-0001-4000-8000-000000000013', $familyId, $childPersonId, [
            'first_name' => 'E2E Olivia',
            'last_name' => 'BothParents',
            'date_of_birth' => '2003-09-27',
            'gender' => 'female',
            'relationship_to_head' => 'daughter',
        ]);
    }

    /**
     * @param  array<string, mixed>  $memberData
     */
    private function seedSingleMemberFamily(string $tenantId, string $key, array $memberData): void
    {
        $ids = self::SINGLE_MEMBER_IDS[$key];
        $familyId = $ids['family'];
        $familyCode = 'E2E-'.strtoupper($key);
        $fullName = trim(($memberData['first_name'] ?? '').' '.($memberData['last_name'] ?? ''));

        Family::query()->updateOrCreate(
            ['id' => $familyId],
            [
                'tenant_id' => $tenantId,
                'family_code' => $familyCode,
                'family_name' => $fullName.' Family',
                'head_of_family' => $fullName,
                'status' => 'active',
                'notes' => self::MARKER,
            ]
        );

        $personAttrs = array_merge([
            'first_name' => $memberData['first_name'],
            'last_name' => $memberData['last_name'],
            'date_of_birth' => $memberData['date_of_birth'] ?? null,
            'gender' => $memberData['gender'] ?? null,
            'father_name' => null,
            'mother_name' => null,
        ], $memberData['person'] ?? []);

        $this->upsertPerson($ids['person'], $tenantId, $personAttrs);

        $this->upsertMember($ids['member'], $familyId, $ids['person'], [
            'first_name' => $memberData['first_name'],
            'last_name' => $memberData['last_name'],
            'date_of_birth' => $memberData['date_of_birth'] ?? null,
            'gender' => $memberData['gender'] ?? null,
            'relationship_to_head' => $memberData['relationship_to_head'] ?? 'self',
            'is_primary_contact' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function upsertPerson(string $id, string $tenantId, array $attrs): Person
    {
        return Person::query()->updateOrCreate(
            ['id' => $id],
            array_merge($attrs, [
                'tenant_id' => $tenantId,
                'status' => 'active',
            ])
        );
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function upsertMember(string $id, string $familyId, string $personId, array $attrs): FamilyMember
    {
        return FamilyMember::query()->updateOrCreate(
            ['id' => $id],
            array_merge($attrs, [
                'family_id' => $familyId,
                'person_id' => $personId,
                'status' => 'active',
            ])
        );
    }
}
