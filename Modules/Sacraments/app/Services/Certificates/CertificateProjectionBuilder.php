<?php

namespace Modules\Sacraments\Services\Certificates;

use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Builds certificate projection_json from sacrament + participant snapshots only (ADR-02 / ADR-09).
 * Never reads live FamilyMember / ChurchLeadership for issuance content.
 */
class CertificateProjectionBuilder
{
    public function __construct(
        protected SacramentDefinitionRegistry $definitions
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Sacrament $sacrament, string $language = 'en', string $locale = 'en_US'): array
    {
        $sacrament->loadMissing(['sacramentType', 'participants']);

        $type = $sacrament->sacramentType;
        if (! $type) {
            throw new SacramentBusinessRuleException(
                'invalid_sacrament_type',
                'Sacrament type is required for certificate projection.'
            );
        }

        $definition = $this->definitions->forTypeCode($type->code);
        if ($definition === null || empty($definition['certificate_supported'])) {
            throw new SacramentBusinessRuleException(
                'certificate_not_supported',
                'Certificates are not supported for this sacrament type.'
            );
        }

        $participants = $sacrament->participants
            ->sortBy('sort_order')
            ->values()
            ->map(function ($p) {
                $snapshot = is_array($p->snapshot_json) ? $p->snapshot_json : [];

                return [
                    'role' => $p->role,
                    'source' => $p->source,
                    'sort_order' => (int) $p->sort_order,
                    'affiliation_type' => $p->affiliation_type,
                    'affiliation_parish_name' => $p->affiliation_parish_name,
                    'affiliation_diocese_name' => $p->affiliation_diocese_name,
                    'snapshot' => $snapshot,
                    'display_name' => $snapshot['full_name']
                        ?? $p->external_full_name
                        ?? null,
                ];
            })
            ->all();

        $code = SacramentTypeCode::normalize((string) $type->code) ?? strtoupper((string) $type->code);

        return [
            'schema_version' => 1,
            'language' => $language,
            'locale' => $locale,
            'sacrament' => [
                'id' => $sacrament->id,
                'type_code' => $code,
                'type_name' => $type->name,
                'date_administered' => optional($sacrament->date_administered)?->format('Y-m-d')
                    ?? (string) $sacrament->date_administered,
                'place_administered' => $sacrament->place_administered,
                'place_classification' => $sacrament->place_classification,
                'event_subtype' => $sacrament->event_subtype,
                'typed_attributes' => is_array($sacrament->typed_attributes) ? $sacrament->typed_attributes : [],
                'certificate_number' => $sacrament->certificate_number,
                'book_number' => $sacrament->book_number,
                'page_number' => $sacrament->page_number,
                'registry_entry' => $sacrament->registry_entry,
                'status' => $sacrament->status,
                // Denorm names kept as fallback when participants empty (legacy records).
                'recipient_name' => $sacrament->recipient_name,
                'minister_name' => $sacrament->minister_name,
                'minister_title' => $sacrament->minister_title,
                'father_name' => $sacrament->father_name,
                'mother_name' => $sacrament->mother_name,
                'godparent1_name' => $sacrament->godparent1_name,
                'godparent2_name' => $sacrament->godparent2_name,
                'marriage_bride_full_name' => $sacrament->marriage_bride_full_name,
                'marriage_groom_full_name' => $sacrament->marriage_groom_full_name,
                'marriage_bride_diocese_name' => $sacrament->marriage_bride_diocese_name ?? null,
                'marriage_groom_diocese_name' => $sacrament->marriage_groom_diocese_name ?? null,
            ],
            'participants' => $participants,
            'built_at' => now()->toIso8601String(),
        ];
    }
}
