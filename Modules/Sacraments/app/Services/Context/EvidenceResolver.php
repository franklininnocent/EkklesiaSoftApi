<?php

namespace Modules\Sacraments\Services\Context;

use Illuminate\Support\Collection;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Support\SacramentRecordStatus;
use Modules\Sacraments\Support\SacramentSourceType;

final class EvidenceResolver
{
    public function __construct(
        private readonly ProvenanceBuilder $provenance,
    ) {}

    /**
     * @param  Collection<int, Sacrament>  $records
     * @return array<string, mixed>
     */
    public function resolveBaptism(Collection $records): array
    {
        return $this->resolveSacramentEvidence($records, SacramentSourceType::BAPTISM_RECORD, 'Baptism Record');
    }

    /**
     * @param  Collection<int, Sacrament>  $records
     * @return array<string, mixed>
     */
    public function resolveConfirmation(Collection $records): array
    {
        return $this->resolveSacramentEvidence($records, SacramentSourceType::CONFIRMATION_RECORD, 'Confirmation Record');
    }

    /**
     * @param  Collection<int, Sacrament>  $records
     * @return array<string, mixed>
     */
    public function resolveMarriageHistory(Collection $records): array
    {
        if ($records->isEmpty()) {
            return [
                'record_status' => SacramentRecordStatus::NOT_FOUND,
                'candidates' => [],
                'records' => [],
            ];
        }

        $items = $records->map(fn (Sacrament $s) => $this->mapSacramentSummary($s, SacramentSourceType::MARRIAGE_RECORD, 'Marriage Record'))->all();

        return [
            'record_status' => SacramentRecordStatus::FOUND,
            'candidates' => [],
            'records' => $items,
        ];
    }

    /**
     * Merge parish register evidence with member profile summary evidence.
     *
     * @param  array<string, mixed>  $register
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function mergeRegisterAndProfile(array $register, array $profile, string $registerEvidenceTier): array
    {
        $registerStatus = $register['record_status'] ?? SacramentRecordStatus::NOT_FOUND;

        if ($registerStatus === SacramentRecordStatus::MULTIPLE_CANDIDATES) {
            return array_merge($register, [
                'register_record_status' => SacramentRecordStatus::MULTIPLE_CANDIDATES,
                'evidence_tier' => $registerEvidenceTier,
            ]);
        }

        if ($registerStatus === SacramentRecordStatus::FOUND) {
            $merged = $register;
            if (isset($merged['evidence'], $profile['evidence']) && is_array($merged['evidence']) && is_array($profile['evidence'])) {
                $merged['evidence'] = $this->fillEvidenceGaps($merged['evidence'], $profile['evidence']);
            }

            return array_merge($merged, [
                'register_record_status' => SacramentRecordStatus::FOUND,
                'evidence_tier' => $registerEvidenceTier,
            ]);
        }

        $profileStatus = $profile['record_status'] ?? SacramentRecordStatus::NOT_FOUND;
        if ($profileStatus === SacramentRecordStatus::FOUND) {
            return array_merge($profile, [
                'register_record_status' => SacramentRecordStatus::NOT_FOUND,
                'evidence_tier' => SacramentSourceType::MEMBER_PROFILE,
            ]);
        }

        return array_merge($register, [
            'register_record_status' => SacramentRecordStatus::NOT_FOUND,
            'evidence_tier' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function fillEvidenceGaps(array $primary, array $fallback): array
    {
        foreach (['date', 'place', 'parish'] as $key) {
            $primaryValue = $this->evidenceFieldValue($primary[$key] ?? null);
            if ($primaryValue !== null && $primaryValue !== '') {
                continue;
            }
            if (isset($fallback[$key])) {
                $primary[$key] = $fallback[$key];
            }
        }

        return $primary;
    }

    private function evidenceFieldValue(mixed $field): mixed
    {
        if (! is_array($field)) {
            return $field;
        }

        return $field['value'] ?? null;
    }

    /**
     * @param  Collection<int, Sacrament>  $records
     * @return array<string, mixed>
     */
    private function resolveSacramentEvidence(Collection $records, string $sourceType, string $sourceLabel): array
    {
        if ($records->isEmpty()) {
            return [
                'record_status' => SacramentRecordStatus::NOT_FOUND,
                'candidates' => [],
                'evidence' => null,
            ];
        }

        if ($records->count() > 1) {
            return [
                'record_status' => SacramentRecordStatus::MULTIPLE_CANDIDATES,
                'candidates' => $records->map(fn (Sacrament $s) => $this->mapSacramentSummary($s, $sourceType, $sourceLabel))->all(),
                'evidence' => null,
            ];
        }

        /** @var Sacrament $sacrament */
        $sacrament = $records->first();

        return [
            'record_status' => SacramentRecordStatus::FOUND,
            'candidates' => [],
            'evidence' => $this->mapSacramentSummary($sacrament, $sourceType, $sourceLabel),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSacramentSummary(Sacrament $sacrament, string $sourceType, string $sourceLabel): array
    {
        $date = $sacrament->date_administered?->format('Y-m-d')
            ?? $sacrament->baptism_date?->format('Y-m-d');

        return [
            'sacrament_id' => $sacrament->id,
            'date' => $this->provenance->verifiedEvidence($date, $sourceType, (string) $sacrament->id, $sourceLabel),
            'place' => $this->provenance->verifiedEvidence($sacrament->place_administered, $sourceType, (string) $sacrament->id, $sourceLabel),
            'parish' => $this->provenance->verifiedEvidence($sacrament->place_administered, $sourceType, (string) $sacrament->id, $sourceLabel),
            'register' => [
                'book_number' => $sacrament->book_number,
                'page_number' => $sacrament->page_number,
                'registry_entry' => $sacrament->registry_entry,
                'certificate_number' => $sacrament->certificate_number,
            ],
            'recipient_name' => $sacrament->recipient_name,
            'recipient_birth_date' => $sacrament->recipient_birth_date?->format('Y-m-d'),
            'father_name' => $sacrament->father_name,
            'mother_name' => $sacrament->mother_name,
            'recipient_gender' => $sacrament->recipient_gender,
        ];
    }
}
