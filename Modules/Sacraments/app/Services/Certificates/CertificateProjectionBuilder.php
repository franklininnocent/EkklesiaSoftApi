<?php

namespace Modules\Sacraments\Services\Certificates;

use Modules\Sacraments\Certificates\CertificateThemeCatalog;
use Modules\Sacraments\Certificates\CertificateViewAssembler;
use Modules\Sacraments\Certificates\DenominationMapper;
use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Support\SacramentTypeCode;
use Modules\Tenants\Models\Tenant;

/**
 * Builds certificate projection_json from sacrament + participant snapshots only (ADR-02 / ADR-09).
 * Never reads live FamilyMember / ChurchLeadership for issuance content.
 * Schema v2 freezes church identity and render metadata at issue time.
 */
class CertificateProjectionBuilder
{
    public function __construct(
        protected SacramentDefinitionRegistry $definitions,
        protected CertificateViewAssembler $views
    ) {}

    /**
     * @param  array{paper?:string, theme_id?:string, emblem?:string}  $options
     * @return array<string, mixed>
     */
    public function build(Sacrament $sacrament, string $language = 'en', string $locale = 'en_US', array $options = []): array
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
        $church = $this->resolveChurchContext($sacrament);
        $denominationType = (string) $church['denomination_type'];
        $engineType = DenominationMapper::engineSacramentType($code, $denominationType);
        $themeId = $options['theme_id'] ?? DenominationMapper::themeId($denominationType);
        $paper = strtoupper((string) ($options['paper'] ?? 'A4')) === 'LETTER' ? 'LETTER' : 'A4';
        $emblem = $options['emblem'] ?? CertificateThemeCatalog::CANONICAL_EMBLEM;

        $projection = [
            'schema_version' => 2,
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
                'recipient_name' => $sacrament->recipient_name,
                'minister_name' => $sacrament->minister_name,
                'minister_title' => $sacrament->minister_title,
                'father_name' => $sacrament->father_name,
                'mother_name' => $sacrament->mother_name,
                'godparent1_name' => $sacrament->godparent1_name,
                'godparent2_name' => $sacrament->godparent2_name,
                'marriage_bride_full_name' => $sacrament->marriage_bride_full_name,
                'marriage_bride_father_name' => $sacrament->marriage_bride_father_name,
                'marriage_bride_mother_name' => $sacrament->marriage_bride_mother_name,
                'marriage_bride_address' => $sacrament->marriage_bride_address,
                'marriage_bride_church_name' => $sacrament->marriage_bride_church_name,
                'marriage_bride_church_address' => $sacrament->marriage_bride_church_address,
                'marriage_groom_full_name' => $sacrament->marriage_groom_full_name,
                'marriage_groom_father_name' => $sacrament->marriage_groom_father_name,
                'marriage_groom_mother_name' => $sacrament->marriage_groom_mother_name,
                'marriage_groom_address' => $sacrament->marriage_groom_address,
                'marriage_groom_church_name' => $sacrament->marriage_groom_church_name,
                'marriage_groom_church_address' => $sacrament->marriage_groom_church_address,
                'marriage_bride_diocese_name' => $sacrament->marriage_bride_diocese_name ?? null,
                'marriage_groom_diocese_name' => $sacrament->marriage_groom_diocese_name ?? null,
                'recipient_birth_date' => optional($sacrament->recipient_birth_date)?->format('Y-m-d')
                    ?? $sacrament->recipient_birth_date,
                'recipient_birth_place' => $sacrament->recipient_birth_place,
            ],
            'participants' => $participants,
            'church' => $church,
            'leadership_context' => is_array($sacrament->leadership_context_json)
                ? $sacrament->leadership_context_json
                : null,
            'render' => [
                'template_code' => null,
                'template_version' => null,
                'theme_id' => $themeId,
                'paper' => $paper,
                'locale' => $locale,
                'emblem' => $emblem,
            ],
            'built_at' => now()->toIso8601String(),
        ];

        $projection['certificate_view'] = $this->views->assemble($projection);

        return $projection;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveChurchContext(Sacrament $sacrament): array
    {
        $context = $sacrament->leadership_context_json;
        if (is_array($context) && is_array($context['church'] ?? null)) {
            return $context['church'];
        }

        return $this->freezeChurch((int) $sacrament->tenant_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function freezeChurch(int $tenantId): array
    {
        $tenant = Tenant::query()
            ->with(['churchProfile.denomination', 'churchProfile.archdiocese', 'addresses'])
            ->find($tenantId);

        $profile = $tenant?->churchProfile;
        $denominationCode = $profile?->denomination?->code ?? 'GENERIC';
        $address = $tenant?->addresses
            ->firstWhere('address_type', 'official')
            ?? $tenant?->addresses->first();
        $addressText = null;
        if ($address) {
            $addressText = trim(implode(', ', array_filter([
                $address->line1 ?? null,
                $address->line2 ?? null,
                $address->city ?? null,
                $address->state_province ?? null,
                $address->country ?? null,
                $address->pin_zip_code ?? null,
            ])));
        }

        return [
            'name' => $tenant?->name ?? 'Parish Church',
            'diocese' => $profile?->archdiocese?->name,
            'address' => $addressText ?: null,
            'logo_url' => null,
            'logo_hash' => null,
            'seal_url' => null,
            'denomination_code' => $denominationCode,
            'denomination_type' => DenominationMapper::map(is_string($denominationCode) ? $denominationCode : null),
            'parish_code' => $tenant?->slug ?: (string) $tenantId,
        ];
    }
}
