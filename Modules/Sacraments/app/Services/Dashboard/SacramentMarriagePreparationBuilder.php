<?php

namespace Modules\Sacraments\Services\Dashboard;

use Carbon\Carbon;
use Modules\Family\Models\FamilyMember;
use Modules\Sacraments\Models\MarriagePreparationCase;
use Modules\Sacraments\Services\Context\MemberProfileSacramentEvidenceProvider;
use Modules\Sacraments\Support\SacramentRecordStatus;

class SacramentMarriagePreparationBuilder
{
    public function __construct(
        private readonly MemberProfileSacramentEvidenceProvider $evidenceProvider
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId, Carbon $periodEnd, ?string $bccId = null): array
    {
        $pastoralYear = (int) $periodEnd->year;
        $windowStart = Carbon::create($pastoralYear, 1, 1)->startOfDay();
        $windowEnd = Carbon::create($pastoralYear, 12, 31)->endOfDay();

        $query = MarriagePreparationCase::query()
            ->forTenant($tenantId)
            ->active()
            ->whereBetween('inquiry_started_at', [$windowStart, $windowEnd])
            ->with([
                'brideFamilyMember:id,baptism_date,confirmation_date,baptism_place,baptism_church_name,baptism_church_address,baptism_location_type,confirmation_place',
                'groomFamilyMember:id,baptism_date,confirmation_date,baptism_place,baptism_church_name,baptism_church_address,baptism_location_type,confirmation_place',
            ]);

        if ($bccId !== null && $bccId !== '') {
            $query->where('bcc_id', $bccId);
        }

        $cases = $query->get();
        $total = $cases->count();

        $inquiriesStarted = $total;
        $canonicalDocsVerified = 0;
        $preCanaCompleted = 0;
        $bannsPublished = 0;

        foreach ($cases as $case) {
            if ($case->pre_cana_completed_at !== null) {
                $preCanaCompleted++;
            }

            if ($case->banns_published_at !== null) {
                $bannsPublished++;
            }

            if ($this->isCanonicallyDocsVerified($case)) {
                $canonicalDocsVerified++;
            }
        }

        return [
            'pastoral_year' => $pastoralYear,
            'pastoral_year_label' => $pastoralYear.' pastoral year',
            'total_active_applications' => $total,
            'metrics' => [
                'inquiries_started' => $this->metricRing($inquiriesStarted, $total),
                'canonical_docs_verified' => $this->metricRing($canonicalDocsVerified, $total),
                'pre_cana_completed' => $this->metricRing($preCanaCompleted, $total),
                'banns_published' => $this->metricRing($bannsPublished, $total),
            ],
        ];
    }

    private function isCanonicallyDocsVerified(MarriagePreparationCase $case): bool
    {
        if ($case->canonical_docs_verified_at !== null) {
            return true;
        }

        if ($case->bride_family_member_id === null || $case->groom_family_member_id === null) {
            return false;
        }

        $bride = $case->brideFamilyMember;
        $groom = $case->groomFamilyMember;

        if ($bride === null || $groom === null) {
            return false;
        }

        return $this->memberDocsVerified($bride) && $this->memberDocsVerified($groom);
    }

    private function memberDocsVerified(FamilyMember $member): bool
    {
        $parish = ['data' => ['name' => null]];
        $baptism = $this->evidenceProvider->resolveBaptism($member, $parish);
        $confirmation = $this->evidenceProvider->resolveConfirmation($member, $parish);

        return ($baptism['record_status'] ?? null) === SacramentRecordStatus::FOUND
            && ($confirmation['record_status'] ?? null) === SacramentRecordStatus::FOUND;
    }

    /**
     * @return array{count: int, pct: int}
     */
    private function metricRing(int $count, int $total): array
    {
        return [
            'count' => $count,
            'pct' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
        ];
    }
}
