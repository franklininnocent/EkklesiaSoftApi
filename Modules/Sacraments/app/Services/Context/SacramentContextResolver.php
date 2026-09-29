<?php

namespace Modules\Sacraments\Services\Context;

use Modules\Sacraments\Support\SacramentRecordStatus;
use Modules\Sacraments\Support\SacramentSourceType;
use Modules\Sacraments\Support\SacramentTypeCode;

final class SacramentContextResolver
{
    public function __construct(
        private readonly PersonContextProvider $personContext,
        private readonly FamilyContextProvider $familyContext,
        private readonly SacramentHistoryProvider $historyProvider,
        private readonly MemberProfileSacramentEvidenceProvider $profileEvidence,
        private readonly EvidenceResolver $evidenceResolver,
        private readonly DerivedFactResolver $derivedFactResolver,
        private readonly ConflictDetector $conflictDetector,
        private readonly FieldRequirementResolver $fieldRequirementResolver,
    ) {}

    /**
     * @param  array{
     *     family_member_id?: ?string,
     *     person_id?: ?string,
     *     workflow: string,
     *     participant_role?: ?string,
     *     sacrament_id?: ?int
     * }  $params
     * @return array<string, mixed>
     */
    public function resolve(int|string $tenantId, array $params): array
    {
        $familyMemberId = $params['family_member_id'] ?? null;
        $personId = $params['person_id'] ?? null;
        $workflow = SacramentTypeCode::normalize($params['workflow'] ?? '') ?? (string) ($params['workflow'] ?? '');

        $personBundle = $this->personContext->resolve($tenantId, $familyMemberId, $personId);
        $person = $personBundle['person'];
        $member = $personBundle['family_member'];
        $resolvedPersonId = $person?->id ?? $member?->person_id;
        $resolvedMemberId = $member?->id ?? $familyMemberId;

        $family = $this->familyContext->resolve($member, $tenantId);
        $parish = $this->familyContext->resolveParish($tenantId);

        $history = $this->historyProvider->load($tenantId, $resolvedPersonId, $resolvedMemberId);

        $registerBaptism = $this->evidenceResolver->resolveBaptism($history['baptism']);
        $profileBaptism = $this->profileEvidence->resolveBaptism($member, $parish);
        $baptism = $this->evidenceResolver->mergeRegisterAndProfile(
            $registerBaptism,
            $profileBaptism,
            SacramentSourceType::BAPTISM_RECORD,
        );

        $registerConfirmation = $this->evidenceResolver->resolveConfirmation($history['confirmation']);
        $profileConfirmation = $this->profileEvidence->resolveConfirmation($member, $parish);
        $confirmation = $this->evidenceResolver->mergeRegisterAndProfile(
            $registerConfirmation,
            $profileConfirmation,
            SacramentSourceType::CONFIRMATION_RECORD,
        );

        $marriageHistory = $this->evidenceResolver->resolveMarriageHistory($history['marriage_history']);

        $derived = [
            'baptismal_status' => $this->derivedFactResolver->resolveBaptismalStatus($baptism),
        ];

        $canonicalIdentity = $personBundle['canonical_identity'];
        $conflicts = $this->conflictDetector->detect($canonicalIdentity, $baptism);
        $conflicts = array_merge(
            $conflicts,
            $this->conflictDetector->detectRegisterProfileDateConflicts($registerBaptism, $profileBaptism),
        );

        $context = [
            'subject' => [
                'person_id' => $resolvedPersonId,
                'family_member_id' => $resolvedMemberId,
                'display_name' => $canonicalIdentity['name']['value'] ?? null,
                'participant_role' => $params['participant_role'] ?? null,
            ],
            'canonical_identity' => $canonicalIdentity,
            'family' => $family,
            'parish' => $parish,
            'sacraments' => [
                'baptism' => $baptism,
                'confirmation' => $confirmation,
                'marriage_history' => $marriageHistory,
            ],
            'derived' => $derived,
            'conflicts' => $conflicts,
            'has_blocking_conflicts' => $this->conflictDetector->hasBlockingConflicts($conflicts),
            'found_summary' => $this->buildFoundSummary($member, $family, $baptism, $confirmation),
            'record_status' => [
                'baptism' => $baptism['record_status'] ?? SacramentRecordStatus::NOT_SEARCHED,
                'confirmation' => $confirmation['record_status'] ?? SacramentRecordStatus::NOT_SEARCHED,
                'marriage_history' => $marriageHistory['record_status'] ?? SacramentRecordStatus::NOT_SEARCHED,
                'baptism_register' => $baptism['register_record_status'] ?? SacramentRecordStatus::NOT_SEARCHED,
                'confirmation_register' => $confirmation['register_record_status'] ?? SacramentRecordStatus::NOT_SEARCHED,
            ],
        ];

        $requirements = $this->fieldRequirementResolver->resolve($workflow, $context);
        $context['fields'] = $requirements['fields'];
        $context['missing'] = $requirements['missing'];

        return $context;
    }

    /**
     * @return list<string>
     */
    private function buildFoundSummary($member, array $family, array $baptism, array $confirmation): array
    {
        $summary = [];

        if ($member !== null) {
            $summary[] = 'member';
        }
        if (($family['record_status'] ?? '') === SacramentRecordStatus::FOUND) {
            $summary[] = 'family';
        }
        if (($baptism['record_status'] ?? '') === SacramentRecordStatus::FOUND) {
            $summary[] = 'baptism';
        }
        if (($confirmation['record_status'] ?? '') === SacramentRecordStatus::FOUND) {
            $summary[] = 'confirmation';
        }

        return $summary;
    }
}
