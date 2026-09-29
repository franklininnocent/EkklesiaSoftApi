<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Family\Models\FamilyMember;
use Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsDefaultSeeder;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;

/**
 * Sample Youth Association memberships (parish members + one guest).
 */
class TenantMinistriesMembershipDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found for ministries demo.');

            return;
        }

        $tenantId = (int) $tenant->id;
        $actor = TenantDemoResolver::resolveActor($tenantId);
        $actorId = $actor?->id;

        (new MinistriesAssociationsDefaultSeeder)->run($tenantId, $actorId);

        $organization = Organization::query()
            ->forTenant($tenantId)
            ->where('code', 'YOUTH')
            ->first();

        if (! $organization) {
            $this->command?->warn('Skipping ministries membership demo: Youth Association missing.');

            return;
        }

        if (OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organization->id)
            ->where('remarks', TenantDemoMarkers::MARKER)
            ->exists()) {
            $this->command?->info('Ministries membership demo already present.');

            return;
        }

        $members = FamilyMember::query()
            ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'active'))
            ->orderBy('id')
            ->limit(5)
            ->get(['id']);

        if ($members->isEmpty()) {
            $this->command?->warn('Skipping ministries membership demo: no family members yet.');

            return;
        }

        $created = 0;
        foreach ($members as $index => $member) {
            $exists = OrganizationMembership::query()
                ->where('tenant_id', $tenantId)
                ->where('organization_id', $organization->id)
                ->where('family_member_id', $member->id)
                ->where('is_current', true)
                ->exists();

            if ($exists) {
                continue;
            }

            OrganizationMembership::query()->create([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'member_source' => OrganizationMembership::SOURCE_PARISH,
                'family_member_id' => $member->id,
                'member_type' => $index === 0 ? 'office_bearer' : 'member',
                'status' => $index === $members->count() - 1
                    ? OrganizationMembership::STATUS_INACTIVE
                    : OrganizationMembership::STATUS_ACTIVE,
                'joined_date' => now()->subMonths(6 - $index)->toDateString(),
                'remarks' => TenantDemoMarkers::MARKER,
                'is_current' => $index !== $members->count() - 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $created++;
        }

        $this->command?->info(sprintf(
            'Ministries membership demo: %d memberships (tenant #%d).',
            $created,
            $tenantId
        ));
    }
}
