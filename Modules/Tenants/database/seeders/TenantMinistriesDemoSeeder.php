<?php

namespace Modules\Tenants\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Family\Models\FamilyMember;
use Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsDefaultSeeder;
use Modules\MinistriesAssociations\DefaultSeeds\OrganizationCategoriesDefaultSeedDefinition;
use Modules\MinistriesAssociations\DefaultSeeds\OrganizationTypesDefaultSeedDefinition;
use Modules\MinistriesAssociations\DefaultSeeds\PositionsDefaultSeedDefinition;
use Modules\MinistriesAssociations\Models\GuestMember;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\MinistriesAssociations\Models\Position;
use Modules\Tenants\Database\Seeders\Support\MinistriesDemoCatalog;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;

/**
 * Comprehensive Ministries & Associations demo fixtures for a parish tenant.
 *
 * Run via {@see TenantDemoDataOrchestratorSeeder} or:
 * TENANT_DEMO_TENANT_ID=1 php artisan db:seed --class=Modules\\Tenants\\Database\\Seeders\\TenantMinistriesDemoSeeder
 */
class TenantMinistriesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $requested = TenantDemoResolver::readEnvTenantId();
            if ($requested !== null) {
                $this->command?->error(sprintf('Tenant #%d not found for ministries demo.', $requested));
            } else {
                $this->command?->error('Tenant not found. Set TENANT_DEMO_TENANT_ID to your parish tenant id.');
            }

            return;
        }

        $tenantId = (int) $tenant->id;
        $actor = TenantDemoResolver::resolveActor($tenantId);
        $actorId = $actor?->id;
        if ($actor) {
            TenantDemoResolver::bindTenantContext($actor, $tenantId);
        }

        OrganizationCategory::runWithoutTenantScope(function () use ($tenantId, $actorId): void {
            $this->seedTaxonomy($tenantId, $actorId);
            (new MinistriesAssociationsDefaultSeeder)->run($tenantId, $actorId);
        });

        $organizations = $this->seedOrganizations($tenantId, $actorId);
        if ($organizations === []) {
            $organizations = $this->loadCatalogOrganizations($tenantId);
        }
        if ($organizations === []) {
            $this->command?->error('Ministries demo: no organizations available to seed.');

            return;
        }

        if ($this->membershipDemoAlreadyPresent($tenantId)) {
            $this->command?->info('Ministries demo memberships already present (idempotent skip).');

            return;
        }

        $members = FamilyMember::query()
            ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'active'))
            ->orderBy('id')
            ->get(['id']);

        if ($members->count() < 15) {
            $this->command?->warn(sprintf(
                'Ministries demo: only %d family members; some scenarios may be thinner than planned.',
                $members->count()
            ));
        }

        if ($members->isEmpty()) {
            $this->command?->warn('Skipping ministries membership demo: no family members yet.');

            return;
        }

        $guests = $this->seedGuestMembers($tenantId, $actorId);
        $positions = $this->loadPositionsByCode($tenantId);

        $memberOffset = 0;
        $stats = ['memberships' => 0, 'leadership' => 0, 'guest_memberships' => 0];

        OrganizationCategory::runWithoutTenantScope(function () use (
            $tenantId,
            $actorId,
            $organizations,
            $members,
            $guests,
            $positions,
            &$memberOffset,
            &$stats
        ): void {
            DB::transaction(function () use (
                $tenantId,
                $actorId,
                $organizations,
                $members,
                $guests,
                $positions,
                &$memberOffset,
                &$stats
            ): void {
                foreach (MinistriesDemoCatalog::organizations() as $spec) {
                    $organization = $organizations[$spec['code']] ?? null;
                    if (! $organization) {
                        continue;
                    }

                    $pool = $this->sliceMembers($members, $memberOffset, (int) ($spec['member_count'] ?? 0));
                    $memberOffset += (int) ($spec['member_count'] ?? 0);

                    $membershipByIndex = $this->seedBaseMemberships(
                        $tenantId,
                        $organization,
                        $pool,
                        $actorId,
                        (string) ($spec['default_member_type'] ?? 'regular'),
                    );
                    $stats['memberships'] += count($membershipByIndex);

                    $stats['memberships'] += $this->seedMembershipVariants(
                        $tenantId,
                        $organization,
                        $members,
                        $spec,
                        $actorId,
                        $membershipByIndex,
                    );

                    if (isset($spec['re_enroll_pool_index'])) {
                        $stats['memberships'] += $this->seedReEnrollmentScenario(
                            $tenantId,
                            $organization,
                            $pool,
                            (int) $spec['re_enroll_pool_index'],
                            $actorId,
                        );
                    }

                    $stats['leadership'] += $this->seedLeadershipTerms(
                        $tenantId,
                        $organization,
                        $spec,
                        $membershipByIndex,
                        $positions,
                        $actorId,
                    );

                    $stats['leadership'] += $this->seedCompletedLeadership(
                        $tenantId,
                        $organization,
                        $spec,
                        $members,
                        $positions,
                        $actorId,
                    );

                    if (! empty($spec['guest_enrollment']) && isset($guests['guest_ymca_volunteer'])) {
                        $stats['guest_memberships'] += $this->seedGuestMembership(
                            $tenantId,
                            $organization,
                            $guests['guest_ymca_volunteer'],
                            $positions,
                            $actorId,
                            (bool) ($spec['guests_can_hold_office'] ?? false),
                        );
                    }
                }

                $this->seedYouthAssociationMemberships($tenantId, $actorId, $members, $organizations);
            });
        });

        $this->command?->info(sprintf(
            'Ministries demo: %d orgs, %d memberships, %d leadership terms, %d guest enrollments (tenant #%d).',
            count($organizations),
            $stats['memberships'],
            $stats['leadership'],
            $stats['guest_memberships'],
            $tenantId,
        ));
    }

    private function seedTaxonomy(int $tenantId, ?int $actorId): void
    {
        (new OrganizationCategoriesDefaultSeedDefinition)->execute($tenantId, $actorId);
        (new OrganizationTypesDefaultSeedDefinition)->execute($tenantId, $actorId);
        (new PositionsDefaultSeedDefinition)->execute($tenantId, $actorId);
    }

    /**
     * @return array<string, Organization>
     */
    private function loadCatalogOrganizations(int $tenantId): array
    {
        return OrganizationCategory::runWithoutTenantScope(function () use ($tenantId): array {
            $codes = MinistriesDemoCatalog::organizationCodes();
            $rows = Organization::query()
                ->forTenant($tenantId)
                ->whereIn('code', $codes)
                ->get();

            $result = [];
            foreach ($rows as $organization) {
                $result[$organization->code] = $organization;
            }

            return $result;
        });
    }

    /**
     * @return array<string, Organization>
     */
    private function seedOrganizations(int $tenantId, ?int $userId): array
    {
        return OrganizationCategory::runWithoutTenantScope(function () use ($tenantId, $userId): array {
        $categories = OrganizationCategory::query()
            ->forTenant($tenantId)
            ->get()
            ->keyBy('code');
        $types = OrganizationType::query()
            ->forTenant($tenantId)
            ->get()
            ->keyBy('code');

        $result = [];

        foreach (MinistriesDemoCatalog::organizations() as $spec) {
            $category = $categories->get($spec['category_code'])
                ?? $categories->get('association')
                ?? $categories->first();
            $type = $types->get($spec['type_code'])
                ?? $types->get('association')
                ?? $types->first();

            if (! $category || ! $type) {
                $this->command?->warn('Ministries demo: taxonomy missing for '.$spec['code']);

                continue;
            }

            $existing = Organization::withTrashed()
                ->forTenant($tenantId)
                ->where('code', $spec['code'])
                ->first();

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $result[$spec['code']] = $existing;

                continue;
            }

            $payload = [
                'tenant_id' => $tenantId,
                'category_id' => $category->id,
                'type_id' => $type->id,
                'code' => $spec['code'],
                'name' => $spec['name'],
                'short_name' => $spec['short_name'] ?? null,
                'description' => $spec['description'] ?? null,
                'vision' => $spec['vision'] ?? null,
                'mission' => $spec['mission'] ?? null,
                'objectives' => $spec['objectives'] ?? null,
                'patron_saint' => $spec['patron_saint'] ?? null,
                'established_date' => $spec['established_date'] ?? null,
                'theme_color' => $spec['theme_color'] ?? null,
                'email' => $spec['email'] ?? null,
                'phone' => $spec['phone'] ?? null,
                'website' => $spec['website'] ?? null,
                'social_links' => $spec['social_links'] ?? null,
                'status' => $spec['status'] ?? Organization::STATUS_ACTIVE,
                'allow_multi_role_holding' => (bool) ($spec['allow_multi_role_holding'] ?? false),
                'guests_can_hold_office' => (bool) ($spec['guests_can_hold_office'] ?? false),
                'created_by' => $userId,
                'updated_by' => $userId,
            ];

            $result[$spec['code']] = Organization::create($payload);
        }

        return $result;
        });
    }

    private function membershipDemoAlreadyPresent(int $tenantId): bool
    {
        return OrganizationCategory::runWithoutTenantScope(function () use ($tenantId): bool {
        $anchor = Organization::query()
            ->forTenant($tenantId)
            ->where('code', MinistriesDemoCatalog::ANCHOR_CODE)
            ->first();

        if (! $anchor) {
            return false;
        }

        return OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $anchor->id)
            ->where('remarks', TenantDemoMarkers::MARKER)
            ->exists();
        });
    }

    /**
     * @return array<string, GuestMember>
     */
    private function seedGuestMembers(int $tenantId, ?int $actorId): array
    {
        return OrganizationCategory::runWithoutTenantScope(function () use ($tenantId, $actorId): array {
        $guests = [];

        foreach (MinistriesDemoCatalog::guestMembers() as $spec) {
            $existing = GuestMember::query()
                ->forTenant($tenantId)
                ->where('email', $spec['email'])
                ->first();

            if ($existing) {
                $guests[$spec['key']] = $existing;

                continue;
            }

            $guests[$spec['key']] = GuestMember::create([
                'tenant_id' => $tenantId,
                'first_name' => $spec['first_name'],
                'last_name' => $spec['last_name'],
                'gender' => $spec['gender'] ?? null,
                'phone' => $spec['phone'] ?? null,
                'email' => $spec['email'],
                'guest_type' => $spec['guest_type'],
                'external_organization' => $spec['external_organization'] ?? null,
                'support_type' => $spec['support_type'] ?? null,
                'remarks' => TenantDemoMarkers::MARKER,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }

        return $guests;
        });
    }

    /**
     * @return array<string, Position>
     */
    private function loadPositionsByCode(int $tenantId): array
    {
        return OrganizationCategory::runWithoutTenantScope(function () use ($tenantId): array {
            return Position::query()
                ->forTenant($tenantId)
                ->get()
                ->keyBy('code')
                ->all();
        });
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @return Collection<int, FamilyMember>
     */
    private function sliceMembers(Collection $members, int $offset, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        return $members->slice($offset, $count)->values();
    }

    /**
     * @param  Collection<int, FamilyMember>  $pool
     * @return array<int, OrganizationMembership>
     */
    private function seedBaseMemberships(
        int $tenantId,
        Organization $organization,
        Collection $pool,
        ?int $actorId,
        string $defaultMemberType,
    ): array {
        $byIndex = [];
        $joinedBase = Carbon::now()->subMonths(18);

        foreach ($pool->values() as $index => $member) {
            if ($this->hasCurrentParishMembership($tenantId, $organization->id, (string) $member->id)) {
                continue;
            }

            $membership = OrganizationMembership::query()->create([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'member_source' => OrganizationMembership::SOURCE_PARISH,
                'family_member_id' => $member->id,
                'member_type' => $defaultMemberType,
                'status' => OrganizationMembership::STATUS_ACTIVE,
                'joined_date' => $joinedBase->copy()->addWeeks($index)->toDateString(),
                'remarks' => TenantDemoMarkers::MARKER,
                'is_current' => true,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $byIndex[$index] = $membership;
        }

        return $byIndex;
    }

    /**
     * @param  Collection<int, FamilyMember>  $allMembers
     * @param  array<int, OrganizationMembership>  $membershipByIndex
     */
    private function seedMembershipVariants(
        int $tenantId,
        Organization $organization,
        Collection $allMembers,
        array $spec,
        ?int $actorId,
        array $membershipByIndex,
    ): int {
        $created = 0;
        $variants = $spec['membership_variants'] ?? [];

        foreach ($variants as $variant) {
            $memberIndex = (int) $variant['member_index'];
            $member = $allMembers->get($memberIndex);
            if (! $member) {
                continue;
            }

            if (! empty($variant['historical'])) {
                $created += $this->createHistoricalMembership(
                    $tenantId,
                    $organization,
                    (string) $member->id,
                    (string) ($variant['status'] ?? OrganizationMembership::STATUS_EXITED),
                    (string) ($variant['member_type'] ?? 'regular'),
                    $actorId,
                );

                continue;
            }

            $current = $membershipByIndex[$memberIndex] ?? null;
            if ($current) {
                $current->update([
                    'status' => $variant['status'],
                    'member_type' => $variant['member_type'] ?? $current->member_type,
                    'is_current' => $variant['status'] === OrganizationMembership::STATUS_SUSPENDED,
                    'updated_by' => $actorId,
                ]);

                continue;
            }

            if ($this->hasCurrentParishMembership($tenantId, $organization->id, (string) $member->id)) {
                continue;
            }

            OrganizationMembership::query()->create([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'member_source' => OrganizationMembership::SOURCE_PARISH,
                'family_member_id' => $member->id,
                'member_type' => $variant['member_type'] ?? 'regular',
                'status' => $variant['status'],
                'joined_date' => Carbon::now()->subMonths(3)->toDateString(),
                'remarks' => TenantDemoMarkers::MARKER,
                'is_current' => $variant['status'] === OrganizationMembership::STATUS_SUSPENDED,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $created++;
        }

        return $created;
    }

    private function createHistoricalMembership(
        int $tenantId,
        Organization $organization,
        string $familyMemberId,
        string $status,
        string $memberType,
        ?int $actorId,
    ): int {
        if (OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organization->id)
            ->where('family_member_id', $familyMemberId)
            ->where('remarks', TenantDemoMarkers::MARKER)
            ->where('is_current', false)
            ->exists()) {
            return 0;
        }

        OrganizationMembership::query()->create([
            'tenant_id' => $tenantId,
            'organization_id' => $organization->id,
            'member_source' => OrganizationMembership::SOURCE_PARISH,
            'family_member_id' => $familyMemberId,
            'member_type' => $memberType,
            'status' => $status,
            'joined_date' => Carbon::now()->subYears(3)->toDateString(),
            'exit_date' => Carbon::now()->subMonths(8)->toDateString(),
            'exit_reason' => $status === OrganizationMembership::STATUS_RESIGNED
                ? 'Relocated for work'
                : 'Completed term of service',
            'remarks' => TenantDemoMarkers::MARKER,
            'is_current' => false,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return 1;
    }

    /**
     * @param  Collection<int, FamilyMember>  $pool
     */
    private function seedReEnrollmentScenario(
        int $tenantId,
        Organization $organization,
        Collection $pool,
        int $poolIndex,
        ?int $actorId,
    ): int {
        $member = $pool->get($poolIndex);
        if (! $member) {
            return 0;
        }

        if (OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organization->id)
            ->where('family_member_id', $member->id)
            ->where('remarks', TenantDemoMarkers::MARKER)
            ->where('is_current', false)
            ->where('status', OrganizationMembership::STATUS_EXITED)
            ->where('exit_reason', 'Temporary relocation')
            ->exists()) {
            return 0;
        }

        $current = OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organization->id)
            ->where('family_member_id', $member->id)
            ->where('is_current', true)
            ->first();

        if ($current) {
            $current->update([
                'status' => OrganizationMembership::STATUS_EXITED,
                'is_current' => false,
                'exit_date' => Carbon::now()->subYear()->toDateString(),
                'exit_reason' => 'Temporary relocation',
                'remarks' => TenantDemoMarkers::MARKER,
                'updated_by' => $actorId,
            ]);
        } else {
            OrganizationMembership::query()->create([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'member_source' => OrganizationMembership::SOURCE_PARISH,
                'family_member_id' => $member->id,
                'member_type' => 'regular',
                'status' => OrganizationMembership::STATUS_EXITED,
                'joined_date' => Carbon::now()->subYears(2)->toDateString(),
                'exit_date' => Carbon::now()->subYear()->toDateString(),
                'exit_reason' => 'Temporary relocation',
                'remarks' => TenantDemoMarkers::MARKER,
                'is_current' => false,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }

        if ($this->hasCurrentParishMembership($tenantId, $organization->id, (string) $member->id)) {
            return 1;
        }

        OrganizationMembership::query()->create([
            'tenant_id' => $tenantId,
            'organization_id' => $organization->id,
            'member_source' => OrganizationMembership::SOURCE_PARISH,
            'family_member_id' => $member->id,
            'member_type' => 'regular',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_date' => Carbon::now()->subMonths(2)->toDateString(),
            'remarks' => TenantDemoMarkers::MARKER,
            'is_current' => true,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return 2;
    }

    /**
     * @param  array<int, OrganizationMembership>  $membershipByIndex
     * @param  array<string, Position>  $positions
     */
    private function seedLeadershipTerms(
        int $tenantId,
        Organization $organization,
        array $spec,
        array $membershipByIndex,
        array $positions,
        ?int $actorId,
    ): int {
        $created = 0;
        $leadership = $spec['leadership'] ?? [];

        foreach ($leadership as $row) {
            $position = $positions[$row['position']] ?? null;
            $membership = $membershipByIndex[(int) $row['member_index']] ?? null;
            if (! $position || ! $membership) {
                continue;
            }

            if ($membership->status !== OrganizationMembership::STATUS_ACTIVE) {
                continue;
            }

            if ($this->hasActiveLeadership($tenantId, $organization->id, $position->id)) {
                continue;
            }

            $effectiveFrom = Carbon::now()->subMonths(6);
            $effectiveTo = null;
            if (! empty($row['effective_to']) && is_string($row['effective_to']) && str_starts_with($row['effective_to'], '+')) {
                $days = (int) filter_var($row['effective_to'], FILTER_SANITIZE_NUMBER_INT);
                $effectiveTo = Carbon::now()->addDays($days)->toDateString();
            }

            LeadershipTerm::query()->create([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'membership_id' => $membership->id,
                'position_id' => $position->id,
                'appointment_date' => $effectiveFrom->toDateString(),
                'effective_from' => $effectiveFrom->toDateString(),
                'effective_to' => $effectiveTo,
                'term_label' => '2024–2026',
                'is_interim' => (bool) ($row['is_interim'] ?? false),
                'status' => LeadershipTerm::STATUS_ACTIVE,
                'remarks' => TenantDemoMarkers::MARKER,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @param  array<string, Position>  $positions
     */
    private function seedCompletedLeadership(
        int $tenantId,
        Organization $organization,
        array $spec,
        Collection $members,
        array $positions,
        ?int $actorId,
    ): int {
        $created = 0;
        $rows = $spec['completed_leadership'] ?? [];

        foreach ($rows as $row) {
            $position = $positions[$row['position']] ?? null;
            $member = $members->get((int) $row['member_index']);
            if (! $position || ! $member) {
                continue;
            }

            if (LeadershipTerm::query()
                ->where('tenant_id', $tenantId)
                ->where('organization_id', $organization->id)
                ->where('position_id', $position->id)
                ->where('remarks', TenantDemoMarkers::MARKER)
                ->where('status', LeadershipTerm::STATUS_COMPLETED)
                ->exists()) {
                continue;
            }

            $membership = OrganizationMembership::query()
                ->where('tenant_id', $tenantId)
                ->where('organization_id', $organization->id)
                ->where('family_member_id', $member->id)
                ->where('remarks', TenantDemoMarkers::MARKER)
                ->orderByDesc('is_current')
                ->first();

            if (! $membership) {
                continue;
            }

            $from = Carbon::now()->modify($row['effective_from']);
            $to = Carbon::now()->modify($row['effective_to']);

            LeadershipTerm::query()->create([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'membership_id' => $membership->id,
                'position_id' => $position->id,
                'appointment_date' => $from->toDateString(),
                'effective_from' => $from->toDateString(),
                'effective_to' => $to->toDateString(),
                'term_label' => '2022–2024',
                'status' => LeadershipTerm::STATUS_COMPLETED,
                'exit_reason' => LeadershipTerm::EXIT_REASON_TERM_COMPLETED,
                'remarks' => TenantDemoMarkers::MARKER,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param  array<string, Position>  $positions
     */
    private function seedGuestMembership(
        int $tenantId,
        Organization $organization,
        GuestMember $guest,
        array $positions,
        ?int $actorId,
        bool $guestsCanHoldOffice,
    ): int {
        if (OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organization->id)
            ->where('guest_member_id', $guest->id)
            ->where('is_current', true)
            ->exists()) {
            return 0;
        }

        $membership = OrganizationMembership::query()->create([
            'tenant_id' => $tenantId,
            'organization_id' => $organization->id,
            'member_source' => OrganizationMembership::SOURCE_GUEST,
            'guest_member_id' => $guest->id,
            'member_type' => 'regular',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_date' => Carbon::now()->subMonths(4)->toDateString(),
            'remarks' => TenantDemoMarkers::MARKER,
            'is_current' => true,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $created = 1;

        if ($guestsCanHoldOffice) {
            $position = $positions['committee_member'] ?? $positions['member'] ?? null;
            if ($position && ! $this->hasActiveLeadership($tenantId, $organization->id, $position->id)) {
                LeadershipTerm::query()->create([
                    'tenant_id' => $tenantId,
                    'organization_id' => $organization->id,
                    'membership_id' => $membership->id,
                    'position_id' => $position->id,
                    'appointment_date' => Carbon::now()->subMonths(2)->toDateString(),
                    'effective_from' => Carbon::now()->subMonths(2)->toDateString(),
                    'status' => LeadershipTerm::STATUS_ACTIVE,
                    'remarks' => TenantDemoMarkers::MARKER,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @param  array<string, Organization>  $organizations
     */
    private function seedYouthAssociationMemberships(
        int $tenantId,
        ?int $actorId,
        Collection $members,
        array $organizations,
    ): void {
        $organization = Organization::query()
            ->forTenant($tenantId)
            ->where('code', 'YOUTH')
            ->first();

        if (! $organization) {
            return;
        }

        if (OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organization->id)
            ->where('remarks', TenantDemoMarkers::MARKER)
            ->exists()) {
            return;
        }

        $pool = $members->slice(0, 5)->values();
        foreach ($pool as $index => $member) {
            if ($this->hasCurrentParishMembership($tenantId, $organization->id, (string) $member->id)) {
                continue;
            }

            OrganizationMembership::query()->create([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'member_source' => OrganizationMembership::SOURCE_PARISH,
                'family_member_id' => $member->id,
                'member_type' => $index === 0 ? 'regular' : ($index === 4 ? 'junior' : 'regular'),
                'status' => $index === $pool->count() - 1
                    ? OrganizationMembership::STATUS_INACTIVE
                    : OrganizationMembership::STATUS_ACTIVE,
                'joined_date' => Carbon::now()->subMonths(6 - $index)->toDateString(),
                'remarks' => TenantDemoMarkers::MARKER,
                'is_current' => $index !== $pool->count() - 1,
                'exit_date' => $index === $pool->count() - 1
                    ? Carbon::now()->subMonth()->toDateString()
                    : null,
                'exit_reason' => $index === $pool->count() - 1 ? 'Age transition to young adult ministry' : null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }
    }

    private function hasCurrentParishMembership(int $tenantId, string $organizationId, string $familyMemberId): bool
    {
        return OrganizationMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('family_member_id', $familyMemberId)
            ->where('is_current', true)
            ->exists();
    }

    private function hasActiveLeadership(int $tenantId, string $organizationId, string $positionId): bool
    {
        return LeadershipTerm::query()
            ->where('tenant_id', $tenantId)
            ->where('organization_id', $organizationId)
            ->where('position_id', $positionId)
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->exists();
    }
}
