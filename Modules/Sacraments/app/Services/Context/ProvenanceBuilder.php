<?php

namespace Modules\Sacraments\Services\Context;

use Modules\Sacraments\Support\SacramentFieldState;
use Modules\Sacraments\Support\SacramentRecordStatus;
use Modules\Sacraments\Support\SacramentSourceType;

final class ProvenanceBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function field(
        mixed $value,
        string $fieldState,
        string $sourceType,
        ?string $sourceId = null,
        ?string $sourceLabel = null,
        string $evidenceType = 'EXPLICIT',
        string $verificationStatus = 'VERIFIED',
        ?string $recordStatus = SacramentRecordStatus::FOUND,
        ?string $derivedFrom = null,
    ): array {
        $provenance = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_label' => $sourceLabel ?? $this->defaultLabel($sourceType),
            'evidence_type' => $evidenceType,
            'verification_status' => $verificationStatus,
            'record_status' => $recordStatus,
        ];

        if ($derivedFrom !== null) {
            $provenance['derived_from'] = $derivedFrom;
        }

        return [
            'value' => $value,
            'field_state' => $fieldState,
            'provenance' => $provenance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function canonical(mixed $value, string $sourceType, ?string $sourceId = null): array
    {
        return $this->field(
            $value,
            SacramentFieldState::CANONICAL,
            $sourceType,
            $sourceId,
            null,
            'EXPLICIT',
            'VERIFIED',
            SacramentRecordStatus::FOUND,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function verifiedEvidence(mixed $value, string $sourceType, ?string $sourceId, string $sourceLabel): array
    {
        return $this->field(
            $value,
            SacramentFieldState::READ_ONLY_VERIFIED,
            $sourceType,
            $sourceId,
            $sourceLabel,
            'EXPLICIT',
            'VERIFIED',
            SacramentRecordStatus::FOUND,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function derived(mixed $value, string $derivedFrom, ?string $sourceId = null): array
    {
        return $this->field(
            $value,
            SacramentFieldState::DERIVED,
            SacramentSourceType::SYSTEM_DERIVED,
            $sourceId,
            'Derived',
            'INFERRED',
            'NOT_INDEPENDENTLY_VERIFIED',
            SacramentRecordStatus::FOUND,
            $derivedFrom,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function missing(): array
    {
        return [
            'value' => null,
            'field_state' => SacramentFieldState::MISSING,
            'provenance' => [
                'source_type' => null,
                'source_id' => null,
                'source_label' => null,
                'evidence_type' => null,
                'verification_status' => 'UNKNOWN',
                'record_status' => SacramentRecordStatus::NOT_FOUND,
            ],
        ];
    }

    private function defaultLabel(string $sourceType): string
    {
        return match ($sourceType) {
            SacramentSourceType::MEMBER_PROFILE => 'Member Profile',
            SacramentSourceType::PERSON_PROFILE => 'Person Profile',
            SacramentSourceType::FAMILY_RECORD => 'Family Record',
            SacramentSourceType::BAPTISM_RECORD => 'Baptism Record',
            SacramentSourceType::CONFIRMATION_RECORD => 'Confirmation Record',
            SacramentSourceType::MARRIAGE_RECORD => 'Marriage Record',
            SacramentSourceType::PARISH_RECORD => 'Parish Record',
            SacramentSourceType::SYSTEM_DERIVED => 'Derived',
            default => 'Unknown',
        };
    }
}
