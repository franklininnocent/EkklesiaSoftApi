<?php

namespace Modules\Sacraments\Services\Context;

use Modules\Sacraments\Support\SacramentConflictSeverity;
use Modules\Sacraments\Support\SacramentRecordStatus;
use Modules\Sacraments\Support\SacramentSourceType;

final class ConflictDetector
{
    private const IDENTITY_FIELDS = ['name', 'date_of_birth', 'father_name', 'mother_name', 'gender'];

    /**
     * @param  array<string, array<string, mixed>>  $canonicalIdentity
     * @param  array<string, mixed>  $baptismEvidence
     * @return list<array<string, mixed>>
     */
    public function detect(array $canonicalIdentity, array $baptismEvidence): array
    {
        $conflicts = [];

        if (($baptismEvidence['record_status'] ?? '') !== SacramentRecordStatus::FOUND) {
            return $conflicts;
        }

        $evidence = $baptismEvidence['evidence'] ?? null;
        if (! is_array($evidence)) {
            return $conflicts;
        }

        $evidenceSourceType = ($baptismEvidence['evidence_tier'] ?? '') === SacramentSourceType::MEMBER_PROFILE
            ? SacramentSourceType::MEMBER_PROFILE
            : SacramentSourceType::BAPTISM_RECORD;
        $evidenceSourceLabel = $evidenceSourceType === SacramentSourceType::MEMBER_PROFILE
            ? 'Member Profile Summary'
            : 'Baptism Record';

        $mappings = [
            'name' => ['evidence_key' => 'recipient_name', 'label' => 'Name'],
            'date_of_birth' => ['evidence_key' => 'recipient_birth_date', 'label' => 'Date of Birth'],
            'father_name' => ['evidence_key' => 'father_name', 'label' => "Father's Name"],
            'mother_name' => ['evidence_key' => 'mother_name', 'label' => "Mother's Name"],
            'gender' => ['evidence_key' => 'recipient_gender', 'label' => 'Gender'],
        ];

        foreach ($mappings as $field => $config) {
            $canonicalValue = $canonicalIdentity[$field]['value'] ?? null;
            $evidenceValue = $evidence[$config['evidence_key']] ?? null;
            if (is_array($evidenceValue)) {
                $evidenceValue = $evidenceValue['value'] ?? null;
            }

            if ($canonicalValue === null || $evidenceValue === null || $evidenceValue === '') {
                continue;
            }

            if ($this->valuesEqual($field, $canonicalValue, $evidenceValue)) {
                continue;
            }

            $severity = $this->severityForField($field, $canonicalValue, $evidenceValue);

            $conflicts[] = [
                'field' => $field,
                'label' => $config['label'],
                'severity' => $severity,
                'blocks_save' => SacramentConflictSeverity::blocksSave($severity),
                'candidates' => [
                    [
                        'value' => $canonicalValue,
                        'source_type' => $canonicalIdentity[$field]['provenance']['source_type'] ?? SacramentSourceType::PERSON_PROFILE,
                        'source_label' => $canonicalIdentity[$field]['provenance']['source_label'] ?? 'Member Profile',
                    ],
                    [
                        'value' => $evidenceValue,
                        'source_type' => $evidenceSourceType,
                        'source_label' => $evidenceSourceLabel,
                        'source_id' => $evidence['sacrament_id'] ?? $evidence['member_id'] ?? null,
                    ],
                ],
            ];
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $registerBaptism
     * @param  array<string, mixed>  $profileBaptism
     * @return list<array<string, mixed>>
     */
    public function detectRegisterProfileDateConflicts(array $registerBaptism, array $profileBaptism): array
    {
        if (($registerBaptism['record_status'] ?? '') !== SacramentRecordStatus::FOUND) {
            return [];
        }
        if (($profileBaptism['record_status'] ?? '') !== SacramentRecordStatus::FOUND) {
            return [];
        }

        $registerDate = $this->extractEvidenceDate($registerBaptism['evidence'] ?? null);
        $profileDate = $this->extractEvidenceDate($profileBaptism['evidence'] ?? null);

        if ($registerDate === null || $profileDate === null || $registerDate === $profileDate) {
            return [];
        }

        return [[
            'field' => 'baptism_date',
            'label' => 'Baptism Date',
            'severity' => SacramentConflictSeverity::WARNING,
            'blocks_save' => false,
            'candidates' => [
                [
                    'value' => $registerDate,
                    'source_type' => SacramentSourceType::BAPTISM_RECORD,
                    'source_label' => 'Baptism Record',
                    'source_id' => $registerBaptism['evidence']['sacrament_id'] ?? null,
                ],
                [
                    'value' => $profileDate,
                    'source_type' => SacramentSourceType::MEMBER_PROFILE,
                    'source_label' => 'Member Profile Summary',
                    'source_id' => $profileBaptism['evidence']['member_id'] ?? null,
                ],
            ],
        ]];
    }

    /**
     * @param  array<string, mixed>|null  $evidence
     */
    private function extractEvidenceDate(?array $evidence): ?string
    {
        if ($evidence === null) {
            return null;
        }

        $date = $evidence['date'] ?? null;
        if (is_array($date)) {
            $date = $date['value'] ?? null;
        }

        if ($date === null || $date === '') {
            return null;
        }

        return substr((string) $date, 0, 10);
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     */
    public function hasBlockingConflicts(array $conflicts): bool
    {
        foreach ($conflicts as $conflict) {
            if (! empty($conflict['blocks_save'])) {
                return true;
            }
        }

        return false;
    }

    private function valuesEqual(string $field, mixed $a, mixed $b): bool
    {
        if ($field === 'name') {
            return $this->normalizeName((string) $a) === $this->normalizeName((string) $b);
        }

        if ($field === 'date_of_birth') {
            return $this->normalizeDate((string) $a) === $this->normalizeDate((string) $b);
        }

        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    private function severityForField(string $field, mixed $canonical, mixed $evidence): string
    {
        if ($field === 'name') {
            $c = $this->normalizeName((string) $canonical);
            $e = $this->normalizeName((string) $evidence);
            if ($c === $e || levenshtein($c, $e) <= 2) {
                return SacramentConflictSeverity::WARNING;
            }

            return SacramentConflictSeverity::REVIEW_REQUIRED;
        }

        if ($field === 'date_of_birth') {
            return SacramentConflictSeverity::REVIEW_REQUIRED;
        }

        if ($field === 'gender') {
            return SacramentConflictSeverity::REVIEW_REQUIRED;
        }

        return SacramentConflictSeverity::WARNING;
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }

    private function normalizeDate(string $value): string
    {
        return substr(trim($value), 0, 10);
    }
}
