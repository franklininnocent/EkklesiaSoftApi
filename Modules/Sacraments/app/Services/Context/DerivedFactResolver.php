<?php

namespace Modules\Sacraments\Services\Context;

use Modules\Sacraments\Support\BaptismalStatus;
use Modules\Sacraments\Support\SacramentFieldState;
use Modules\Sacraments\Support\SacramentRecordStatus;
use Modules\Sacraments\Support\SacramentSourceType;

final class DerivedFactResolver
{
    public function __construct(
        private readonly ProvenanceBuilder $provenance,
    ) {}

    /**
     * @param  array<string, mixed>  $baptismEvidence
     * @return array<string, mixed>
     */
    public function resolveBaptismalStatus(array $baptismEvidence): array
    {
        $status = $baptismEvidence['record_status'] ?? SacramentRecordStatus::NOT_SEARCHED;

        if ($status === SacramentRecordStatus::NOT_FOUND || $status === SacramentRecordStatus::NOT_SEARCHED) {
            return [
                'value' => null,
                'field_state' => 'MISSING',
                'provenance' => [
                    'source_type' => null,
                    'record_status' => $status,
                    'verification_status' => 'UNKNOWN',
                ],
            ];
        }

        if ($status === SacramentRecordStatus::MULTIPLE_CANDIDATES) {
            return [
                'value' => null,
                'field_state' => 'MULTIPLE_CANDIDATES',
                'provenance' => [
                    'source_type' => SacramentSourceType::BAPTISM_RECORD,
                    'record_status' => $status,
                    'verification_status' => 'UNKNOWN',
                ],
            ];
        }

        if ($status === SacramentRecordStatus::FOUND && isset($baptismEvidence['evidence'])) {
            $tier = $baptismEvidence['evidence_tier'] ?? SacramentSourceType::BAPTISM_RECORD;
            $evidence = $baptismEvidence['evidence'];
            $sacramentId = $evidence['sacrament_id'] ?? null;

            if ($tier === SacramentSourceType::BAPTISM_RECORD || $sacramentId !== null) {
                return $this->provenance->derived(
                    BaptismalStatus::BAPTIZED_CATHOLIC,
                    SacramentSourceType::BAPTISM_RECORD,
                    $sacramentId !== null ? (string) $sacramentId : null,
                );
            }

            if ($tier === SacramentSourceType::MEMBER_PROFILE) {
                $locationType = $evidence['baptism_location_type'] ?? null;
                if ($locationType === 'home_parish') {
                    return $this->provenance->field(
                        BaptismalStatus::BAPTIZED_CATHOLIC,
                        SacramentFieldState::DERIVED,
                        SacramentSourceType::MEMBER_PROFILE,
                        isset($evidence['member_id']) ? (string) $evidence['member_id'] : null,
                        'Member Profile Summary',
                        'INFERRED',
                        'NOT_INDEPENDENTLY_VERIFIED',
                        SacramentRecordStatus::FOUND,
                        'home_parish_baptism_profile',
                    );
                }
            }

            return $this->provenance->missing();
        }

        return $this->provenance->missing();
    }
}
