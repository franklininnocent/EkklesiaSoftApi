<?php

namespace Modules\Sacraments\Definitions;

use Modules\Sacraments\Support\SacramentEventSubtype;
use Modules\Sacraments\Support\SacramentOrdinationType;
use Modules\Sacraments\Support\SacramentParticipantRole;
use Modules\Sacraments\Support\SacramentPlaceClassification;
use Modules\Sacraments\Support\SacramentPrivacyClass;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Backend-authoritative sacrament definitions (ADR-06 / ADR-16).
 * Angular must consume GET /sacraments/definitions — not invent parallel rules.
 */
final class SacramentDefinitionRegistry
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(array_filter([
            $this->forCanonicalCode(SacramentTypeCode::BAPTISM),
            $this->forCanonicalCode(SacramentTypeCode::MATRIMONY),
            $this->forCanonicalCode(SacramentTypeCode::CONFIRMATION),
            $this->forCanonicalCode(SacramentTypeCode::EUCHARIST),
            $this->forCanonicalCode(SacramentTypeCode::ANOINTING),
            $this->forCanonicalCode(SacramentTypeCode::RECONCILIATION),
            $this->forCanonicalCode(SacramentTypeCode::HOLY_ORDERS),
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forTypeCode(?string $code): ?array
    {
        $canonical = SacramentTypeCode::normalize($code);

        return $canonical ? $this->forCanonicalCode($canonical) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function forCanonicalCode(string $code): ?array
    {
        return match ($code) {
            SacramentTypeCode::BAPTISM => $this->baptism(),
            SacramentTypeCode::MATRIMONY => $this->matrimony(),
            SacramentTypeCode::CONFIRMATION => $this->confirmation(),
            SacramentTypeCode::EUCHARIST => $this->eucharist(),
            SacramentTypeCode::ANOINTING => $this->anointing(),
            SacramentTypeCode::RECONCILIATION => $this->reconciliation(),
            SacramentTypeCode::HOLY_ORDERS => $this->holyOrders(),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function baptism(): array
    {
        return [
            'code' => SacramentTypeCode::BAPTISM,
            'display_name' => 'Baptism',
            'category' => 'initiation',
            'workflow' => 'progressive',
            'batch_supported' => true,
            'repeatable' => false,
            'repeatability_policy' => 'warn_same_person_type_date',
            'privacy_class' => SacramentPrivacyClass::STANDARD,
            'certificate_supported' => true,
            'certificate_template_key' => 'baptism_v1',
            'review_required' => false,
            'gated' => false,
            'phase' => 'A',
            'minister_roles' => ['priest', 'deacon', 'pastor', 'other'],
            'registry_policy' => [
                'book_number' => 'recommended',
                'page_number' => 'recommended',
                'registry_entry' => 'recommended',
                'certificate_number' => 'optional',
            ],
            'participants' => [
                $this->slot(SacramentParticipantRole::RECIPIENT, 1, 1, 'required', ['member', 'person', 'external']),
                $this->slot(SacramentParticipantRole::FATHER, 0, 1, 'recommended', ['member', 'external']),
                $this->slot(SacramentParticipantRole::MOTHER, 0, 1, 'recommended', ['member', 'external']),
                $this->slot(SacramentParticipantRole::GODFATHER, 0, 1, 'recommended', ['member', 'external']),
                $this->slot(SacramentParticipantRole::GODMOTHER, 0, 1, 'recommended', ['member', 'external']),
                $this->slot(SacramentParticipantRole::MINISTER, 1, 1, 'required', ['internal_leadership', 'external']),
            ],
            'fields' => [
                'minimum' => [
                    'date_administered',
                    'place_administered',
                    'participants.recipient',
                    'participants.minister',
                    'recipient_birth_date',
                    'recipient_birth_place',
                    'recipient_gender',
                    'father_name',
                    'mother_name',
                ],
                'recommended' => [
                    'participants.father',
                    'participants.mother',
                ],
                'complete' => [
                    'book_number', 'page_number', 'registry_entry', 'certificate_number',
                    'participants.godfather', 'participants.godmother',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function matrimony(): array
    {
        return [
            'code' => SacramentTypeCode::MATRIMONY,
            'display_name' => 'Matrimony',
            'category' => 'service',
            'workflow' => 'dedicated_progressive_review',
            'batch_supported' => false,
            'repeatable' => false,
            'repeatability_policy' => 'warn_marriage_parties_date',
            'privacy_class' => SacramentPrivacyClass::STANDARD,
            'certificate_supported' => true,
            'certificate_template_key' => 'marriage_v1',
            'review_required' => true,
            'gated' => false,
            'phase' => 'A',
            'affiliation_independent_of_source' => true,
            'minister_roles' => ['priest', 'deacon', 'other'],
            'registry_policy' => [
                'book_number' => 'recommended',
                'page_number' => 'recommended',
                'registry_entry' => 'recommended',
                'certificate_number' => 'optional',
            ],
            'participants' => [
                $this->slot(SacramentParticipantRole::BRIDE, 1, 1, 'required', ['member', 'external'], true),
                $this->slot(SacramentParticipantRole::GROOM, 1, 1, 'required', ['member', 'external'], true),
                $this->slot(SacramentParticipantRole::WITNESS, 0, null, 'recommended', ['external']),
                $this->slot(SacramentParticipantRole::MINISTER, 1, 1, 'required', ['internal_leadership', 'external']),
            ],
            'fields' => [
                'minimum' => [
                    'date_administered',
                    'place_administered',
                    'participants.bride',
                    'participants.groom',
                    'participants.minister',
                    'recipient_birth_date',
                    'recipient_gender',
                ],
                'recommended' => ['participants.witness'],
                'complete' => ['book_number', 'page_number', 'registry_entry', 'certificate_number'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function confirmation(): array
    {
        return [
            'code' => SacramentTypeCode::CONFIRMATION,
            'display_name' => 'Confirmation',
            'category' => 'initiation',
            'workflow' => 'progressive',
            'batch_supported' => true,
            'repeatable' => false,
            'repeatability_policy' => 'warn_same_person_type_date',
            'privacy_class' => SacramentPrivacyClass::STANDARD,
            'certificate_supported' => true,
            'certificate_template_key' => 'confirmation_v1',
            'review_required' => false,
            'gated' => false,
            'phase' => 'B',
            'minister_roles' => ['bishop', 'priest'],
            'registry_policy' => [
                'book_number' => 'recommended',
                'page_number' => 'recommended',
                'registry_entry' => 'recommended',
                'certificate_number' => 'optional',
            ],
            'participants' => [
                $this->slot(SacramentParticipantRole::RECIPIENT, 1, 1, 'required', ['member', 'external']),
                $this->slot(SacramentParticipantRole::SPONSOR, 0, 2, 'recommended', ['member', 'external']),
                $this->slot(SacramentParticipantRole::MINISTER, 1, 1, 'required', ['internal_leadership', 'external']),
            ],
            'fields' => [
                'minimum' => ['date_administered', 'participants.recipient', 'participants.minister'],
                'recommended' => ['place_administered', 'participants.sponsor'],
                'complete' => ['book_number', 'page_number', 'registry_entry', 'certificate_number'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function eucharist(): array
    {
        return [
            'code' => SacramentTypeCode::EUCHARIST,
            'display_name' => 'Eucharist (First Holy Communion)',
            'category' => 'initiation',
            'workflow' => 'progressive',
            'batch_supported' => true,
            'repeatable' => true,
            'repeatability_policy' => 'warn_same_person_subtype',
            'privacy_class' => SacramentPrivacyClass::STANDARD,
            'certificate_supported' => true,
            'certificate_template_key' => 'eucharist_first_communion_v1',
            'review_required' => false,
            'gated' => false,
            'phase' => 'B',
            'default_event_subtype' => SacramentEventSubtype::FIRST_COMMUNION,
            'event_subtypes' => SacramentEventSubtype::forEucharist(),
            'minister_roles' => ['priest', 'other'],
            'registry_policy' => [
                'book_number' => 'recommended',
                'page_number' => 'recommended',
                'registry_entry' => 'recommended',
                'certificate_number' => 'optional',
            ],
            'participants' => [
                $this->slot(SacramentParticipantRole::RECIPIENT, 1, 1, 'required', ['member', 'person', 'external']),
                $this->slot(SacramentParticipantRole::FATHER, 0, 1, 'recommended', ['member', 'external']),
                $this->slot(SacramentParticipantRole::MOTHER, 0, 1, 'recommended', ['member', 'external']),
                $this->slot(SacramentParticipantRole::MINISTER, 1, 1, 'required', ['internal_leadership', 'external']),
            ],
            'fields' => [
                'minimum' => [
                    'date_administered',
                    'place_administered',
                    'participants.recipient',
                    'participants.minister',
                    'event_subtype',
                    'recipient_birth_date',
                    'recipient_birth_place',
                    'recipient_gender',
                    'father_name',
                    'mother_name',
                    'baptism_date',
                ],
                'recommended' => [],
                'complete' => ['book_number', 'page_number', 'registry_entry', 'certificate_number'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function anointing(): array
    {
        return [
            'code' => SacramentTypeCode::ANOINTING,
            'display_name' => 'Anointing of the Sick',
            'category' => 'healing',
            'workflow' => 'progressive',
            'batch_supported' => false,
            'repeatable' => true,
            'repeatability_policy' => 'warn_same_person_type_date',
            'privacy_class' => SacramentPrivacyClass::SENSITIVE,
            'certificate_supported' => true,
            'certificate_template_key' => 'anointing_v1',
            'review_required' => false,
            'gated' => false,
            'phase' => 'B',
            'minister_roles' => ['priest'],
            'place_classifications' => SacramentPlaceClassification::all(),
            'forbidden_fields' => [
                'diagnosis', 'medical_history', 'medication', 'disease', 'clinical_notes',
            ],
            'registry_policy' => [
                'book_number' => 'optional',
                'page_number' => 'optional',
                'registry_entry' => 'optional',
                'certificate_number' => 'optional',
            ],
            'participants' => [
                $this->slot(SacramentParticipantRole::RECIPIENT, 1, 1, 'required', ['member', 'external']),
                $this->slot(SacramentParticipantRole::MINISTER, 1, 1, 'required', ['internal_leadership', 'external']),
            ],
            'fields' => [
                'minimum' => ['date_administered', 'participants.recipient', 'participants.minister'],
                'recommended' => ['place_administered', 'place_classification'],
                'complete' => ['book_number', 'page_number', 'registry_entry'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reconciliation(): array
    {
        return [
            'code' => SacramentTypeCode::RECONCILIATION,
            'display_name' => 'Reconciliation',
            'category' => 'healing',
            'workflow' => 'simple_privacy',
            'batch_supported' => false,
            'repeatable' => true,
            'repeatability_policy' => 'warn_same_person_type_date',
            'privacy_class' => SacramentPrivacyClass::RESTRICTED,
            'certificate_supported' => false,
            'certificate_template_key' => null,
            'review_required' => false,
            'gated' => false,
            'phase' => 'C',
            'export_allowed' => false,
            'minister_roles' => ['priest'],
            'forbidden_fields' => [
                'confession_text', 'sins', 'confession_notes', 'penance_details',
            ],
            'registry_policy' => [
                'book_number' => 'optional',
                'page_number' => 'optional',
                'registry_entry' => 'optional',
                'certificate_number' => 'na',
            ],
            'participants' => [
                $this->slot(SacramentParticipantRole::RECIPIENT, 1, 1, 'required', ['member', 'external']),
                $this->slot(SacramentParticipantRole::MINISTER, 1, 1, 'required', ['internal_leadership', 'external']),
            ],
            'fields' => [
                'minimum' => ['date_administered', 'participants.recipient', 'participants.minister'],
                'recommended' => ['place_administered'],
                'complete' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function holyOrders(): array
    {
        return [
            'code' => SacramentTypeCode::HOLY_ORDERS,
            'display_name' => 'Holy Orders',
            'category' => 'service',
            'workflow' => 'dedicated_progressive_review',
            'batch_supported' => false,
            'repeatable' => false,
            'repeatability_policy' => 'warn_same_candidate_ordination',
            'privacy_class' => SacramentPrivacyClass::STANDARD,
            'certificate_supported' => true,
            'certificate_template_key' => 'holy_orders_v1',
            'review_required' => true,
            'gated' => false,
            'phase' => 'C',
            'minister_roles' => ['bishop', 'archbishop'],
            'ordination_types' => SacramentOrdinationType::all(),
            'typed_attributes_schema' => [
                'ordination_type' => ['required' => true, 'enum' => SacramentOrdinationType::all()],
                'diocese_name' => ['required' => false, 'type' => 'string', 'max' => 255],
                'place_detail' => ['required' => false, 'type' => 'string', 'max' => 255],
            ],
            'registry_policy' => [
                'book_number' => 'recommended',
                'page_number' => 'recommended',
                'registry_entry' => 'recommended',
                'certificate_number' => 'optional',
            ],
            'participants' => [
                $this->slot(SacramentParticipantRole::CANDIDATE, 1, 1, 'required', ['member', 'external']),
                $this->slot(SacramentParticipantRole::MINISTER, 1, 1, 'required', ['internal_leadership', 'external']),
                $this->slot(SacramentParticipantRole::CO_CONSECRATOR, 0, null, 'optional', ['internal_leadership', 'external']),
                $this->slot(SacramentParticipantRole::WITNESS, 0, null, 'optional', ['member', 'external']),
            ],
            'fields' => [
                'minimum' => [
                    'date_administered',
                    'participants.candidate',
                    'participants.minister',
                    'typed_attributes.ordination_type',
                ],
                'recommended' => ['place_administered', 'typed_attributes.diocese_name'],
                'complete' => ['book_number', 'page_number', 'registry_entry', 'certificate_number'],
            ],
        ];
    }

    /**
     * @param  list<string>  $sources
     * @return array<string, mixed>
     */
    private function slot(
        string $role,
        int $min,
        ?int $max,
        string $classification,
        array $sources,
        bool $affiliationRequiredWhenOther = false
    ): array {
        return [
            'role' => $role,
            'min' => $min,
            'max' => $max,
            'classification' => $classification,
            'allowed_sources' => $sources,
            'affiliation_required_when_other' => $affiliationRequiredWhenOther,
        ];
    }
}
