<?php

namespace Modules\Sacraments\Services;

use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentEventSubtype;
use Modules\Sacraments\Support\SacramentOrdinationType;
use Modules\Sacraments\Support\SacramentPlaceClassification;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Validates Phase 10 sacrament-level typed fields (subtype, place, typed_attributes).
 */
class SacramentTypedAttributesValidator
{
    /** @var list<string> */
    private const GLOBAL_FORBIDDEN = [
        'diagnosis', 'medical_history', 'medication', 'disease', 'clinical_notes',
        'confession_text', 'sins', 'confession_notes', 'penance_details',
    ];

    public function __construct(
        protected SacramentDefinitionRegistry $definitions
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> sanitized fields to merge onto create/correct payload
     */
    public function validateAndNormalize(SacramentType $type, array $data): array
    {
        $definition = $this->definitions->forTypeCode($type->code) ?? [];
        $this->rejectForbiddenKeys($data, $definition);

        $out = [];
        $code = SacramentTypeCode::normalize($type->code) ?? strtoupper((string) $type->code);

        if ($code === SacramentTypeCode::EUCHARIST) {
            $subtype = SacramentEventSubtype::normalize($data['event_subtype'] ?? null)
                ?? ($definition['default_event_subtype'] ?? SacramentEventSubtype::FIRST_COMMUNION);
            $allowed = $definition['event_subtypes'] ?? SacramentEventSubtype::forEucharist();
            if (! in_array($subtype, $allowed, true)) {
                throw new SacramentBusinessRuleException(
                    'event_subtype_invalid',
                    'Invalid event subtype for Eucharist.'
                );
            }
            $out['event_subtype'] = $subtype;
        } elseif (array_key_exists('event_subtype', $data) && $data['event_subtype'] !== null) {
            $out['event_subtype'] = SacramentEventSubtype::normalize($data['event_subtype']);
        }

        if (array_key_exists('place_classification', $data)) {
            $place = $data['place_classification'];
            if ($place !== null && $place !== '') {
                if (! in_array($place, SacramentPlaceClassification::all(), true)) {
                    throw new SacramentBusinessRuleException(
                        'place_classification_invalid',
                        'Invalid place classification.'
                    );
                }
                $out['place_classification'] = $place;
            } else {
                $out['place_classification'] = null;
            }
        }

        if ($code === SacramentTypeCode::HOLY_ORDERS) {
            $out['typed_attributes'] = $this->normalizeHolyOrdersAttributes(
                is_array($data['typed_attributes'] ?? null) ? $data['typed_attributes'] : []
            );
        } elseif (array_key_exists('typed_attributes', $data)) {
            // Other types must not carry typed_attributes in Phase 10.
            if (! empty($data['typed_attributes'])) {
                throw new SacramentBusinessRuleException(
                    'typed_attributes_not_allowed',
                    'typed_attributes are not allowed for this sacrament type.'
                );
            }
            $out['typed_attributes'] = null;
        }

        if ($code === SacramentTypeCode::RECONCILIATION && array_key_exists('notes', $data)) {
            $notes = trim((string) ($data['notes'] ?? ''));
            if (strlen($notes) > 250) {
                throw new SacramentBusinessRuleException(
                    'notes_too_long',
                    'Notes for restricted records must be at most 250 characters.'
                );
            }
            $out['notes'] = $notes !== '' ? $notes : null;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $definition
     */
    private function rejectForbiddenKeys(array $data, array $definition): void
    {
        $forbidden = array_unique(array_merge(
            self::GLOBAL_FORBIDDEN,
            $definition['forbidden_fields'] ?? []
        ));

        foreach ($forbidden as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                throw new SacramentBusinessRuleException(
                    'forbidden_field',
                    'This field is not allowed on sacramental records.',
                    ['field' => $key]
                );
            }
        }

        $typed = $data['typed_attributes'] ?? null;
        if (is_array($typed)) {
            foreach ($forbidden as $key) {
                if (array_key_exists($key, $typed) && $typed[$key] !== null && $typed[$key] !== '') {
                    throw new SacramentBusinessRuleException(
                        'forbidden_field',
                        'This field is not allowed on sacramental records.',
                        ['field' => 'typed_attributes.'.$key]
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function normalizeHolyOrdersAttributes(array $attrs): array
    {
        $ordination = SacramentOrdinationType::normalize($attrs['ordination_type'] ?? null);
        if ($ordination === null) {
            throw new SacramentBusinessRuleException(
                'ordination_type_required',
                'Ordination type is required for Holy Orders.'
            );
        }

        $diocese = isset($attrs['diocese_name']) ? trim((string) $attrs['diocese_name']) : '';
        $placeDetail = isset($attrs['place_detail']) ? trim((string) $attrs['place_detail']) : '';

        $allowedKeys = ['ordination_type', 'diocese_name', 'place_detail'];
        foreach (array_keys($attrs) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new SacramentBusinessRuleException(
                    'typed_attributes_unknown_key',
                    'Unknown typed attribute key.',
                    ['field' => (string) $key]
                );
            }
        }

        return array_filter([
            'ordination_type' => $ordination,
            'diocese_name' => $diocese !== '' ? $diocese : null,
            'place_detail' => $placeDetail !== '' ? $placeDetail : null,
        ], static fn ($v) => $v !== null);
    }
}
