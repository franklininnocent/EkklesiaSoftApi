<?php

namespace Modules\Sacraments\Services\Migration;

use Modules\Family\Models\FamilyMember;
use Modules\Sacraments\Support\SacramentMigrationResolutionStatus;

/**
 * Exact name + DOB member matching only (ADR-11). Never name-only auto-link.
 */
class SacramentNameMatchService
{
    /**
     * @return array{confidence:string, member:?FamilyMember, candidates:int}
     */
    public function findExactUnique(int $tenantId, string $name, ?string $dob): array
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '' || $dob === null || trim($dob) === '') {
            return [
                'confidence' => SacramentMigrationResolutionStatus::CONFIDENCE_NONE,
                'member' => null,
                'candidates' => 0,
            ];
        }

        $candidates = FamilyMember::query()
            ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereDate('date_of_birth', $dob)
            ->get()
            ->filter(fn (FamilyMember $m) => $this->normalizeName($m->full_name_display ?? $this->composeName($m)) === $normalized)
            ->values();

        $count = $candidates->count();
        if ($count === 1) {
            return [
                'confidence' => SacramentMigrationResolutionStatus::CONFIDENCE_EXACT,
                'member' => $candidates->first(),
                'candidates' => 1,
            ];
        }

        if ($count > 1) {
            return [
                'confidence' => SacramentMigrationResolutionStatus::CONFIDENCE_AMBIGUOUS,
                'member' => null,
                'candidates' => $count,
            ];
        }

        return [
            'confidence' => SacramentMigrationResolutionStatus::CONFIDENCE_NONE,
            'member' => null,
            'candidates' => 0,
        ];
    }

    public function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name) ?? $name;

        return trim($name);
    }

    private function composeName(FamilyMember $member): string
    {
        return trim(implode(' ', array_filter([
            $member->first_name,
            $member->middle_name,
            $member->last_name,
        ])));
    }
}
