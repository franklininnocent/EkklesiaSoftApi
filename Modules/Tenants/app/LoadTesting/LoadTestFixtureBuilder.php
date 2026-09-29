<?php

namespace Modules\Tenants\LoadTesting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;

class LoadTestFixtureBuilder
{
    private const PERMISSIONS = [
        'families.view',
        'donations.view',
        'sacraments.view',
    ];

    /**
     * @param  array{
     *     tag: string,
     *     tenants: int,
     *     members: int,
     *     members_per_family: int,
     *     chunk_size: int,
     *     export_tokens: bool,
     * }  $options
     */
    public function seed(array $options): LoadTestFixtureResult
    {
        $started = microtime(true);
        $tag = $options['tag'];
        $tenantCount = max(1, (int) $options['tenants']);
        $totalMembers = max(1, (int) $options['members']);
        $membersPerFamily = max(1, (int) $options['members_per_family']);
        $chunkSize = max(100, (int) $options['chunk_size']);
        $exportTokens = (bool) $options['export_tokens'];

        $membersPerTenant = (int) ceil($totalMembers / $tenantCount);
        $familiesPerTenant = (int) ceil($membersPerTenant / $membersPerFamily);

        $this->ensurePermissions();

        $tenantSummaries = [];
        $totalFamilies = 0;
        $totalMembersInserted = 0;

        for ($tenantIndex = 1; $tenantIndex <= $tenantCount; $tenantIndex++) {
            $remainingMembers = $totalMembers - $totalMembersInserted;
            if ($remainingMembers <= 0) {
                break;
            }

            $tenantMemberTarget = min($membersPerTenant, $remainingMembers);
            $tenantFamilyTarget = (int) ceil($tenantMemberTarget / $membersPerFamily);

            $summary = DB::transaction(function () use (
                $tag,
                $tenantIndex,
                $tenantMemberTarget,
                $tenantFamilyTarget,
                $membersPerFamily,
                $chunkSize,
                $exportTokens,
            ): array {
                $now = now();
                $slug = sprintf('load-test-%s-%04d', $tag, $tenantIndex);

                $tenantId = (int) DB::table('tenants')->insertGetId([
                    'name' => sprintf('Load Test Parish %s #%d', $tag, $tenantIndex),
                    'slug' => $slug,
                    'plan' => 'enterprise',
                    'max_users' => 999999,
                    'max_storage_mb' => 50000,
                    'trial_ends_at' => $now->copy()->addYear(),
                    'subscription_ends_at' => $now->copy()->addYear(),
                    'active' => 1,
                    'settings' => json_encode([
                        'load_test_tag' => $tag,
                        'load_test_seeded_at' => $now->toIso8601String(),
                    ]),
                    'features' => json_encode(['donations', 'events', 'groups']),
                    'primary_color' => '#3B82F6',
                    'secondary_color' => '#10B981',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $roleId = (int) DB::table('roles')->insertGetId([
                    'name' => 'Load Test Admin',
                    'description' => 'Load test actor role',
                    'level' => 2,
                    'active' => 1,
                    'tenant_id' => $tenantId,
                    'is_custom' => true,
                    'role_type' => Role::ROLE_TYPE_TENANT,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $permissionIds = Permission::query()
                    ->whereIn('name', self::PERMISSIONS)
                    ->pluck('id')
                    ->all();

                foreach ($permissionIds as $permissionId) {
                    DB::table('permission_role')->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }

                $email = sprintf('load-test-%s-%d@ekklesia.test', $tag, $tenantId);
                $userId = (int) DB::table('users')->insertGetId([
                    'name' => sprintf('Load Test User %d', $tenantId),
                    'email' => $email,
                    'email_verified_at' => $now,
                    'password' => Hash::make('load-test-password'),
                    'user_type' => 1,
                    'is_primary_admin' => false,
                    'active' => true,
                    'tenant_id' => $tenantId,
                    'role_id' => $roleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('role_user')->insertOrIgnore([
                    'role_id' => $roleId,
                    'user_id' => $userId,
                ]);

                $membersInserted = 0;
                $familiesInserted = 0;

                for ($familyOffset = 0; $familyOffset < $tenantFamilyTarget; $familyOffset += $chunkSize) {
                    $familyBatchSize = min($chunkSize, $tenantFamilyTarget - $familyOffset);
                    $familyRows = [];
                    $familyIds = [];

                    for ($familyIndex = 0; $familyIndex < $familyBatchSize; $familyIndex++) {
                        $familyNumber = $familyOffset + $familyIndex + 1;
                        $familyId = (string) Str::uuid();
                        $familyIds[] = $familyId;

                        $familyRows[] = [
                            'id' => $familyId,
                            'tenant_id' => $tenantId,
                            'family_code' => sprintf('LT-%d-%06d', $tenantId, $familyNumber),
                            'family_name' => sprintf('Load Test Family %d', $familyNumber),
                            'head_of_family' => sprintf('Head %d', $familyNumber),
                            'status' => 'active',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    DB::table('families')->insert($familyRows);
                    $familiesInserted += count($familyRows);

                    $personRows = [];
                    $memberRows = [];

                    foreach ($familyIds as $familyIndex => $familyId) {
                        $membersForFamily = min(
                            $membersPerFamily,
                            $tenantMemberTarget - $membersInserted - count($memberRows)
                        );

                        if ($membersForFamily <= 0) {
                            break;
                        }

                        for ($memberIndex = 0; $memberIndex < $membersForFamily; $memberIndex++) {
                            $personId = (string) Str::uuid();
                            $memberId = (string) Str::uuid();
                            $memberNumber = $membersInserted + count($memberRows) + 1;

                            $personRows[] = [
                                'id' => $personId,
                                'tenant_id' => $tenantId,
                                'first_name' => 'Member',
                                'middle_name' => null,
                                'last_name' => sprintf('T%d-%d', $tenantId, $memberNumber),
                                'date_of_birth' => '1990-01-15',
                                'gender' => $memberIndex % 2 === 0 ? 'male' : 'female',
                                'status' => 'active',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];

                            $memberRows[] = [
                                'id' => $memberId,
                                'tenant_id' => $tenantId,
                                'family_id' => $familyId,
                                'person_id' => $personId,
                                'first_name' => 'Member',
                                'middle_name' => null,
                                'last_name' => sprintf('T%d-%d', $tenantId, $memberNumber),
                                'date_of_birth' => '1990-01-15',
                                'gender' => $memberIndex % 2 === 0 ? 'male' : 'female',
                                'relationship_to_head' => $memberIndex === 0 ? 'self' : 'other',
                                'marital_status' => 'single',
                                'is_primary_contact' => $memberIndex === 0,
                                'status' => 'active',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }

                    foreach (array_chunk($personRows, $chunkSize) as $chunk) {
                        DB::table('persons')->insert($chunk);
                    }

                    foreach (array_chunk($memberRows, $chunkSize) as $chunk) {
                        DB::table('family_members')->insert($chunk);
                    }

                    $membersInserted += count($memberRows);

                    if ($membersInserted >= $tenantMemberTarget) {
                        break;
                    }
                }

                $token = null;
                if ($exportTokens) {
                    $user = User::query()->findOrFail($userId);
                    $token = $user->createToken('load-test')->accessToken;
                }

                return [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'token' => $token,
                    'families' => $familiesInserted,
                    'members' => $membersInserted,
                ];
            });

            $tenantSummaries[] = $summary;
            $totalFamilies += $summary['families'];
            $totalMembersInserted += $summary['members'];
        }

        return new LoadTestFixtureResult(
            tag: $tag,
            tenantCount: count($tenantSummaries),
            memberCount: $totalMembersInserted,
            familyCount: $totalFamilies,
            tenants: $tenantSummaries,
            elapsedSeconds: microtime(true) - $started,
        );
    }

    public function purge(string $tag): int
    {
        $tenantIds = Tenant::query()
            ->where('settings->load_test_tag', $tag)
            ->pluck('id')
            ->all();

        if ($tenantIds === []) {
            return 0;
        }

        Tenant::query()->whereIn('id', $tenantIds)->forceDelete();

        return count($tenantIds);
    }

    private function ensurePermissions(): void
    {
        foreach (self::PERMISSIONS as $permissionName) {
            Permission::updateOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'description' => 'Load test permission',
                    'module' => 'LoadTesting',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'load_test',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
        }
    }
}
