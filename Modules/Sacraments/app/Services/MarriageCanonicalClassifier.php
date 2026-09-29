<?php

namespace Modules\Sacraments\Services;

use Modules\Sacraments\Support\BaptismalStatus;
use Modules\Sacraments\Support\MarriageCanonicalClassification;
use Modules\Sacraments\Support\SacramentParticipantRole;

/**
 * Derives Can. 1124 / 1086 classification from the two parties' baptismal status.
 */
final class MarriageCanonicalClassifier
{
    /**
     * @param  list<array<string, mixed>>  $participants
     */
    public function deriveFromParticipants(array $participants): ?string
    {
        $bride = null;
        $groom = null;
        foreach ($participants as $row) {
            $role = (string) ($row['role'] ?? '');
            $status = $row['baptismal_status'] ?? null;
            if ($role === SacramentParticipantRole::BRIDE) {
                $bride = is_string($status) ? $status : null;
            }
            if ($role === SacramentParticipantRole::GROOM) {
                $groom = is_string($status) ? $status : null;
            }
        }

        return $this->derive($bride, $groom);
    }

    public function derive(?string $brideStatus, ?string $groomStatus): ?string
    {
        if (! BaptismalStatus::isValid($brideStatus) || ! BaptismalStatus::isValid($groomStatus)) {
            return null;
        }

        if ($brideStatus === BaptismalStatus::BAPTIZED_CATHOLIC
            && $groomStatus === BaptismalStatus::BAPTIZED_CATHOLIC
        ) {
            return MarriageCanonicalClassification::BOTH_CATHOLIC;
        }

        $statuses = [$brideStatus, $groomStatus];
        $hasCatholic = in_array(BaptismalStatus::BAPTIZED_CATHOLIC, $statuses, true);
        $hasBaptizedNonCatholic = in_array(BaptismalStatus::BAPTIZED_NON_CATHOLIC, $statuses, true);
        $hasUnbaptized = in_array(BaptismalStatus::UNBAPTIZED, $statuses, true);

        if ($hasCatholic && $hasBaptizedNonCatholic) {
            return MarriageCanonicalClassification::MIXED_MARRIAGE;
        }

        if ($hasCatholic && $hasUnbaptized) {
            return MarriageCanonicalClassification::DISPARITY_OF_CULT;
        }

        return MarriageCanonicalClassification::OTHER;
    }
}
