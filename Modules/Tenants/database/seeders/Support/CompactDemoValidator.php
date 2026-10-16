<?php

namespace Modules\Tenants\Database\Seeders\Support;

use Modules\BCC\Models\BCC;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Family\Database\Seeders\Support\RealisticParishHouseholdBuilder;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\Sacraments\Models\Sacrament;

/**
 * Integrity and compact-shape validation for demo-marked parish fixtures.
 */
final class CompactDemoValidator
{
    public const EXIT_OK = 0;

    public const EXIT_INTEGRITY = 1;

    public const EXIT_COMPACT_SHAPE = 2;

    /**
     * @return list<string>
     */
    public static function integrityErrors(int $tenantId): array
    {
        $errors = [];
        $compactBccIds = self::compactBccIds($tenantId);

        if ($compactBccIds === []) {
            return $errors;
        }

        $compactFamilyIds = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%')
            ->pluck('id');

        foreach ($compactFamilyIds as $familyId) {
            $family = Family::query()->find($familyId);
            if ($family === null) {
                continue;
            }
            if ((int) $family->tenant_id !== $tenantId) {
                $errors[] = 'Compact family '.$family->family_code.' has wrong tenant_id.';
            }
            if ($family->bcc_id === null || ! $compactBccIds->contains($family->bcc_id)) {
                $errors[] = 'Compact family '.$family->family_code.' is not assigned to a compact demo BCC.';
            }
        }

        $orphanMembers = FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('family_id', $compactFamilyIds)
            ->whereHas('family', fn ($q) => $q->where('tenant_id', '!=', $tenantId))
            ->count();

        if ($orphanMembers > 0) {
            $errors[] = 'Compact members reference families outside the demo tenant.';
        }

        if ($compactFamilyIds->isNotEmpty()) {
            $demoPlanIds = ContributionPlan::forTenant($tenantId)->where('code', 'like', 'DEMO_%')->pluck('id');
            if ($demoPlanIds->isNotEmpty()) {
                $stewardshipOnForeign = ContributionDue::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('plan_id', $demoPlanIds)
                    ->whereNotIn('family_id', $compactFamilyIds)
                    ->exists();

                if ($stewardshipOnForeign) {
                    $errors[] = 'Stewardship demo dues exist for non-compact families on this tenant.';
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    public static function compactShapeErrors(int $tenantId): array
    {
        $errors = [];
        $compactBccs = BCC::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('bcc_code', CompactDemoMarkers::BCC_CODES)
            ->where('notes', 'like', '%'.TenantDemoMarkers::MARKER.'%')
            ->get();

        if ($compactBccs->count() !== count(CompactDemoMarkers::BCC_CODES)) {
            $errors[] = sprintf(
                'Expected exactly %d compact BCCs (found %d).',
                count(CompactDemoMarkers::BCC_CODES),
                $compactBccs->count()
            );
        }

        foreach ($compactBccs as $bcc) {
            $totalDummy = Family::query()
                ->where('tenant_id', $tenantId)
                ->where('bcc_id', $bcc->id)
                ->whereNull('deleted_at')
                ->where('notes', 'like', '%'.RealisticParishHouseholdBuilder::MARKER.'%')
                ->count();

            $compactCount = Family::query()
                ->where('tenant_id', $tenantId)
                ->where('bcc_id', $bcc->id)
                ->whereNull('deleted_at')
                ->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%')
                ->count();

            if ($totalDummy > CompactDemoMarkers::FAMILIES_PER_BCC && $compactCount < CompactDemoMarkers::FAMILIES_PER_BCC) {
                $errors[] = sprintf(
                    'BCC %s has legacy dummy families (%d); compact shape blocked.',
                    $bcc->bcc_code,
                    $totalDummy
                );

                continue;
            }

            if ($compactCount !== CompactDemoMarkers::FAMILIES_PER_BCC) {
                $errors[] = sprintf(
                    'BCC %s has %d compact families; expected %d.',
                    $bcc->bcc_code,
                    $compactCount,
                    CompactDemoMarkers::FAMILIES_PER_BCC
                );
            }
        }

        $compactFamilyCount = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%')
            ->count();

        $expectedFamilies = count(CompactDemoMarkers::BCC_CODES) * CompactDemoMarkers::FAMILIES_PER_BCC;
        if ($compactFamilyCount !== $expectedFamilies) {
            $errors[] = sprintf(
                'Expected %d compact families (found %d).',
                $expectedFamilies,
                $compactFamilyCount
            );
        }

        $memberCount = FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('family', fn ($q) => $q->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%'))
            ->whereNull('deleted_at')
            ->count();

        if ($memberCount > CompactDemoMarkers::MAX_MEMBERS) {
            $errors[] = sprintf('Compact member count %d exceeds maximum %d.', $memberCount, CompactDemoMarkers::MAX_MEMBERS);
        }

        $duplicates = self::duplicateCompactIdentityCount($tenantId);
        if ($duplicates > 0) {
            $errors[] = sprintf('%d duplicate compact member identity keys detected.', $duplicates);
        }

        $demoSacramentCount = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->count();

        if ($demoSacramentCount > 0) {
            $legacySacramentRemain = $demoSacramentCount > CompactDemoMarkers::SACRAMENT_REGISTER_TARGET;

            if ($legacySacramentRemain) {
                if ($demoSacramentCount < CompactDemoMarkers::SACRAMENT_REGISTER_TARGET) {
                    $errors[] = sprintf(
                        'Sacrament demo rows %d below minimum %d.',
                        $demoSacramentCount,
                        CompactDemoMarkers::SACRAMENT_REGISTER_TARGET
                    );
                }
            } elseif ($demoSacramentCount < SacramentsDemoVerifier::MIN_DEMO_ROWS) {
                $errors[] = sprintf(
                    'Expected at least %d sacrament register demo rows (found %d).',
                    SacramentsDemoVerifier::MIN_DEMO_ROWS,
                    $demoSacramentCount
                );
            } elseif ($demoSacramentCount > CompactDemoMarkers::SACRAMENT_REGISTER_TARGET && $demoSacramentCount <= 204) {
                // Legacy pagination-oriented demo volume on the same tenant — allowed.
            } elseif ($demoSacramentCount > CompactDemoMarkers::SACRAMENT_REGISTER_TARGET) {
                $errors[] = sprintf(
                    'Sacrament demo rows %d exceed compact target %d (legacy volume).',
                    $demoSacramentCount,
                    CompactDemoMarkers::SACRAMENT_REGISTER_TARGET
                );
            }
        }

        return $errors;
    }

    /**
     * @return array<string, int|string>
     */
    public static function countTable(int $tenantId): array
    {
        $compactBccIds = self::compactBccIds($tenantId);

        return [
            'compact_bccs' => $compactBccIds->count(),
            'compact_families' => Family::query()
                ->where('tenant_id', $tenantId)
                ->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%')
                ->count(),
            'compact_members' => FamilyMember::query()
                ->where('tenant_id', $tenantId)
                ->whereHas('family', fn ($q) => $q->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%'))
                ->whereNull('deleted_at')
                ->count(),
            'sacrament_demo_rows' => Sacrament::query()
                ->where('tenant_id', $tenantId)
                ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
                ->count(),
            'mass_intention_demo_rows' => MassIntentionRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%')
                ->count(),
        ];
    }

    private static function duplicateCompactIdentityCount(int $tenantId): int
    {
        $rows = FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereHas('family', fn ($q) => $q->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%'))
            ->get(['first_name', 'last_name', 'date_of_birth']);

        $keys = [];
        $dupes = 0;
        foreach ($rows as $row) {
            $key = strtolower(trim((string) $row->first_name)).'|'.strtolower(trim((string) $row->last_name)).'|'.(string) $row->date_of_birth;
            if (isset($keys[$key])) {
                $dupes++;
            }
            $keys[$key] = true;
        }

        return $dupes;
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private static function compactBccIds(int $tenantId)
    {
        return BCC::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('bcc_code', CompactDemoMarkers::BCC_CODES)
            ->where('notes', 'like', '%'.TenantDemoMarkers::MARKER.'%')
            ->pluck('id');
    }
}
