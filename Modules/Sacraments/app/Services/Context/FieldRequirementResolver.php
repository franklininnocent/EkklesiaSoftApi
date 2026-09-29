<?php

namespace Modules\Sacraments\Services\Context;

use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Support\SacramentFieldState;
use Modules\Sacraments\Support\SacramentRecordStatus;
use Modules\Sacraments\Support\SacramentTypeCode;

final class FieldRequirementResolver
{
    public function __construct(
        private readonly SacramentDefinitionRegistry $definitions,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     fields: array<string, array<string, mixed>>,
     *     missing: list<array<string, mixed>>
     * }
     */
    public function resolve(string $workflow, array $context): array
    {
        $definition = $this->definitions->forTypeCode($workflow);
        $fields = [];
        $missing = [];

        $canonical = $context['canonical_identity'] ?? [];
        $baptism = $context['sacraments']['baptism'] ?? [];
        $derived = $context['derived'] ?? [];
        $hasMember = ! empty($context['subject']['family_member_id']);

        foreach (['name', 'date_of_birth', 'gender', 'father_name', 'mother_name'] as $identityField) {
            $fieldData = $canonical[$identityField] ?? null;
            if ($hasMember && $fieldData !== null) {
                $fields[$identityField] = [
                    'required' => in_array($identityField, ['name', 'date_of_birth', 'gender'], true),
                    'visible' => true,
                    'input_hidden' => true,
                    'field_state' => SacramentFieldState::CANONICAL,
                    'auto_resolved' => $fieldData['value'] !== null,
                ];
                if ($fieldData['value'] === null && in_array($identityField, ['name', 'date_of_birth', 'gender'], true)) {
                    $missing[] = $this->missingEntry($identityField, 'required', 'Complete member profile');
                }
            }
        }

        $baptismStatus = $derived['baptismal_status'] ?? null;
        $baptismRecordStatus = $baptism['record_status'] ?? SacramentRecordStatus::NOT_FOUND;
        $registerRecordStatus = $baptism['register_record_status'] ?? $baptismRecordStatus;

        if (SacramentTypeCode::isMatrimony($workflow)) {
            if ($baptismRecordStatus === SacramentRecordStatus::FOUND && ($baptismStatus['value'] ?? null) !== null) {
                $fields['baptismal_status'] = [
                    'required' => false,
                    'visible' => true,
                    'input_hidden' => true,
                    'field_state' => SacramentFieldState::DERIVED,
                    'auto_resolved' => true,
                ];
            } elseif ($baptismRecordStatus === SacramentRecordStatus::FOUND) {
                $fields['baptismal_status'] = [
                    'required' => true,
                    'visible' => true,
                    'input_hidden' => false,
                    'field_state' => SacramentFieldState::EDITABLE,
                    'auto_resolved' => false,
                ];
                $missing[] = $this->missingEntry('baptismal_status', 'required', 'Provide baptismal status');
            } elseif ($baptismRecordStatus === SacramentRecordStatus::MULTIPLE_CANDIDATES) {
                $fields['baptismal_status'] = [
                    'required' => true,
                    'visible' => true,
                    'input_hidden' => false,
                    'field_state' => SacramentFieldState::MULTIPLE_CANDIDATES,
                    'auto_resolved' => false,
                ];
                $missing[] = $this->missingEntry('baptism_record_selection', 'required', 'Select baptism record');
            } elseif ($baptismRecordStatus === SacramentRecordStatus::NOT_FOUND) {
                $fields['baptismal_status'] = [
                    'required' => true,
                    'visible' => true,
                    'input_hidden' => false,
                    'field_state' => SacramentFieldState::MISSING,
                    'auto_resolved' => false,
                ];
                $missing[] = $this->missingEntry('baptismal_status', 'required', 'Provide baptismal status');
            }

            if ($registerRecordStatus === SacramentRecordStatus::NOT_FOUND
                && $baptismRecordStatus === SacramentRecordStatus::FOUND) {
                $missing[] = $this->missingEntry(
                    'baptism_register_record',
                    'advisory',
                    'Parish register record not found — verify baptism certificate before marriage.',
                );
            }

            $fields['ecclesial_affiliation_code'] = [
                'required' => true,
                'visible' => true,
                'input_hidden' => $baptismRecordStatus === SacramentRecordStatus::FOUND,
                'field_state' => $baptismRecordStatus === SacramentRecordStatus::FOUND
                    ? SacramentFieldState::READ_ONLY_VERIFIED
                    : SacramentFieldState::EDITABLE,
                'auto_resolved' => $baptismRecordStatus === SacramentRecordStatus::FOUND,
            ];
        }

        if ($definition !== null) {
            foreach ($definition['fields']['minimum'] ?? [] as $fieldName) {
                if (isset($fields[$fieldName]) || str_starts_with($fieldName, 'participants.')) {
                    continue;
                }
                if (! isset($fields[$fieldName])) {
                    $fields[$fieldName] = [
                        'required' => true,
                        'visible' => true,
                        'input_hidden' => false,
                        'field_state' => SacramentFieldState::EDITABLE,
                        'auto_resolved' => false,
                    ];
                }
            }
        }

        return ['fields' => $fields, 'missing' => $missing];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingEntry(string $field, string $classification, string $label): array
    {
        return [
            'field' => $field,
            'classification' => $classification,
            'label' => $label,
        ];
    }
}
