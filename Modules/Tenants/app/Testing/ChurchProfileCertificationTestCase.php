<?php

namespace Modules\Tenants\Testing;

use Laravel\Passport\Passport;
use Modules\Family\Models\Person;
use Modules\Tenants\Database\Seeders\LeadershipRolesSeeder;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\ChurchSocialMedia;
use Modules\Tenants\Models\ChurchStatistic;
use Modules\Tenants\Models\Tenant;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

abstract class ChurchProfileCertificationTestCase extends TestCase
{
    use ActsAsTenantRoles;

    /**
     * @param  list<string>  $permissions
     * @return array{tenant: Tenant, user: mixed, role: mixed}
     */
    protected function actingAsTenantWith(array $permissions, ?Tenant $tenant = null): array
    {
        $context = $this->makeTenantPersona(
            $tenant,
            'Church Profile Role '.uniqid('', true),
            $permissions,
            [
                'is_custom' => true,
                'level' => 3,
            ]
        );
        Passport::actingAs($context['user']);

        return $context;
    }

    protected function seedChurchProfile(Tenant $tenant, array $overrides = []): ChurchProfile
    {
        return ChurchProfile::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'country' => 'India',
            'phone' => '+911234567890',
            'email' => 'parish@example.test',
            'about' => 'Parish about text',
            'patron_name' => 'St Mary',
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshotProfile(ChurchProfile $profile): array
    {
        $profile->refresh();

        return [
            'id' => $profile->id,
            'tenant_id' => $profile->tenant_id,
            'patron_name' => $profile->patron_name,
            'about' => $profile->about,
            'email' => $profile->email,
            'phone' => $profile->phone,
            'patron_image_path' => $profile->patron_image_path,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validProfilePayload(array $overrides = []): array
    {
        return array_merge([
            'patron_name' => 'St Joseph',
            'about' => 'Updated parish identity',
            'vision' => 'A faithful parish',
            'mission' => 'Serve families',
            'core_values' => 'Faith, family, service',
            'service_times' => 'Sunday 8:00 AM',
            'country' => 'India',
            'phone' => '+919876543210',
            'email' => 'updated@parish.test',
            'website' => 'https://parish.example.test',
            'founded_year' => 1952,
        ], $overrides);
    }

    protected function seedStatistic(Tenant $tenant, array $overrides = []): ChurchStatistic
    {
        return ChurchStatistic::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'year' => 2026,
            'month' => 1,
            'membership_count' => 120,
            'weekly_attendance' => 90,
            'baptisms' => 4,
            'confirmations' => 2,
            'marriages' => 1,
            'funerals' => 0,
            'tithes_offerings' => 1500.50,
            'notes' => 'January snapshot',
        ], $overrides));
    }

    protected function seedSocial(Tenant $tenant, array $overrides = []): ChurchSocialMedia
    {
        return ChurchSocialMedia::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'platform' => 'facebook',
            'url' => 'https://facebook.com/parish',
            'username' => 'parish',
            'follower_count' => 200,
            'is_primary' => 1,
            'display_order' => 0,
            'active' => 1,
        ], $overrides));
    }

    protected function makePerson(Tenant $tenant, string $first = 'John', string $last = 'Smith'): Person
    {
        return Person::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    protected function seedLeadershipRoles(): void
    {
        $this->seed(LeadershipRolesSeeder::class);
    }
}
